<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Models\VacationHistoryEntry;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Models\WorkSchedule;
use App\Services\RH\VacationRequestService;
use App\Support\RH\PresentadorPortalVacaciones;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/**
 * Lo que ve quien revisa (jefe o director) en el portal: saldo, desglose, historial por periodo,
 * coincidencias del departamento, cuándo llegó y qué sigue (spec §5.5). Reglas: 1 jefe directo,
 * 2 director (usuario específico), 3 visto bueno de RH.
 */
class DetalleParaRevisarTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/portal-vacaciones';

    private Department $depto;

    private Employee $jefe;

    private Employee $solicitante;

    private Employee $director;

    private User $rh;

    private VacationRequest $revisada;

    private WorkSchedule $horario;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        Mail::fake();
        $this->setUpVacaciones();

        $this->horario = WorkSchedule::create(['name' => 'Lun-Sab', 'is_active' => true] + $this->dias(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']));
        $this->horario->enterprises()->attach($this->empresa->id, ['is_default' => true]);
        Holiday::create(['date' => '2026-11-16', 'name' => 'Día de la Revolución', 'enterprise_id' => null, 'is_official' => true]);

        $this->depto = $this->departamento($this->empresa, 'VTA');
        $this->jefe = $this->empleadoConUsuario($this->empresa, ['department_id' => $this->depto->id]);
        $this->solicitante = $this->empleadoConUsuario($this->empresa, [
            'department_id' => $this->depto->id, 'reports_to' => $this->jefe->id, 'hire_date' => '2021-03-15',
        ]);
        $this->director = $this->empleadoConUsuario($this->corporativo);
        $this->rh = User::factory()->create();
        $this->concederVacaciones($this->rh, 'aprobar');

        $this->depto->update(['manager_id' => $this->jefe->id]);
        $this->regla(1, ApprovalFlowStep::TYPE_DEPARTMENT_MANAGER);
        $this->regla(2, ApprovalFlowStep::TYPE_USER, ['user_id' => $this->director->user_id]);
        $this->regla(3, ApprovalFlowStep::TYPE_RH_REVIEW);

        $this->historialDelSolicitante();
        $this->revisada = $this->revisadaPorElDirector();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dias(array $dias): array
    {
        $datos = [];
        foreach ($dias as $dia) {
            $datos["{$dia}_start"] = '08:00';
            $datos["{$dia}_end"] = '17:00';
        }

        return $datos;
    }

    private function directa(Employee $empleado, string $estado, string $inicio, string $fin, int $dias, array $extra = []): VacationRequest
    {
        return VacationRequest::create(array_merge([
            'employee_id' => $empleado->id,
            'enterprise_id' => $empleado->enterprise_id,
            'start_date' => $inicio,
            'end_date' => $fin,
            'days_requested' => $dias,
            'status' => $estado,
            'vacation_year' => (int) substr($inicio, 0, 4),
        ], $extra));
    }

    private function historialDelSolicitante(): void
    {
        $this->directa($this->solicitante, 'approved', '2026-03-30', '2026-04-02', 4);
        $this->directa($this->solicitante, 'approved', '2026-08-10', '2026-08-13', 4);
        $rechazada = $this->directa($this->solicitante, 'rejected', '2026-09-21', '2026-09-25', 5, ['rejection_reason' => 'Cierre de zafra']);
        $rechazada->approvals()->create([
            'step_order' => 1, 'description' => 'Jefe directo', 'rules' => [], 'is_rh_final' => false,
            'decision' => VacationRequestApproval::REJECTED, 'decided_by' => $this->jefe->user_id,
            'comment' => 'Cierre de zafra', 'decided_at' => now(),
        ]);
        $this->directa($this->solicitante, 'cancelled', '2026-05-04', '2026-05-08', 5);

        VacationHistoryEntry::create([
            'employee_id' => $this->solicitante->id, 'vacation_year' => 2025,
            'start_date' => '2025-07-14', 'end_date' => '2025-07-24', 'days' => 9, 'notes' => 'interno',
        ]);
        VacationHistoryEntry::create([
            'employee_id' => $this->solicitante->id, 'vacation_year' => 2024,
            'start_date' => null, 'end_date' => null, 'days' => 5, 'notes' => 'interno',
        ]);
    }

    /** Solicitud del 16 al 20 de noviembre (el 16 es festivo): el jefe aprueba y queda en el paso del director. */
    private function revisadaPorElDirector(): VacationRequest
    {
        $servicio = app(VacationRequestService::class);
        $solicitud = $servicio->crear($this->solicitante, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-20'), 'Descanso familiar', VacationRequest::SOURCE_PORTAL);
        Carbon::setTestNow('2026-11-06 09:30:00');
        $solicitud = $servicio->aprobarPaso($solicitud, $this->jefe);
        $this->assertSame(2, $solicitud->current_step);

        return $solicitud;
    }

    private function entrar(Employee $empleado): array
    {
        $token = $this->postJson(self::BASE.'/login', ['employee_number' => $empleado->employee_number, 'pin' => '123456'])
            ->assertOk()->json('data.token');

        return ['X-Portal-Token' => $token];
    }

    private function detalle(?VacationRequest $solicitud = null): array
    {
        return $this->getJson(self::BASE.'/por-revisar/'.($solicitud ?? $this->revisada)->id, $this->entrar($this->director))
            ->assertOk()->json('data');
    }

    public function test_saldo_antes_y_despues(): void
    {
        $saldo = $this->detalle()['saldo'];

        // 12+14+16+18+20 = 80 acumulados − 8 aprobados − 14 del historial − 4 de esta solicitud.
        $this->assertSame(54, $saldo['despues']);
        $this->assertSame($saldo['despues'] + 4, $saldo['disponibles']);
        $this->assertSame(['inicio' => '2026-03-15', 'fin' => '2027-03-14'], $saldo['periodo']);
        $this->assertSame(20, $saldo['le_corresponden_periodo']);
        $this->assertSame(8, $saldo['tomados_periodo']);
        $this->assertSame(0, $saldo['otras_en_revision']);
        $this->assertSame(0, $saldo['adelanto']);
        $this->assertArrayHasKey('proximo_aniversario', $saldo);
        $this->assertSame('2027-03-15', $saldo['proximo_aniversario']['fecha']);
        $this->assertSame(22, $saldo['proximo_aniversario']['dias']);

        $extra = $this->detalle()['solicitante_extra'];
        $this->assertSame('2021-03-15', $extra['fecha_ingreso']);
        $this->assertSame('5 años, 7 meses', $extra['antiguedad']);
    }

    public function test_desglose(): void
    {
        $desglose = $this->detalle()['desglose'];

        $this->assertSame(5, $desglose['dias_naturales']);
        $this->assertSame(4, $desglose['dias_descontables']);
        $this->assertSame([['fecha' => '2026-11-16', 'motivo' => 'festivo: Día de la Revolución']], $desglose['excluidos']);
        $this->assertSame('2026-11-21', $desglose['regresa']);
    }

    public function test_regresa_respeta_el_horario(): void
    {
        $this->horario->update(['saturday_start' => null, 'saturday_end' => null]);

        // Sin sábado ni domingo laborables, vuelve el lunes.
        $this->assertSame('2026-11-23', $this->detalle()['desglose']['regresa']);
    }

    public function test_historial_agrupado_sin_notas(): void
    {
        $respuesta = $this->getJson(self::BASE.'/por-revisar/'.$this->revisada->id, $this->entrar($this->director))->assertOk();
        $historial = $respuesta->json('data.historial');

        $this->assertLessThanOrEqual(3, count($historial));
        $this->assertCount(3, $historial);

        $actual = $historial[0];
        $this->assertSame('2026-03-15', $actual['inicio']);
        $this->assertSame('2027-03-14', $actual['fin']);
        $this->assertSame(20, $actual['le_corresponden']);
        $this->assertSame(8, $actual['tomados']);
        $this->assertCount(3, $actual['items']);
        $rechazada = collect($actual['items'])->firstWhere('estado', 'rejected');
        $this->assertSame('solicitud', $rechazada['tipo']);
        $this->assertSame('2026-09-21', $rechazada['fecha_inicio']);
        $this->assertSame('Cierre de zafra', $rechazada['rechazo']['motivo']);
        $this->assertNotEmpty($rechazada['rechazo']['por']);
        $this->assertNotContains('cancelled', collect($actual['items'])->pluck('estado')->all());
        $this->assertNotContains($this->revisada->id, collect($actual['items'])->pluck('id')->filter()->all());
        $this->assertNotContains('2026-11-16', collect($actual['items'])->pluck('fecha_inicio')->all());

        $this->assertSame('2025-03-15', $historial[1]['inicio']);
        $this->assertSame(9, $historial[1]['tomados']);
        $this->assertSame('historial_rh', $historial[1]['items'][0]['tipo']);
        $this->assertSame('2025-07-14', $historial[1]['items'][0]['fecha_inicio']);
        $this->assertSame(9, $historial[1]['items'][0]['dias']);

        $this->assertSame('2024-03-15', $historial[2]['inicio']);
        $this->assertSame('2025-03-14', $historial[2]['fin']);
        $this->assertSame(5, $historial[2]['tomados']);
        $this->assertSame(16, $historial[2]['le_corresponden']);
        $this->assertNull($historial[2]['items'][0]['fecha_inicio']);

        $this->assertStringNotContainsString('interno', $respuesta->getContent());
    }

    public function test_coinciden_solo_del_departamento(): void
    {
        $otroDepto = $this->departamento($this->empresa, 'OPE');
        $colega = $this->empleado($this->empresa, ['department_id' => $this->depto->id, 'first_name' => 'Colega']);
        $this->directa($colega, 'approved', '2026-11-18', '2026-11-19', 2);
        $ajeno = $this->empleado($this->empresa, ['department_id' => $otroDepto->id, 'first_name' => 'Ajeno']);
        $this->directa($ajeno, 'approved', '2026-11-18', '2026-11-19', 2);
        $cancelado = $this->empleado($this->empresa, ['department_id' => $this->depto->id, 'first_name' => 'Cancelado']);
        $this->directa($cancelado, 'cancelled', '2026-11-18', '2026-11-19', 2);
        $fuera = $this->empleado($this->empresa, ['department_id' => $this->depto->id, 'first_name' => 'Fuera']);
        $this->directa($fuera, 'approved', '2026-12-01', '2026-12-03', 3);

        $coinciden = $this->detalle()['coinciden'];

        $this->assertCount(1, $coinciden);
        $this->assertSame('Colega '.$colega->last_name, $coinciden[0]['nombre']);
        $this->assertSame('2026-11-18', $coinciden[0]['fecha_inicio']);
        $this->assertSame('2026-11-19', $coinciden[0]['fecha_fin']);
        $this->assertSame('approved', $coinciden[0]['estado']);
    }

    public function test_llego_el_y_siguiente(): void
    {
        $detalle = $this->detalle();
        $pasos = $this->revisada->approvals()->get();

        $this->assertSame($pasos->firstWhere('step_order', 1)->decided_at->toIso8601String(), $detalle['llego_el']);
        $this->assertSame($pasos->firstWhere('step_order', 3)->description, $detalle['siguiente']);
    }

    public function test_llego_el_del_primer_paso_es_la_creacion(): void
    {
        $nueva = app(VacationRequestService::class)->crear(
            $this->solicitante, Carbon::parse('2026-12-07'), Carbon::parse('2026-12-09'), 'Trámites personales', VacationRequest::SOURCE_PORTAL,
        );

        $this->assertSame(1, $nueva->current_step);
        $respuesta = $this->getJson(self::BASE.'/por-revisar/'.$nueva->id, $this->entrar($this->jefe))->assertOk();
        $this->assertSame($nueva->created_at->toIso8601String(), $respuesta->json('data.llego_el'));
    }

    public function test_el_comentario_del_visto_bueno_sale_a_quien_revisa_y_no_al_solicitante(): void
    {
        $servicio = app(VacationRequestService::class);
        $otra = $servicio->crear($this->solicitante, Carbon::parse('2026-12-14'), Carbon::parse('2026-12-17'), 'Fin de año', VacationRequest::SOURCE_PORTAL);
        $otra = $servicio->aprobarPaso($otra, $this->jefe);
        $otra = $servicio->aprobarPaso($otra, $this->director);
        $this->assertSame(3, $otra->current_step);
        $otra = $servicio->darVistoBueno($otra, $this->rh, 'Saldo correcto, sin pendientes');

        $paso = $otra->approvals()->where('is_rh_review', true)->first();
        $this->assertSame('Saldo correcto, sin pendientes', $paso->comment);

        // El solicitante, desde su portal, ve el paso sin el comentario.
        $propio = $this->getJson(self::BASE.'/solicitudes/'.$otra->id, $this->entrar($this->solicitante))->assertOk();
        $pasoPropio = collect($propio->json('data.pasos'))->firstWhere('orden', 3);
        $this->assertNull($pasoPropio['comentario']);
        $this->assertStringNotContainsString('Saldo correcto', $propio->getContent());

        // Quien revisa lo ve (paraRevisar), marcado como visto bueno.
        $pasosRevisor = $this->detalle($this->revisada)['pasos'];
        $this->assertTrue($pasosRevisor[2]['es_visto_bueno']);
        $this->assertFalse($pasosRevisor[0]['es_visto_bueno']);
        $this->assertNull($pasosRevisor[0]['comentario']);

        $this->revisada->approvals()->where('is_rh_review', true)->update(['comment' => 'Verificado por RH']);
        $this->assertSame('Verificado por RH', $this->detalle($this->revisada->fresh())['pasos'][2]['comentario']);
    }

    public function test_la_lista_trae_solo_saldo_despues_y_adelanto(): void
    {
        $lista = $this->getJson(self::BASE.'/por-revisar', $this->entrar($this->director))->assertOk()->json('data');

        $this->assertCount(1, $lista);
        $this->assertSame(['despues' => 54, 'adelanto' => 0], $lista[0]['saldo']);
        foreach (['historial', 'desglose', 'coinciden', 'llego_el', 'siguiente', 'solicitante_extra'] as $clave) {
            $this->assertArrayNotHasKey($clave, $lista[0]);
        }
        $this->assertSame($this->detalle()['saldo']['despues'], $lista[0]['saldo']['despues']);
    }

    public function test_adelanto_en_la_lista_y_en_el_detalle(): void
    {
        // Un ajuste negativo deja el saldo con adelanto: despues = 54 − 58 = −4.
        \App\Models\VacationBalance::create([
            'employee_id' => $this->solicitante->id, 'year' => 2026, 'entitled_days' => 20, 'adjustment_days' => -58,
        ]);

        $lista = $this->getJson(self::BASE.'/por-revisar', $this->entrar($this->director))->assertOk()->json('data');
        $detalle = $this->detalle();

        $this->assertSame(['despues' => -4, 'adelanto' => 4], $lista[0]['saldo']);
        $this->assertSame(-4, $detalle['saldo']['despues']);
        $this->assertSame(4, $detalle['saldo']['adelanto']);
        $this->assertSame(0, $detalle['saldo']['disponibles']);
    }

    /** hire_date es NOT NULL en la BD, pero el presentador no debe romperse si llega vacío (datos viejos, importaciones). */
    public function test_sin_fecha_de_ingreso_no_falla(): void
    {
        $solicitud = VacationRequest::with('employee')->findOrFail($this->revisada->id);
        $solicitud->employee->hire_date = null; // en memoria: no se guarda

        $datos = PresentadorPortalVacaciones::paraRevisar($solicitud, true);
        $json = json_encode($datos);

        $this->assertNull($datos['saldo']['periodo']);
        $this->assertSame(0, $datos['saldo']['disponibles']);
        $this->assertSame(0, $datos['saldo']['despues']);
        $this->assertSame(0, $datos['saldo']['le_corresponden_periodo']);
        $this->assertNull($datos['solicitante_extra']['fecha_ingreso']);
        $this->assertCount(1, $datos['historial']);
        $this->assertNull($datos['historial'][0]['inicio']);
        $this->assertNull($datos['historial'][0]['fin']);
        $this->assertNotEmpty($datos['historial'][0]['items']);
        $this->assertStringNotContainsString('interno', $json);

        $lista = PresentadorPortalVacaciones::paraRevisar($solicitud);
        $this->assertSame(['despues' => 0, 'adelanto' => 0], $lista['saldo']);
    }

    public function test_consultas_acotadas(): void
    {
        $encabezados = $this->entrar($this->director);
        $url = self::BASE.'/por-revisar/'.$this->revisada->id;
        $this->getJson($url, $encabezados)->assertOk(); // calienta cachés

        $contar = function () use ($url, $encabezados): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url, $encabezados)->assertOk();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $con3 = $contar();

        // 27 ítems más repartidos en distintos periodos y estados, algunos rechazados con su paso.
        for ($i = 0; $i < 9; $i++) {
            $inicio = Carbon::parse('2023-04-03')->addDays($i * 20);
            VacationHistoryEntry::create([
                'employee_id' => $this->solicitante->id, 'vacation_year' => 2023,
                'start_date' => $inicio->toDateString(), 'end_date' => $inicio->copy()->addDays(2)->toDateString(), 'days' => 3, 'notes' => 'interno',
            ]);
            $this->directa($this->solicitante, 'approved', Carbon::parse('2025-04-01')->addDays($i * 10)->toDateString(), Carbon::parse('2025-04-02')->addDays($i * 10)->toDateString(), 2);
            $rechazada = $this->directa($this->solicitante, 'rejected', Carbon::parse('2022-05-02')->addDays($i * 10)->toDateString(), Carbon::parse('2022-05-03')->addDays($i * 10)->toDateString(), 2, ['rejection_reason' => 'No']);
            $rechazada->approvals()->create([
                'step_order' => 1, 'description' => 'Jefe directo', 'rules' => [], 'is_rh_final' => false,
                'decision' => VacationRequestApproval::REJECTED, 'decided_by' => $this->jefe->user_id, 'comment' => 'No', 'decided_at' => now(),
            ]);
        }

        $con30 = $contar();

        $this->assertSame($con3, $con30, 'El número de consultas no debe crecer con los ítems del historial.');
    }
}
