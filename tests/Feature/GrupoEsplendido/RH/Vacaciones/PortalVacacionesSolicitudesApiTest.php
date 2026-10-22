<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\VacationRequestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class PortalVacacionesSolicitudesApiTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/portal-vacaciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function entrar(Employee $empleado): array
    {
        $token = $this->postJson(self::BASE.'/login', ['employee_number' => $empleado->employee_number, 'pin' => '123456'])
            ->assertOk()
            ->json('data.token');

        return ['X-Portal-Token' => $token];
    }

    private function lunes(): Carbon
    {
        return today()->next(Carbon::MONDAY)->addWeeks(2);
    }

    /** Lunes a domingo de la semana elegida. */
    private function semana(int $semanasDespues = 0): array
    {
        $inicio = $this->lunes()->addWeeks($semanasDespues);

        return ['start_date' => $inicio->toDateString(), 'end_date' => $inicio->copy()->addDays(6)->toDateString()];
    }

    public function test_calcular_descuenta_solo_dias_laborables(): void
    {
        $encabezados = $this->entrar($this->empleado($this->empresa));

        $this->postJson(self::BASE.'/calcular', $this->semana(), $encabezados)
            ->assertOk()
            ->assertJsonPath('data.dias_naturales', 7)
            ->assertJsonPath('data.dias_descontables', 6)
            ->assertJsonPath('data.excluidos.0.motivo', 'descanso')
            ->assertJsonCount(1, 'data.excluidos')
            ->assertJsonPath('data.disponibles', 42)
            ->assertJsonPath('data.disponibles_despues', 36)
            ->assertJsonPath('data.errores', []);
    }

    public function test_calcular_reporta_las_reglas_sin_crear_nada(): void
    {
        $nuevo = $this->empleado($this->empresa, ['hire_date' => now()->subMonths(6)->toDateString()]);
        $encabezados = $this->entrar($nuevo);

        // Tres semanas (18 días) pasan de los 12 que puede adelantar.
        $fechas = ['start_date' => $this->lunes()->toDateString(), 'end_date' => $this->lunes()->addDays(20)->toDateString()];
        $errores = $this->postJson(self::BASE.'/calcular', $fechas, $encabezados)->assertOk()->json('data.errores');
        $this->assertStringContainsString('Puede adelantar hasta 12 días', implode(' ', $errores));

        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_calcular_exige_fechas_en_formato_iso(): void
    {
        $encabezados = $this->entrar($this->empleado($this->empresa));

        $this->postJson(self::BASE.'/calcular', ['start_date' => '02/11/2026', 'end_date' => '06/11/2026'], $encabezados)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    public function test_crear_usa_el_empleado_de_la_sesion_con_origen_portal(): void
    {
        $empleado = $this->empleado($this->empresa);
        $ajeno = $this->empleado($this->empresa);
        $encabezados = $this->entrar($empleado);

        $this->postJson(self::BASE.'/solicitudes', $this->semana() + ['reason' => 'Viaje familiar', 'employee_id' => $ajeno->id], $encabezados)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'pending')
            ->assertJsonPath('data.dias', 6)
            ->assertJsonPath('data.motivo', 'Viaje familiar')
            ->assertJsonPath('data.paso_actual', 1)
            ->assertJsonPath('data.total_pasos', 1)
            ->assertJsonPath('data.revisor_actual', CadenaAprobacionVacaciones::DESCRIPCION_RH);

        $solicitud = VacationRequest::sole();
        $this->assertSame($empleado->id, $solicitud->employee_id);
        $this->assertSame(VacationRequest::SOURCE_PORTAL, $solicitud->source);
        $this->assertNull($solicitud->created_by);
        $this->assertSame(0, VacationRequest::where('employee_id', $ajeno->id)->count());
    }

    public function test_crear_rechaza_lo_que_rompe_las_reglas(): void
    {
        $empleado = $this->empleado($this->empresa);
        $encabezados = $this->entrar($empleado);
        $this->postJson(self::BASE.'/solicitudes', $this->semana(), $encabezados)->assertCreated();

        $this->postJson(self::BASE.'/solicitudes', $this->semana(), $encabezados)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe una solicitud pendiente o aprobada que se empalma con esas fechas.');

        $ayer = today()->subDay()->toDateString();
        $this->postJson(self::BASE.'/solicitudes', ['start_date' => $ayer, 'end_date' => $ayer], $encabezados)
            ->assertStatus(422)
            ->assertJsonPath('message', 'La fecha de inicio no puede ser anterior a hoy.');
    }

    public function test_lista_solo_las_propias_de_la_mas_reciente_a_la_mas_vieja(): void
    {
        $empleado = $this->empleado($this->empresa);
        $vieja = $this->solicitud($empleado, 'approved', 2);
        $this->travel(1)->days();
        $reciente = $this->solicitud($empleado, 'pending', 3);
        $this->solicitud($this->empleado($this->empresa), 'pending', 1);
        $encabezados = $this->entrar($empleado);

        $respuesta = $this->getJson(self::BASE.'/solicitudes', $encabezados)->assertOk();

        $this->assertSame([$reciente->id, $vieja->id], array_column($respuesta->json('data'), 'id'));
        $this->assertSame('VAC-'.str_pad((string) $reciente->id, 6, '0', STR_PAD_LEFT), $respuesta->json('data.0.folio'));
    }

    public function test_detalle_muestra_la_cadena_con_solo_el_nombre_de_quien_decidio(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $puestoRh = $this->puesto($this->corporativo, 'Coordinador de RH', 3, true, 'enterprise');
        $this->regla(2, 'position', ['position_id' => $puestoRh->id]);
        $this->empleadoConUsuario($this->corporativo, ['position_id' => $puestoRh->id]);
        $empleado = $this->empleado($this->empresa, ['reports_to' => $jefe->id]);
        $encabezados = $this->entrar($empleado);

        $id = $this->postJson(self::BASE.'/solicitudes', $this->semana(), $encabezados)
            ->assertCreated()
            ->assertJsonPath('data.paso_actual', 1)
            ->assertJsonPath('data.total_pasos', 2)
            ->assertJsonPath('data.revisor_actual', 'Jefe directo')
            ->json('data.id');

        $usuarioJefe = User::findOrFail($jefe->user_id);
        app(VacationRequestService::class)->aprobarPaso(VacationRequest::findOrFail($id), $usuarioJefe);

        $respuesta = $this->getJson(self::BASE."/solicitudes/{$id}", $encabezados)
            ->assertOk()
            ->assertJsonPath('data.paso_actual', 2)
            ->assertJsonPath('data.pasos.0.decision', 'approved')
            ->assertJsonPath('data.pasos.0.decidido_por', $usuarioJefe->name)
            ->assertJsonPath('data.pasos.1.decision', 'waiting')
            ->assertJsonCount(2, 'data.pasos');

        $this->assertSame(['orden', 'descripcion', 'decision', 'decidido_por', 'decidido_el', 'comentario', 'es_rh'], array_keys($respuesta->json('data.pasos.0')));
        $this->assertStringNotContainsString($usuarioJefe->email, $respuesta->getContent());
    }

    /** Regla de jefe directo (con usuario) + paso final de RH oculto; devuelve [encabezados, id, usuarioJefe]. */
    private function solicitudConJefeYRh(): array
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $empleado = $this->empleado($this->empresa, ['reports_to' => $jefe->id]);
        $encabezados = $this->entrar($empleado);
        $id = $this->postJson(self::BASE.'/solicitudes', $this->semana(), $encabezados)->assertCreated()->json('data.id');

        return [$encabezados, $id, User::findOrFail($jefe->user_id)];
    }

    public function test_cancelada_no_expone_comentarios_internos_ni_el_paso_final_de_rh(): void
    {
        [$encabezados, $id] = $this->solicitudConJefeYRh();

        $this->postJson(self::BASE."/solicitudes/{$id}/cancelar", [], $encabezados)->assertOk();

        $respuesta = $this->getJson(self::BASE."/solicitudes/{$id}", $encabezados)
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelled')
            ->assertJsonPath('data.paso_actual', null)
            ->assertJsonPath('data.total_pasos', 1)
            ->assertJsonCount(1, 'data.pasos');

        $this->assertTrue(VacationRequest::findOrFail($id)->approvals->contains(fn ($paso) => $paso->comment !== null), 'el escenario debe tener comentarios internos');
        foreach ($respuesta->json('data.pasos') as $paso) {
            $this->assertNull($paso['comentario']);
            $this->assertFalse($paso['es_rh']);
        }
    }

    public function test_rechazada_muestra_el_motivo_en_el_paso_rechazado(): void
    {
        [$encabezados, $id, $usuarioJefe] = $this->solicitudConJefeYRh();
        app(VacationRequestService::class)->rechazar(VacationRequest::findOrFail($id), $usuarioJefe, 'Motivo X');

        $respuesta = $this->getJson(self::BASE."/solicitudes/{$id}", $encabezados)
            ->assertOk()
            ->assertJsonPath('data.estado', 'rejected')
            ->assertJsonPath('data.motivo_rechazo', 'Motivo X')
            ->assertJsonPath('data.paso_actual', null)
            ->assertJsonPath('data.pasos.0.decision', 'rejected')
            ->assertJsonPath('data.pasos.0.comentario', 'Motivo X');

        foreach (array_slice($respuesta->json('data.pasos'), 1) as $paso) {
            $this->assertNull($paso['comentario']);
        }
    }

    public function test_tras_aprobar_el_jefe_ningun_paso_expone_comentarios_internos(): void
    {
        [$encabezados, $id, $usuarioJefe] = $this->solicitudConJefeYRh();
        app(VacationRequestService::class)->aprobarPaso(VacationRequest::findOrFail($id), $usuarioJefe);

        $respuesta = $this->getJson(self::BASE."/solicitudes/{$id}", $encabezados)->assertOk();

        $this->assertCount($respuesta->json('data.total_pasos'), $respuesta->json('data.pasos'));
        foreach ($respuesta->json('data.pasos') as $paso) {
            $this->assertNull($paso['comentario']);
        }
    }

    public function test_un_id_desmesurado_responde_404_y_no_500(): void
    {
        $encabezados = $this->entrar($this->empleado($this->empresa));

        $this->getJson(self::BASE.'/solicitudes/99999999999999999999', $encabezados)->assertNotFound();
        $this->getJson(self::BASE.'/solicitudes/99999999999999999999/formato', $encabezados)->assertNotFound();
        $this->postJson(self::BASE.'/solicitudes/99999999999999999999/cancelar', [], $encabezados)->assertNotFound();
    }

    public function test_cancelar_solo_las_propias_que_siguen_en_revision(): void
    {
        $empleado = $this->empleado($this->empresa);
        $encabezados = $this->entrar($empleado);
        $id = $this->postJson(self::BASE.'/solicitudes', $this->semana(), $encabezados)->json('data.id');
        $aprobada = $this->solicitud($empleado, 'approved', 2);

        $this->postJson(self::BASE."/solicitudes/{$id}/cancelar", [], $encabezados)
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelled');
        $this->assertSame('cancelled', VacationRequest::findOrFail($id)->status);

        $this->postJson(self::BASE."/solicitudes/{$id}/cancelar", [], $encabezados)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo puedes cancelar solicitudes que siguen en revisión.');
        $this->postJson(self::BASE."/solicitudes/{$aprobada->id}/cancelar", [], $encabezados)->assertStatus(422);
        $this->assertSame('approved', $aprobada->fresh()->status);
    }

    public function test_no_ve_ni_cancela_ni_descarga_solicitudes_ajenas(): void
    {
        $ajena = $this->solicitud($this->empleado($this->empresa), 'approved', 2);
        $pendienteAjena = $this->solicitud($this->empleado($this->empresa), 'pending', 2);
        $encabezados = $this->entrar($this->empleado($this->empresa));

        $this->getJson(self::BASE."/solicitudes/{$ajena->id}", $encabezados)->assertNotFound()->assertJsonPath('message', 'Solicitud no encontrada.');
        $this->getJson(self::BASE."/solicitudes/{$ajena->id}/formato", $encabezados)->assertNotFound();
        $this->postJson(self::BASE."/solicitudes/{$pendienteAjena->id}/cancelar", [], $encabezados)->assertNotFound();
        $this->getJson(self::BASE.'/solicitudes/999999', $encabezados)->assertNotFound()->assertJsonPath('message', 'Solicitud no encontrada.');

        $this->assertSame('pending', $pendienteAjena->fresh()->status);
    }

    public function test_el_formato_solo_existe_para_aprobadas(): void
    {
        $empleado = $this->empleado($this->empresa);
        $aprobador = User::factory()->create(['name' => 'Laura Aprobadora']);
        $pendiente = $this->solicitud($empleado, 'pending', 2);
        $aprobada = $this->solicitud($empleado, 'approved', 3, ['approved_by' => $aprobador->id, 'approved_at' => now(), 'reason' => 'Descanso']);
        $encabezados = $this->entrar($empleado);

        $this->getJson(self::BASE."/solicitudes/{$pendiente->id}/formato", $encabezados)
            ->assertStatus(422)
            ->assertJsonPath('message', 'El formato solo está disponible para solicitudes aprobadas.');

        $respuesta = $this->getJson(self::BASE."/solicitudes/{$aprobada->id}/formato", $encabezados)
            ->assertOk()
            ->assertJsonPath('data.folio', 'VAC-'.str_pad((string) $aprobada->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.empresa.nombre', 'Splendid Farms')
            ->assertJsonPath('data.empleado.numero', $empleado->employee_number)
            ->assertJsonPath('data.solicitud.fecha_inicio', $aprobada->start_date->format('Y-m-d'))
            ->assertJsonPath('data.solicitud.dias', 3)
            ->assertJsonPath('data.solicitud.motivo', 'Descanso')
            ->assertJsonPath('data.aprobado_por', 'Laura Aprobadora');

        $this->assertStringNotContainsString($aprobador->email, $respuesta->getContent());
    }
}
