<?php

namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\Employee;
use App\Models\VacationPortalSession;
use App\Services\RH\SesionPortalVacaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

/** Spec §3.3: regenerar el PIN, dar de baja o borrar al empleado revoca sus sesiones del portal. */
class EmployeeControllerPortalSessionRevocationTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private function sesionActiva(Employee $empleado): VacationPortalSession
    {
        return app(SesionPortalVacaciones::class)->iniciar($empleado, Request::create('/', 'POST'))['sesion'];
    }

    public function test_regenerar_pin_revoca_las_sesiones_del_portal(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $otro = $this->createEmployee($enterprise->id);
        $sesion = $this->sesionActiva($employee);
        $sesionOtro = $this->sesionActiva($otro);

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/regenerate-pin")->assertOk();

        $this->assertNotNull($sesion->fresh()->revoked_at);
        $this->assertNull($sesionOtro->fresh()->revoked_at);
    }

    public function test_dar_de_baja_revoca_las_sesiones_del_portal(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $sesion = $this->sesionActiva($employee);

        $this->postJson("/api/grupoesplendido/rh/empleados/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertNotNull($sesion->fresh()->revoked_at);
    }

    public function test_borrar_revoca_las_sesiones_del_portal(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        $sesion = $this->sesionActiva($employee);

        $this->deleteJson("/api/grupoesplendido/rh/empleados/{$employee->id}")->assertOk();

        $this->assertNotNull($sesion->fresh()->revoked_at);
    }
}
