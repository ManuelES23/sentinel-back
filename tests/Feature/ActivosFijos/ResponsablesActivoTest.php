<?php
// sentinel-back/tests/Feature/ActivosFijos/ResponsablesActivoTest.php

namespace Tests\Feature\ActivosFijos;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Services\ActivosFijos\ResponsablesActivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

class ResponsablesActivoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesAssignmentFixtures;

    private ResponsablesActivo $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        // El usuario de prueba también tiene acceso a la empresa: un nombre fijo evita choques con las búsquedas.
        $this->actingUser->update(['name' => 'Operador Del Sistema']);
        $this->servicio = app(ResponsablesActivo::class);
    }

    private function accesoVigente(User $usuario, array $extra = []): void
    {
        UserEnterpriseAccess::create(array_merge([
            'user_id' => $usuario->id,
            'enterprise_id' => $this->enterprise->id,
            'is_active' => true,
            'granted_at' => now()->subMonth(),
        ], $extra));
    }

    public function test_busca_empleados_activos_de_la_empresa_con_puesto_y_departamento(): void
    {
        $depto = Department::create(['enterprise_id' => $this->enterprise->id, 'name' => 'Sistemas']);
        $puesto = Position::create(['enterprise_id' => $this->enterprise->id, 'name' => 'Analista']);
        $ana = $this->crearEmpleado($this->enterprise, [
            'first_name' => 'Ana', 'last_name' => 'Pérez', 'department_id' => $depto->id, 'position_id' => $puesto->id,
        ]);
        $this->crearEmpleado($this->enterprise, ['first_name' => 'Beto', 'last_name' => 'Baja', 'status' => 'terminated']);
        $this->crearEmpleado($this->corporativo, ['first_name' => 'Carla', 'last_name' => 'Ajena']);

        $encontrados = $this->servicio->buscar($this->enterprise, 'Pérez');

        $this->assertSame(
            [['tipo' => 'employee', 'id' => $ana->id, 'nombre' => 'Ana Pérez', 'puesto' => 'Analista', 'departamento' => 'Sistemas']],
            $encontrados->all(),
        );
        $this->assertSame([], $this->servicio->buscar($this->enterprise, 'Baja')->all(), 'un empleado dado de baja no se ofrece');
        $this->assertSame([], $this->servicio->buscar($this->enterprise, 'Ajena')->all(), 'un empleado de otra empresa no se ofrece');
    }

    public function test_busca_empleados_de_splendid_farms(): void
    {
        $luis = $this->crearEmpleadoSf($this->enterprise, ['first_name' => 'Luis', 'last_name' => 'Gómez']);
        $this->crearEmpleadoSf($this->enterprise, ['first_name' => 'Pedro', 'last_name' => 'Baja', 'status' => 'terminated']);

        $encontrados = $this->servicio->buscar($this->enterprise, 'Gómez');

        $this->assertSame(
            [['tipo' => 'sf_employee', 'id' => $luis->id, 'nombre' => 'Luis Gómez', 'puesto' => 'Jornalero', 'departamento' => 'Campo']],
            $encontrados->all(),
        );
        $this->assertSame([], $this->servicio->buscar($this->enterprise, 'Baja')->all());
    }

    public function test_ofrece_usuarios_con_acceso_vigente_y_omite_a_los_ligados_a_un_empleado(): void
    {
        $libre = User::factory()->create(['name' => 'Usuario Libre', 'role' => 'user']);
        $ligado = User::factory()->create(['name' => 'Usuario Ligado', 'role' => 'user']);
        $vencido = User::factory()->create(['name' => 'Usuario Vencido', 'role' => 'user']);
        User::factory()->create(['name' => 'Usuario Sin Acceso', 'role' => 'user']);
        $this->accesoVigente($libre);
        $this->accesoVigente($ligado);
        $this->accesoVigente($vencido, ['expires_at' => now()->subDay()]);
        $this->crearEmpleado($this->enterprise, ['first_name' => 'Lucía', 'last_name' => 'Ligada', 'user_id' => $ligado->id]);

        $nombres = $this->servicio->buscar($this->enterprise, 'Usuario')->pluck('nombre')->all();

        $this->assertSame(['Usuario Libre'], $nombres);
        $this->assertSame(
            [['tipo' => 'user', 'id' => $libre->id, 'nombre' => 'Usuario Libre', 'puesto' => null, 'departamento' => null]],
            $this->servicio->buscar($this->enterprise, 'Libre')->all(),
        );
        $this->assertSame(['Lucía Ligada'], $this->servicio->buscar($this->enterprise, 'Ligada')->pluck('nombre')->all());
    }

    public function test_cada_palabra_de_la_busqueda_debe_coincidir_y_los_comodines_se_escapan(): void
    {
        $this->crearEmpleado($this->enterprise, ['first_name' => 'María', 'last_name' => 'López', 'second_last_name' => 'Ruiz']);
        $this->crearEmpleado($this->enterprise, ['first_name' => 'María', 'last_name' => 'Soto']);

        $this->assertSame(['María López Ruiz'], $this->servicio->buscar($this->enterprise, 'mar lóp')->pluck('nombre')->all());
        $this->assertSame([], $this->servicio->buscar($this->enterprise, '%')->all(), 'el % se busca literal');
    }

    public function test_devuelve_como_maximo_veinte_resultados(): void
    {
        foreach (range(1, 25) as $n) {
            $this->crearEmpleado($this->enterprise, ['first_name' => 'Persona', 'last_name' => sprintf('Numero%02d', $n)]);
        }

        $this->assertCount(ResponsablesActivo::LIMITE, $this->servicio->buscar($this->enterprise, 'Persona'));
    }

    public function test_resolver_devuelve_los_datos_de_cada_tipo(): void
    {
        $empleado = $this->crearEmpleado($this->enterprise, ['first_name' => 'Ana', 'last_name' => 'Pérez']);
        $sf = $this->crearEmpleadoSf($this->enterprise);
        $usuario = User::factory()->create(['name' => 'Usuario Libre', 'role' => 'user']);
        $this->accesoVigente($usuario);

        $this->assertSame('Ana Pérez', $this->servicio->resolver('employee', $empleado->id, $this->enterprise)['nombre']);
        $this->assertSame('Jornalero', $this->servicio->resolver('sf_employee', $sf->id, $this->enterprise)['puesto']);
        $this->assertSame(
            ['tipo' => 'user', 'id' => $usuario->id, 'nombre' => 'Usuario Libre', 'puesto' => null, 'departamento' => null],
            $this->servicio->resolver('user', $usuario->id, $this->enterprise),
        );
    }

    public function test_resolver_rechaza_a_personas_de_otra_empresa_inactivas_o_sin_acceso(): void
    {
        $ajeno = $this->crearEmpleado($this->corporativo);
        $baja = $this->crearEmpleado($this->enterprise, ['status' => 'terminated']);
        $sinAcceso = User::factory()->create(['role' => 'user']);
        $vencido = User::factory()->create(['role' => 'user']);
        $this->accesoVigente($vencido, ['expires_at' => now()->subDay()]);

        foreach ([['employee', $ajeno->id], ['employee', $baja->id], ['user', $sinAcceso->id], ['user', $vencido->id], ['employee', 999999]] as [$tipo, $id]) {
            try {
                $this->servicio->resolver($tipo, $id, $this->enterprise);
                $this->fail("Se esperaba rechazo para {$tipo} {$id}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('assignee_id', $e->errors());
            }
        }
    }

    public function test_resolver_rechaza_un_tipo_desconocido(): void
    {
        $this->expectException(ValidationException::class);

        $this->servicio->resolver('proveedor', 1, $this->enterprise);
    }
}
