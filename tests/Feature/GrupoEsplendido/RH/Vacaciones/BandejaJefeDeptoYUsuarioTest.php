<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** La bandeja de pendientes con los pasos «Jefe del departamento» y «Usuario específico». */
class BandejaJefeDeptoYUsuarioTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Employee $jefe;

    private User $director;

    private VacationRequest $solicitud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();

        $depto = $this->departamento($this->empresa, 'VTA');
        $this->jefe = $this->empleadoConUsuario($this->empresa, ['department_id' => $depto->id]);
        $depto->update(['manager_id' => $this->jefe->id]);
        $this->director = User::factory()->create();

        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);

        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $this->solicitud = app(VacationRequestService::class)->crear(
            $this->empleado($this->empresa, ['department_id' => $depto->id]), $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL
        );
    }

    private function proceso(array $procesos): ?array
    {
        return collect($procesos)->firstWhere('code', 'vacation_requests');
    }

    public function test_el_jefe_del_departamento_la_ve_aunque_no_tenga_subordinados_ni_puesto_aprobador(): void
    {
        Sanctum::actingAs($this->jefe->user);

        $resumen = $this->proceso($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));
        $this->assertSame(1, $resumen['pending_count']);

        $lista = $this->proceso($this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes'));
        $this->assertSame($this->solicitud->id, $lista['items'][0]['id']);
        $this->assertSame('Jefe del departamento', $lista['items'][0]['step_description']);
    }

    public function test_el_director_sin_empleado_no_ve_nada_antes_de_su_turno(): void
    {
        Sanctum::actingAs($this->director);

        $antes = $this->proceso($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));

        $this->assertSame(0, $antes['pending_count']);
    }

    public function test_el_director_sin_empleado_ve_su_paso_cuando_le_toca(): void
    {
        app(VacationRequestService::class)->aprobarPaso($this->solicitud, $this->jefe->user);
        Sanctum::actingAs($this->director);

        $resumen = $this->proceso($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));
        $this->assertSame(1, $resumen['pending_count']);

        $lista = $this->proceso($this->getJson('/api/pending-approvals')->assertOk()->json('data.processes'));
        $this->assertSame($this->solicitud->id, $lista['items'][0]['id']);
        $this->assertSame(2, $lista['items'][0]['step']);
        $this->assertSame('Usuario: '.$this->director->name, $lista['items'][0]['step_description']);
    }

    public function test_el_director_sin_empleado_aprueba_desde_la_bandeja(): void
    {
        app(VacationRequestService::class)->aprobarPaso($this->solicitud, $this->jefe->user);
        Sanctum::actingAs($this->director);

        $this->postJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}/approve")->assertOk();

        $this->assertSame('approved', $this->solicitud->fresh()->status);
    }

    public function test_el_director_sin_empleado_rechaza_desde_la_bandeja(): void
    {
        app(VacationRequestService::class)->aprobarPaso($this->solicitud, $this->jefe->user);
        Sanctum::actingAs($this->director);

        $this->postJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}/reject", ['rejection_reason' => 'Fechas de cierre'])->assertOk();

        $this->assertSame('rejected', $this->solicitud->fresh()->status);
    }

    public function test_un_usuario_sin_empleado_que_no_es_el_director_no_ve_ni_decide_nada(): void
    {
        app(VacationRequestService::class)->aprobarPaso($this->solicitud, $this->jefe->user);
        Sanctum::actingAs(User::factory()->create());

        $this->assertNull($this->proceso($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes')));
        $this->postJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}/approve")->assertStatus(403);
        $this->assertSame('pending', $this->solicitud->fresh()->status);
    }

    public function test_otros_tipos_siguen_exigiendo_empleado_ligado(): void
    {
        Sanctum::actingAs($this->director);

        $this->postJson('/api/pending-approvals/incident/1/approve')->assertStatus(403);
    }

    public function test_el_director_con_empleado_y_puesto_sin_autoridad_tambien_la_ve(): void
    {
        $empleadoDirector = $this->empleado($this->corporativo, ['user_id' => $this->director->id]);
        app(VacationRequestService::class)->aprobarPaso($this->solicitud, $this->jefe->user);
        Sanctum::actingAs($this->director);

        $resumen = $this->proceso($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));

        $this->assertNotNull($empleadoDirector);
        $this->assertSame(1, $resumen['pending_count']);
    }
}
