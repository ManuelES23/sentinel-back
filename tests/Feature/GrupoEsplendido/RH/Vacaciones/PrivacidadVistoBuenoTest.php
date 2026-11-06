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
 * El comentario del visto bueno de RH es para quien revisa (spec §5.5): nunca le llega al solicitante, y el
 * detalle de la bandeja (`GET pending-approvals/vacation_request/{id}`) solo lo ve quien tiene que ver con la
 * solicitud (quien decide el paso actual, quien ya decidió un paso o RH).
 */
class PrivacidadVistoBuenoTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const COMENTARIO = 'Ojo: el saldo apenas alcanza, revisar con su jefe';

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

    /** Jefe aprobó y RH dio el visto bueno con comentario: ahora le toca al director. */
    private function conVistoBueno(): VacationRequest
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $solicitud = $this->servicio()->crear($this->solicitante, $lunes, $lunes->copy()->addDays(4), 'Descanso familiar', VacationRequest::SOURCE_PORTAL);
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $this->jefe->user);

        return $this->servicio()->darVistoBueno($solicitud, $this->rh, self::COMENTARIO);
    }

    private function detalleBandeja(VacationRequest $solicitud)
    {
        return $this->getJson("/api/pending-approvals/vacation_request/{$solicitud->id}");
    }

    // ===== Hallazgo 1: historial de Mi Perfil =====

    public function test_el_historial_del_perfil_no_trae_el_comentario_del_visto_bueno_ni_las_reglas(): void
    {
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($this->solicitante->user);

        $respuesta = $this->getJson('/api/profile/vacation-history')->assertOk();

        $this->assertStringNotContainsString(self::COMENTARIO, $respuesta->getContent());
        $item = collect($respuesta->json('data.requests.data'))->firstWhere('id', $solicitud->id);
        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('approvals', $item);
        // La revisión presentada (la misma del portal) sí sale, con el visto bueno marcado.
        $this->assertSame(3, $item['revision']['paso_actual']);
        $this->assertTrue($item['revision']['pasos'][1]['es_visto_bueno']);
        $this->assertNull($item['revision']['pasos'][1]['comentario']);
    }

    // ===== Hallazgo 2: quién ve el detalle de la bandeja =====

    public function test_quien_decide_el_paso_actual_ve_el_detalle(): void
    {
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($this->director);

        $this->detalleBandeja($solicitud)->assertOk()->assertJsonPath('data.id', $solicitud->id);
    }

    public function test_el_jefe_directo_del_paso_actual_ve_el_detalle(): void
    {
        $lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
        $solicitud = $this->servicio()->crear($this->solicitante, $lunes, $lunes->copy()->addDays(4), 'Descanso familiar', VacationRequest::SOURCE_PORTAL);
        Sanctum::actingAs($this->jefe->user);

        $this->detalleBandeja($solicitud)->assertOk()->assertJsonPath('data.id', $solicitud->id);
    }

    public function test_el_solicitante_no_lo_ve_aunque_su_usuario_tenga_otro_empleado_mas_antiguo(): void
    {
        // employees.user_id no es único: el usuario ya tenía un empleado anterior, así que su employee() no es el solicitante.
        $usuario = User::factory()->create();
        $this->empleado($this->empresa, ['user_id' => $usuario->id]);
        $this->solicitante->update(['user_id' => $usuario->id]);
        // Además es de RH: solo el user_id del solicitante lo delata.
        $this->concederVacaciones($usuario, 'aprobar');
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($usuario);

        $respuesta = $this->detalleBandeja($solicitud)->assertNotFound();
        $this->assertStringNotContainsString(self::COMENTARIO, $respuesta->getContent());
    }

    public function test_quien_ya_decidio_un_paso_ve_el_detalle(): void
    {
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($this->jefe->user);

        $this->detalleBandeja($solicitud)->assertOk()->assertJsonPath('data.id', $solicitud->id);
    }

    public function test_quien_decidio_ve_el_detalle_aunque_la_solicitud_ya_cerro(): void
    {
        $solicitud = $this->servicio()->aprobarPaso($this->conVistoBueno(), $this->director);
        $this->assertSame('approved', $solicitud->status);
        Sanctum::actingAs($this->director);

        $this->detalleBandeja($solicitud)->assertOk();
    }

    public function test_rh_ve_el_detalle(): void
    {
        $solicitud = $this->conVistoBueno();
        $otroRh = $this->empleadoConUsuario($this->corporativo)->user;
        $this->concederVacaciones($otroRh, 'aprobar');
        Sanctum::actingAs($otroRh);

        $this->detalleBandeja($solicitud)->assertOk();
    }

    public function test_el_solicitante_no_ve_el_detalle(): void
    {
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($this->solicitante->user);

        $respuesta = $this->detalleBandeja($solicitud)->assertNotFound();
        $this->assertStringNotContainsString(self::COMENTARIO, $respuesta->getContent());
    }

    public function test_el_solicitante_no_lo_ve_aunque_sea_de_rh(): void
    {
        $solicitud = $this->conVistoBueno();
        $this->concederVacaciones($this->solicitante->user, 'aprobar');
        Sanctum::actingAs($this->solicitante->user);

        $this->detalleBandeja($solicitud)->assertNotFound();
    }

    public function test_el_solicitante_no_lo_ve_por_un_usuario_compartido(): void
    {
        $solicitud = $this->conVistoBueno();
        // Datos viejos: otro empleado ligado al mismo usuario del solicitante.
        $this->empleado($this->empresa, ['user_id' => $this->solicitante->user_id]);
        Sanctum::actingAs($this->solicitante->user);

        $this->detalleBandeja($solicitud)->assertNotFound();
    }

    public function test_un_usuario_ajeno_no_ve_el_detalle(): void
    {
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($this->empleadoConUsuario($this->empresa)->user);

        $respuesta = $this->detalleBandeja($solicitud)->assertNotFound();
        $this->assertStringNotContainsString(self::COMENTARIO, $respuesta->getContent());
    }

    public function test_un_usuario_ajeno_sin_empleado_no_ve_el_detalle(): void
    {
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs(User::factory()->create());

        $this->detalleBandeja($solicitud)->assertNotFound();
    }

    public function test_usuario_especifico_sin_empleado_ve_el_detalle_de_su_paso(): void
    {
        $directorSinEmpleado = User::factory()->create();
        ApprovalFlowStep::where('approver_type', ApprovalFlowStep::TYPE_USER)->update(['user_id' => $directorSinEmpleado->id]);
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($directorSinEmpleado);

        $this->detalleBandeja($solicitud)->assertOk()->assertJsonPath('data.id', $solicitud->id);
    }

    // ===== Hallazgo 3: el respaldo de RH no sale «en espera» detrás del director =====

    public function test_el_detalle_de_la_bandeja_oculta_el_respaldo_que_no_espera(): void
    {
        $solicitud = $this->conVistoBueno();
        Sanctum::actingAs($this->director);

        $approvals = $this->detalleBandeja($solicitud)->assertOk()->json('data.approvals');

        $this->assertSame([1, 2, 3], array_column($approvals, 'step_order'));
        $this->assertSame(self::COMENTARIO, $approvals[1]['comment']);
    }

    public function test_el_detalle_de_la_bandeja_muestra_el_respaldo_cuando_es_el_que_espera(): void
    {
        $this->director->employee()->update(['status' => Employee::STATUS_INACTIVE]);
        $solicitud = $this->conVistoBueno();
        $this->assertSame(4, $solicitud->current_step);
        Sanctum::actingAs($this->rh);

        $approvals = $this->detalleBandeja($solicitud)->assertOk()->json('data.approvals');

        $this->assertSame(4, end($approvals)['step_order']);
    }

    // ===== Hallazgo 4: el empleado ve «RH dio el visto bueno», sin el comentario =====

    public function test_el_portal_del_empleado_marca_el_visto_bueno_sin_comentario(): void
    {
        $solicitud = $this->conVistoBueno();
        $token = $this->postJson('/api/portal-vacaciones/login', ['employee_number' => $this->solicitante->employee_number, 'pin' => '123456'])
            ->assertOk()->json('data.token');

        $respuesta = $this->getJson("/api/portal-vacaciones/solicitudes/{$solicitud->id}", ['X-Portal-Token' => $token])->assertOk();

        $pasos = $respuesta->json('data.pasos');
        $this->assertSame([false, true, false], array_column($pasos, 'es_visto_bueno'));
        $this->assertNull($pasos[1]['comentario']);
        $this->assertStringNotContainsString(self::COMENTARIO, $respuesta->getContent());
    }

    public function test_el_perfil_marca_el_visto_bueno_sin_comentario(): void
    {
        $this->conVistoBueno();
        Sanctum::actingAs($this->solicitante->user);

        $respuesta = $this->getJson('/api/profile')->assertOk();

        $pasos = $respuesta->json('data.vacation.requests.0.revision.pasos');
        $this->assertSame([false, true, false], array_column($pasos, 'es_visto_bueno'));
        $this->assertStringNotContainsString(self::COMENTARIO, $respuesta->getContent());
    }
}
