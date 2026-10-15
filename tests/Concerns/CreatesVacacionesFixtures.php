<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Position;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use App\Models\VacationRequest;
use Illuminate\Support\Str;

/**
 * Fixtures del motor de vacaciones: corporativo (slug grupoesplendido, donde
 * vive RH) + una empresa operativa, el proceso de aprobación vacation_requests
 * y helpers para empleados, reglas de la cadena y permisos de RH.
 */
trait CreatesVacacionesFixtures
{
    protected Enterprise $corporativo;

    protected Enterprise $empresa;

    protected ApprovalProcess $procesoVacaciones;

    protected function setUpVacaciones(): void
    {
        $this->corporativo = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'Corporativo', 'is_active' => true]);
        $this->empresa = Enterprise::create(['name' => 'Splendid Farms', 'slug' => 'splendidfarms', 'description' => 'Agrícola', 'is_active' => true]);
        $this->procesoVacaciones = ApprovalProcess::firstOrCreate(
            ['code' => ApprovalProcess::VACATION_REQUESTS],
            [
                'name' => 'Solicitudes de Vacaciones',
                'description' => 'Aprobación de solicitudes de vacaciones',
                'module' => 'grupoesplendido/rh',
                'entity_type' => VacationRequest::class,
                'requires_approval' => true,
                'is_active' => true,
            ],
        );
    }

    protected function departamento(Enterprise $empresa, string $codigo = 'OPE', ?int $padreId = null): Department
    {
        return Department::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'code' => $codigo],
            ['name' => 'Depto '.$codigo, 'parent_id' => $padreId, 'is_active' => true],
        );
    }

    protected function puesto(Enterprise $empresa, string $nombre, int $nivel = 7, bool $aprueba = false, string $alcance = 'own_department'): Position
    {
        return Position::create([
            'enterprise_id' => $empresa->id, 'code' => 'P-'.Str::random(6), 'name' => $nombre,
            'hierarchy_level' => $nivel, 'can_approve' => $aprueba, 'approval_scope' => $alcance, 'is_active' => true,
        ]);
    }

    /** Empleado activo con 3 años de antigüedad (42 días LFT acumulados) por defecto. */
    protected function empleado(Enterprise $empresa, array $atributos = []): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create(array_merge([
            'enterprise_id' => $empresa->id,
            'employee_number' => 'VA'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'first_name' => 'Empleado',
            'last_name' => 'Vacaciones'.$n,
            'hire_date' => now()->subYears(3)->subDay()->toDateString(),
            'qr_code' => 'QR-VAC-'.$n.'-'.Str::random(6),
            'pin' => '123456',
            'status' => Employee::STATUS_ACTIVE,
        ], $atributos));
    }

    protected function empleadoConUsuario(Enterprise $empresa, array $atributos = []): Employee
    {
        $usuario = User::factory()->create();

        return $this->empleado($empresa, array_merge($atributos, ['user_id' => $usuario->id]));
    }

    protected function regla(int $paso, string $tipo, array $atributos = []): ApprovalFlowStep
    {
        return ApprovalFlowStep::create(array_merge([
            'approval_process_id' => $this->procesoVacaciones->id,
            'enterprise_id' => null,
            'step_order' => $paso,
            'approver_type' => $tipo,
            'approval_scope' => 'enterprise',
            'can_approve' => true,
            'can_reject' => true,
            'is_active' => true,
        ], $atributos));
    }

    /** Solicitud directa en BD (sin servicio ni cadena), en fechas futuras que no se empalman entre sí. */
    protected function solicitud(Employee $empleado, string $estado, int $dias, array $atributos = []): VacationRequest
    {
        static $desfase = 0;
        $desfase += 15;
        $inicio = now()->addDays(60 + $desfase)->startOfDay();

        return VacationRequest::create(array_merge([
            'employee_id' => $empleado->id,
            'enterprise_id' => $empleado->enterprise_id,
            'start_date' => $inicio->toDateString(),
            'end_date' => $inicio->copy()->addDays(max(0, $dias - 1))->toDateString(),
            'days_requested' => $dias,
            'status' => $estado,
            'vacation_year' => $inicio->year,
        ], $atributos));
    }

    /** rh → gestion → vacaciones en el corporativo, con los tipos view/create/edit/aprobar. */
    protected function submoduloVacaciones(): Submodule
    {
        $aplicacion = Application::firstOrCreate(
            ['enterprise_id' => $this->corporativo->id, 'slug' => 'rh'],
            ['name' => 'Recursos Humanos', 'description' => 'RH', 'path' => '/grupoesplendido/rh', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(['application_id' => $aplicacion->id, 'slug' => 'gestion'], ['name' => 'Gestión', 'order' => 4, 'is_active' => true]);
        $submodulo = Submodule::firstOrCreate(['module_id' => $modulo->id, 'slug' => 'vacaciones'], ['name' => 'Vacaciones', 'order' => 1, 'is_active' => true]);

        foreach (['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'aprobar' => 'Aprobar como RH'] as $slug => $nombre) {
            SubmodulePermissionType::firstOrCreate(['submodule_id' => $submodulo->id, 'slug' => $slug], ['name' => $nombre, 'order' => 1, 'is_active' => true]);
        }

        return $submodulo;
    }

    protected function concederVacaciones(User $usuario, string ...$permisos): void
    {
        $submodulo = $this->submoduloVacaciones();
        foreach ($permisos as $slug) {
            $tipo = SubmodulePermissionType::where('submodule_id', $submodulo->id)->where('slug', $slug)->firstOrFail();
            UserSubmodulePermission::updateOrCreate(
                ['user_id' => $usuario->id, 'submodule_id' => $submodulo->id, 'permission_type_id' => $tipo->id],
                ['is_granted' => true],
            );
        }
    }

    /** rh → gestion → festivos en el corporativo, con los tipos view/create/edit/delete. */
    protected function submoduloFestivos(): Submodule
    {
        $aplicacion = Application::firstOrCreate(
            ['enterprise_id' => $this->corporativo->id, 'slug' => 'rh'],
            ['name' => 'Recursos Humanos', 'description' => 'RH', 'path' => '/grupoesplendido/rh', 'is_active' => true],
        );
        $modulo = Module::firstOrCreate(['application_id' => $aplicacion->id, 'slug' => 'gestion'], ['name' => 'Gestión', 'order' => 4, 'is_active' => true]);
        $submodulo = Submodule::firstOrCreate(['module_id' => $modulo->id, 'slug' => 'festivos'], ['name' => 'Festivos', 'order' => 3, 'is_active' => true]);

        foreach (['view' => 'Ver', 'create' => 'Crear', 'edit' => 'Editar', 'delete' => 'Eliminar'] as $slug => $nombre) {
            SubmodulePermissionType::firstOrCreate(['submodule_id' => $submodulo->id, 'slug' => $slug], ['name' => $nombre, 'order' => 1, 'is_active' => true]);
        }

        return $submodulo;
    }

    protected function concederFestivos(User $usuario, string ...$permisos): void
    {
        $submodulo = $this->submoduloFestivos();
        foreach ($permisos as $slug) {
            $tipo = SubmodulePermissionType::where('submodule_id', $submodulo->id)->where('slug', $slug)->firstOrFail();
            UserSubmodulePermission::updateOrCreate(
                ['user_id' => $usuario->id, 'submodule_id' => $submodulo->id, 'permission_type_id' => $tipo->id],
                ['is_granted' => true],
            );
        }
    }
}
