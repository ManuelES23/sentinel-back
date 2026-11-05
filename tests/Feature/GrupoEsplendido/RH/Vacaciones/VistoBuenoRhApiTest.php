<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * API de RH con el paso de visto bueno: RH solo da el visto bueno (no resuelve la solicitud completa)
 * y solo aprueba o rechaza cuando la solicitud llega al respaldo `is_rh_final`.
 */
class VistoBuenoRhApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/grupoesplendido/rh/vacaciones';

    private const MENSAJE_403 = 'RH solo puede aprobar o rechazar cuando la solicitud llega a su paso final; en el paso de visto bueno usa «Dar visto bueno».';

    private Employee $jefe;

    private Employee $solicitante;

    private Employee $rhEmpleado;

    private User $rh;

    private User $director;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->lunes = today()->next(Carbon::MONDAY)->addWeeks(2);

        $this->jefe = $this->empleadoConUsuario($this->empresa);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, ['reports_to' => $this->jefe->id]);
        $this->rhEmpleado = $this->empleadoConUsuario($this->corporativo);
        $this->rh = $this->rhEmpleado->user;
        $this->concederVacaciones($this->rh, 'aprobar');
        $this->director = $this->empleadoConUsuario($this->corporativo)->user;
    }

    private function cadenaCompleta(): void
    {
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $this->regla(2, ApprovalFlowStep::TYPE_RH_REVIEW);
        $this->regla(3, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->id]);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function nueva(int $semanas = 0): VacationRequest
    {
        $lunes = $this->lunes->copy()->addWeeks($semanas);

        return $this->servicio()->crear($this->solicitante, $lunes, $lunes->copy()->addDays(4), null, VacationRequest::SOURCE_PORTAL);
    }

    /** Solicitud con el jefe ya aprobado: el paso actual es el visto bueno de RH. */
    private function enVistoBueno(): VacationRequest
    {
        $this->cadenaCompleta();

        return $this->servicio()->aprobarPaso($this->nueva(), $this->jefe->user);
    }

    /** Solicitud en el respaldo de RH: jefe aprobó, RH dio el visto bueno y el director no puede decidir. */
    private function enRespaldo(int $semanas = 0): VacationRequest
    {
        $solicitud = $this->servicio()->aprobarPaso($this->nueva($semanas), $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, 'ok');
    }

    private function directorInactivo(): void
    {
        $this->cadenaCompleta();
        $this->director->employee()->update(['status' => Employee::STATUS_INACTIVE]);
    }

    private function paso(VacationRequest $solicitud, int $orden): VacationRequestApproval
    {
        return $solicitud->approvals()->where('step_order', $orden)->firstOrFail();
    }

    private function procesoVacaciones(array $procesos): ?array
    {
        return collect($procesos)->firstWhere('code', 'vacation_requests');
    }

    public function test_visto_bueno_por_api(): void
    {
        $solicitud = $this->enVistoBueno();
        Sanctum::actingAs($this->rh);

        $this->postJson(self::BASE."/{$solicitud->id}/visto-bueno", ['comentario' => 'El saldo cuadra'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Visto bueno enviado')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_step', 3)
            ->assertJsonPath('data.approvals.1.is_rh_review', true)
            ->assertJsonPath('data.approvals.1.decision', 'approved')
            ->assertJsonPath('data.approvals.1.comment', 'El saldo cuadra');

        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 3)->decision);
    }

    public function test_visto_bueno_sin_comentario_y_con_comentario_largo(): void
    {
        $solicitud = $this->enVistoBueno();
        Sanctum::actingAs($this->rh);

        $this->postJson(self::BASE."/{$solicitud->id}/visto-bueno", ['comentario' => str_repeat('a', 501)])
            ->assertStatus(422);
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 2)->decision);

        $this->postJson(self::BASE."/{$solicitud->id}/visto-bueno")->assertOk();
        $this->assertNull($this->paso($solicitud, 2)->comment);
    }

    public function test_visto_bueno_sin_permiso_aprobar_da_403(): void
    {
        $solicitud = $this->enVistoBueno();
        $sinPermiso = User::factory()->create();
        $this->concederVacaciones($sinPermiso, 'view', 'edit');
        Sanctum::actingAs($sinPermiso);

        $this->postJson(self::BASE."/{$solicitud->id}/visto-bueno")->assertForbidden();
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 2)->decision);
    }

    public function test_visto_bueno_fuera_de_su_paso_da_403(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->nueva();
        Sanctum::actingAs($this->rh);

        $this->postJson(self::BASE."/{$solicitud->id}/visto-bueno")->assertForbidden();
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 1)->decision);
    }

    public function test_aprobar_en_paso_del_jefe_da_403_y_no_cambia_nada(): void
    {
        $this->cadenaCompleta();
        $solicitud = $this->nueva();
        Sanctum::actingAs($this->rh);

        $this->postJson(self::BASE."/{$solicitud->id}/aprobar")->assertForbidden()->assertJsonPath('message', self::MENSAJE_403);
        $this->postJson(self::BASE."/{$solicitud->id}/rechazar", ['rejection_reason' => 'No procede'])
            ->assertForbidden()->assertJsonPath('message', self::MENSAJE_403);

        $solicitud->refresh();
        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(1, $solicitud->current_step);
        $this->assertSame(0, $solicitud->approvals()->where('decision', VacationRequestApproval::REJECTED)->count());
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 1)->decision);
    }

    public function test_aprobar_y_rechazar_en_el_paso_de_visto_bueno_dan_403(): void
    {
        $solicitud = $this->enVistoBueno();
        Sanctum::actingAs($this->rh);

        $this->postJson(self::BASE."/{$solicitud->id}/aprobar")->assertForbidden()->assertJsonPath('message', self::MENSAJE_403);
        $this->postJson(self::BASE."/{$solicitud->id}/rechazar", ['rejection_reason' => 'No procede'])
            ->assertForbidden()->assertJsonPath('message', self::MENSAJE_403);

        $solicitud->refresh();
        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(2, $solicitud->current_step);
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 2)->decision);
    }

    public function test_aprobar_y_rechazar_en_respaldo_si_funcionan(): void
    {
        $this->directorInactivo();
        $aprobada = $this->enRespaldo();
        $rechazada = $this->enRespaldo(3);
        $this->assertTrue((bool) $this->paso($aprobada, 4)->is_rh_final);
        Sanctum::actingAs($this->rh);

        $this->postJson(self::BASE."/{$aprobada->id}/aprobar")->assertOk()
            ->assertJsonPath('message', 'Solicitud aprobada exitosamente')
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame(VacationRequestApproval::APPROVED, $this->paso($aprobada, 4)->decision);

        $this->postJson(self::BASE."/{$rechazada->id}/rechazar")->assertStatus(422);
        $this->postJson(self::BASE."/{$rechazada->id}/rechazar", ['rejection_reason' => 'Temporada alta'])->assertOk()
            ->assertJsonPath('message', 'Solicitud rechazada')
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Temporada alta');
    }

    public function test_aprobar_en_respaldo_sin_permiso_aprobar_da_403(): void
    {
        $this->directorInactivo();
        $solicitud = $this->enRespaldo();
        $sinPermiso = User::factory()->create();
        $this->concederVacaciones($sinPermiso, 'view', 'edit');
        Sanctum::actingAs($sinPermiso);

        $this->postJson(self::BASE."/{$solicitud->id}/aprobar")->assertForbidden();
        $this->assertSame('pending', $solicitud->fresh()->status);
    }

    public function test_cancelar_pendiente_sigue_funcionando_para_rh(): void
    {
        $solicitud = $this->enVistoBueno();
        $this->concederVacaciones($this->rh, 'edit');
        Sanctum::actingAs($this->rh);

        $this->postJson(self::BASE."/{$solicitud->id}/cancelar")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_resolver_como_rh_ya_no_existe(): void
    {
        $this->assertFalse(method_exists(VacationRequestService::class, 'resolverComoRh'));
    }

    public function test_bandeja_no_lista_el_paso_de_visto_bueno(): void
    {
        $solicitud = $this->enVistoBueno();
        Sanctum::actingAs($this->rh);

        $resumen = $this->procesoVacaciones($this->getJson('/api/pending-approvals/summary')->assertOk()->json('data.processes'));
        $this->assertTrue($resumen === null || $resumen['pending_count'] === 0);

        $proceso = $this->procesoVacaciones($this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes'));
        $this->assertTrue($proceso === null || ! in_array($solicitud->id, array_column($proceso['items'] ?? [], 'id'), true));

        // Tampoco se aprueba ni se rechaza desde ahí.
        $this->postJson("/api/pending-approvals/vacation_request/{$solicitud->id}/approve")->assertForbidden();
        $this->postJson("/api/pending-approvals/vacation_request/{$solicitud->id}/reject", ['rejection_reason' => 'No procede'])->assertForbidden();
        $this->assertSame(VacationRequestApproval::WAITING, $this->paso($solicitud, 2)->decision);
    }

    public function test_bandeja_si_lista_el_respaldo_de_rh(): void
    {
        $this->directorInactivo();
        $solicitud = $this->enRespaldo();
        Sanctum::actingAs($this->rh);

        $proceso = $this->procesoVacaciones($this->getJson('/api/pending-approvals?process=vacation_requests')->assertOk()->json('data.processes'));

        $this->assertContains($solicitud->id, array_column($proceso['items'], 'id'));
    }

    public function test_detalle_de_la_bandeja_trae_comentario_del_visto_bueno(): void
    {
        $solicitud = $this->enVistoBueno();
        $this->servicio()->darVistoBueno($solicitud, $this->rh, 'El saldo cuadra');
        Sanctum::actingAs($this->director);

        $detalle = $this->getJson("/api/pending-approvals/vacation_request/{$solicitud->id}")->assertOk()->json('data');

        $this->assertSame('El saldo cuadra', $detalle['approvals'][1]['comment']);
        $this->assertTrue($detalle['approvals'][1]['is_rh_review']);
        $this->assertFalse($detalle['approvals'][0]['is_rh_review']);
    }
}
