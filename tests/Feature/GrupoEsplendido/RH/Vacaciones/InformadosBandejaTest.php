<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\InformadosVacaciones;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * La persona informada del paso del director (p. ej. su asistente) ve en Mi Perfil → Pendientes, en solo consulta,
 * las solicitudes que esperan a ese paso; abre su detalle (también las que el paso ya aprobó) y nunca decide.
 */
class InformadosBandejaTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Department $depto;

    private Employee $jefe;

    private User $rh;

    private User $director;

    private User $asistente;

    private ApprovalFlowStep $reglaDirector;

    private VacationRequest $solicitud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();

        $this->depto = $this->departamento($this->empresa, 'VTA');
        $this->jefe = $this->empleadoConUsuario($this->empresa, ['department_id' => $this->depto->id]);
        $this->depto->update(['manager_id' => $this->jefe->id]);
        $this->rh = $this->empleadoConUsuario($this->corporativo)->user;
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->director = User::factory()->create();
        // La asistente es empleada del corporativo, sin puesto aprobador ni subordinados.
        $this->asistente = $this->empleadoConUsuario($this->corporativo)->user;

        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->reglaDirector = $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
        $this->reglaDirector->observers()->attach($this->asistente->id);

        $this->solicitud = $this->nueva($this->empleado($this->empresa, ['department_id' => $this->depto->id]));
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(Employee $empleado): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        return $this->servicio()->crear($empleado, $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
    }

    /** El jefe aprobó y RH dio el visto bueno: le toca al director. */
    private function enManosDelDirector(VacationRequest $solicitud): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, 'Todo en orden');
    }

    private function vacaciones(TestResponse $respuesta): ?array
    {
        return collect($respuesta->assertOk()->json('data.processes'))->firstWhere('code', 'vacation_requests');
    }

    private function bandeja(): ?array
    {
        return $this->vacaciones($this->getJson('/api/pending-approvals?process=vacation_requests'));
    }

    private function resumen(): ?array
    {
        return $this->vacaciones($this->getJson('/api/pending-approvals/summary'));
    }

    public function test_la_informada_ve_en_solo_consulta_lo_que_espera_al_director(): void
    {
        Sanctum::actingAs($this->asistente);
        $this->assertSame(0, $this->resumen()['pending_count'] ?? 0);

        $this->enManosDelDirector($this->solicitud);

        $lista = $this->bandeja();
        $this->assertCount(1, $lista['items']);
        $this->assertSame($this->solicitud->id, $lista['items'][0]['id']);
        $this->assertTrue($lista['items'][0]['solo_consulta']);

        $resumen = $this->getJson('/api/pending-approvals/summary')->assertOk();
        $this->assertSame(1, $this->vacaciones($resumen)['pending_count']);
        $this->assertSame(1, $resumen->json('data.total_pending'));

        Sanctum::actingAs($this->director);
        $delDirector = $this->bandeja();
        $this->assertSame($this->solicitud->id, $delDirector['items'][0]['id']);
        $this->assertFalse($delDirector['items'][0]['solo_consulta']);
    }

    public function test_la_informada_sin_empleado_ve_la_pestana(): void
    {
        $sinEmpleado = User::factory()->create();
        $this->reglaDirector->observers()->attach($sinEmpleado->id);
        $this->enManosDelDirector($this->solicitud);
        Sanctum::actingAs($sinEmpleado);

        $resumen = $this->getJson('/api/pending-approvals/summary')->assertOk()->assertJsonPath('data.can_approve', true);
        $this->assertSame(1, $this->vacaciones($resumen)['pending_count']);

        $lista = $this->bandeja();
        $this->assertSame($this->solicitud->id, $lista['items'][0]['id']);
        $this->assertTrue($lista['items'][0]['solo_consulta']);
    }

    public function test_detalle_de_solo_consulta(): void
    {
        $this->enManosDelDirector($this->solicitud);
        Sanctum::actingAs($this->asistente);

        $respuesta = $this->getJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}")
            ->assertOk()
            ->assertJsonPath('data.can_decide', false);

        $this->assertNotNull($respuesta->json('data.review'));
    }

    public function test_detalle_de_una_aprobada_por_el_paso(): void
    {
        $solicitud = $this->enManosDelDirector($this->solicitud);
        $this->servicio()->aprobarPaso($solicitud, $this->director);
        $this->assertSame('approved', $solicitud->fresh()->status);

        $otra = $this->nueva($this->empleado($this->empresa, ['department_id' => $this->depto->id]));
        Sanctum::actingAs($this->asistente);

        $this->getJson("/api/pending-approvals/vacation_request/{$solicitud->id}")->assertOk();
        $this->getJson("/api/pending-approvals/vacation_request/{$otra->id}")->assertNotFound();
    }

    public function test_la_informada_no_puede_aprobar_ni_rechazar(): void
    {
        $this->enManosDelDirector($this->solicitud);
        Sanctum::actingAs($this->asistente);

        $this->postJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}/approve")->assertForbidden();
        $this->postJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}/reject", ['rejection_reason' => 'No'])->assertForbidden();

        $this->assertSame('pending', $this->solicitud->fresh()->status);
    }

    public function test_regla_inactiva_no_informa(): void
    {
        $this->enManosDelDirector($this->solicitud);
        $this->reglaDirector->update(['is_active' => false]);
        Sanctum::actingAs($this->asistente);

        $this->assertSame(0, $this->resumen()['pending_count'] ?? 0);
        $this->assertSame([], $this->bandeja()['items'] ?? []);
        $this->assertFalse(app(InformadosVacaciones::class)->esInformado($this->asistente));
    }

    public function test_el_detalle_da_404_a_la_informada_si_la_regla_esta_inactiva(): void
    {
        $this->enManosDelDirector($this->solicitud);
        Sanctum::actingAs($this->asistente);
        $this->getJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}")->assertOk();

        $this->reglaDirector->update(['is_active' => false]);

        $this->getJson("/api/pending-approvals/vacation_request/{$this->solicitud->id}")->assertNotFound();
    }

    public function test_no_ve_su_propia_solicitud(): void
    {
        $empleadaAsistente = $this->asistente->employee()->first();
        $empleadaAsistente->update(['enterprise_id' => $this->empresa->id, 'department_id' => $this->depto->id]);
        $propia = $this->enManosDelDirector($this->nueva($empleadaAsistente->fresh()));
        $this->assertSame(3, $propia->fresh()->current_step);

        Sanctum::actingAs($this->asistente);

        $this->assertSame(0, $this->resumen()['pending_count'] ?? 0);
        $this->assertSame([], $this->bandeja()['items'] ?? []);
        $this->getJson("/api/pending-approvals/vacation_request/{$propia->id}")->assertNotFound();
    }

    public function test_aprobador_e_informado_sale_una_vez_con_botones(): void
    {
        $alterno = User::factory()->create();
        $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $alterno->id]);
        $this->reglaDirector->observers()->attach($alterno->id);

        $nueva = $this->enManosDelDirector($this->nueva($this->empleado($this->empresa, ['department_id' => $this->depto->id])));
        Sanctum::actingAs($alterno);

        $items = collect($this->bandeja()['items'])->where('id', $nueva->id)->values();
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['solo_consulta']);
    }

    public function test_los_pasos_de_rh_no_informan(): void
    {
        $informados = app(InformadosVacaciones::class);
        $cadena = app(CadenaAprobacionVacaciones::class);

        $enRh = $this->servicio()->aprobarPaso($this->solicitud, $this->jefe->user);
        $pasoRh = $cadena->pasoActual($enRh);
        $this->assertTrue($pasoRh->is_rh_review);
        $this->assertFalse($informados->informaDelPaso($this->asistente, $pasoRh));
        $this->assertTrue($informados->informadosDelPaso($enRh, $pasoRh)->isEmpty());

        $conDirector = $this->servicio()->darVistoBueno($enRh, $this->rh);
        $pasoDirector = $cadena->pasoActual($conDirector);
        $this->assertTrue($informados->informaDelPaso($this->asistente, $pasoDirector));
        $this->assertSame([$this->asistente->id], $informados->informadosDelPaso($conDirector, $pasoDirector)->pluck('id')->all());
    }

    public function test_aprobadas_que_informa(): void
    {
        $solicitud = $this->enManosDelDirector($this->solicitud);
        $this->servicio()->aprobarPaso($solicitud, $this->director);
        $informados = app(InformadosVacaciones::class);

        $this->assertSame([$solicitud->id], $informados->idsAprobadasQueInforma($this->asistente->id)->all());
        $this->assertSame([$this->asistente->id], $informados->informadosDeAprobadas($solicitud->fresh())->all());
        $this->assertSame([], $informados->idsAprobadasQueInforma($this->director->id)->all());
    }
}
