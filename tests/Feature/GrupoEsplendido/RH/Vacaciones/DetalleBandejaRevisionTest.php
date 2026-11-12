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

/**
 * Quien autoriza con su usuario de Sentinel (jefe o director) ve en Mi Perfil el mismo detalle B del portal:
 * `data.review` (saldo, desglose, historial, coincidencias, cadena con el comentario del visto bueno) y
 * `data.can_decide` (si le toca decidir ahora, para mostrar o no los botones).
 */
class DetalleBandejaRevisionTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const COMENTARIO = 'Ojo: el saldo apenas alcanza';

    private Employee $jefe;

    private Employee $solicitante;

    private User $rh;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, ['reports_to' => $this->jefe->id]);
        $this->rh = $this->empleadoConUsuario($this->corporativo)->user;
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->director = $this->empleadoConUsuario($this->corporativo)->user;

        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        return $this->servicio()->crear($this->solicitante, $lunes, $lunes->copy()->addDays(4), 'Descanso familiar', VacationRequest::SOURCE_PORTAL);
    }

    /** Jefe aprobó y RH dio el visto bueno con comentario: le toca al director. */
    private function enManosDelDirector(): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, self::COMENTARIO);
    }

    private function detalle(VacationRequest $solicitud)
    {
        return $this->getJson("/api/pending-approvals/vacation_request/{$solicitud->id}");
    }

    public function test_el_director_ve_el_detalle_completo_y_puede_decidir(): void
    {
        $solicitud = $this->enManosDelDirector();
        Sanctum::actingAs($this->director);

        $respuesta = $this->detalle($solicitud)->assertOk()
            ->assertJsonPath('data.can_decide', true)
            ->assertJsonPath('data.review.id', $solicitud->id)
            ->assertJsonPath('data.review.motivo', 'Descanso familiar')
            ->assertJsonPath('data.review.dias', 5);

        $review = $respuesta->json('data.review');
        foreach (['saldo', 'desglose', 'historial', 'coinciden', 'siguiente', 'llego_el', 'pasos'] as $clave) {
            $this->assertArrayHasKey($clave, $review, "falta {$clave} en review");
        }
        $this->assertArrayHasKey('despues', $review['saldo']);
        $this->assertArrayHasKey('adelanto', $review['saldo']);

        // La cadena trae el visto bueno con su comentario (es para quien autoriza) y lo marca.
        $vistoBueno = collect($review['pasos'])->firstWhere('es_visto_bueno', true);
        $this->assertNotNull($vistoBueno);
        $this->assertSame(self::COMENTARIO, $vistoBueno['comentario']);
    }

    public function test_el_jefe_ve_el_detalle_completo_en_su_paso(): void
    {
        $solicitud = $this->nueva();
        Sanctum::actingAs($this->jefe->user);

        $this->detalle($solicitud)->assertOk()
            ->assertJsonPath('data.can_decide', true)
            ->assertJsonPath('data.review.id', $solicitud->id);
    }

    public function test_rh_ve_el_detalle_pero_no_decide_en_el_paso_del_director(): void
    {
        $solicitud = $this->enManosDelDirector();
        Sanctum::actingAs($this->rh);

        $this->detalle($solicitud)->assertOk()
            ->assertJsonPath('data.can_decide', false)
            ->assertJsonPath('data.review.id', $solicitud->id);
    }

    public function test_quien_ya_decidio_lo_ve_sin_poder_decidir(): void
    {
        $solicitud = $this->enManosDelDirector();
        Sanctum::actingAs($this->jefe->user);

        $this->detalle($solicitud)->assertOk()
            ->assertJsonPath('data.can_decide', false)
            ->assertJsonPath('data.review.id', $solicitud->id);
    }

    public function test_una_solicitud_cerrada_ya_no_se_puede_decidir(): void
    {
        $solicitud = $this->servicio()->aprobarPaso($this->enManosDelDirector(), $this->director);
        $this->assertSame('approved', $solicitud->status);
        Sanctum::actingAs($this->director);

        $this->detalle($solicitud)->assertOk()->assertJsonPath('data.can_decide', false);
    }

    public function test_el_solicitante_sigue_sin_verlo(): void
    {
        $solicitud = $this->enManosDelDirector();
        Sanctum::actingAs($this->solicitante->user);

        $respuesta = $this->detalle($solicitud)->assertNotFound();
        $this->assertStringNotContainsString(self::COMENTARIO, $respuesta->getContent());
    }

    public function test_el_detalle_conserva_la_lista_de_campos_de_siempre(): void
    {
        $solicitud = $this->nueva();
        Sanctum::actingAs($this->jefe->user);

        $this->detalle($solicitud)->assertOk()
            ->assertJsonPath('data.type', 'vacation_request')
            ->assertJsonPath('data.employee.number', $this->solicitante->employee_number)
            ->assertJsonStructure(['data' => ['details', 'approvals', 'review', 'can_decide']]);
    }
}
