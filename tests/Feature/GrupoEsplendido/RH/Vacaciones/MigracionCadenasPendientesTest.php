<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class MigracionCadenasPendientesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function migrar(): void
    {
        (require database_path('migrations/2026_10_15_100400_build_chains_for_pending_vacation_requests.php'))->up();
    }

    public function test_las_pendientes_reciben_cadena_y_las_demas_no(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $empleado = $this->empleado($this->empresa, ['reports_to' => $jefe->id]);
        $pendiente = $this->solicitud($empleado, 'pending', 3);
        $aprobada = $this->solicitud($empleado, 'approved', 3);

        $this->migrar();
        $this->migrar();

        $this->assertSame(2, $pendiente->approvals()->count());
        $this->assertSame(1, $pendiente->fresh()->current_step);
        $this->assertSame(0, $aprobada->approvals()->count());
    }
}
