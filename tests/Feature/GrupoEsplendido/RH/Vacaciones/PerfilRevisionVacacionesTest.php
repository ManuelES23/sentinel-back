<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class PerfilRevisionVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    public function test_el_perfil_trae_la_revision_de_cada_solicitud(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $empleado = $this->empleadoConUsuario($this->empresa, ['reports_to' => $jefe->id]);
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        app(VacationRequestService::class)->crear($empleado, $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PERFIL);
        Sanctum::actingAs($empleado->user);

        $revision = $this->getJson('/api/profile')->assertOk()->json('data.vacation.requests.0.revision');

        $this->assertSame(1, $revision['paso_actual']);
        $this->assertSame(1, $revision['total_pasos']);
        $this->assertSame('Jefe directo', $revision['revisor_actual']);
        $this->assertSame('Jefe directo', $revision['pasos'][0]['descripcion']);
        $this->assertSame('waiting', $revision['pasos'][0]['decision']);
        $this->assertNull($revision['pasos'][0]['comentario']);
    }

    public function test_una_solicitud_sin_cadena_trae_la_revision_vacia_sin_romper(): void
    {
        $empleado = $this->empleadoConUsuario($this->empresa);
        $this->solicitud($empleado, 'approved', 3, ['vacation_year' => now()->year]);
        Sanctum::actingAs($empleado->user);

        $revision = $this->getJson('/api/profile')->assertOk()->json('data.vacation.requests.0.revision');

        $this->assertNull($revision['paso_actual']);
        $this->assertSame(0, $revision['total_pasos']);
        $this->assertSame([], $revision['pasos']);
    }

    public function test_rh_recibe_el_departamento_del_empleado(): void
    {
        $this->submoduloVacaciones();
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'view');
        $departamento = $this->departamento($this->empresa, 'OPE');
        $empleado = $this->empleado($this->empresa, ['department_id' => $departamento->id]);
        $this->solicitud($empleado, 'pending', 3);
        Sanctum::actingAs($rh);

        $this->getJson('/api/grupoesplendido/rh/vacaciones')->assertOk()
            ->assertJsonPath('data.0.employee.department.name', 'Depto OPE');
    }
}
