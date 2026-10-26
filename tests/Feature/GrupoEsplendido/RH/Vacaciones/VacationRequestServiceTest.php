<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Exceptions\RH\VacacionesException;
use App\Models\ApprovalFlowStep;
use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\VacationBalance;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\VacationRequestService;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class VacationRequestServiceTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->lunes = today()->next(Carbon::MONDAY)->addWeeks(2);
    }

    private function servicio(): VacationRequestService
    {
        return app(VacationRequestService::class);
    }

    private function dia(int $desfase): Carbon
    {
        return $this->lunes->copy()->addDays($desfase);
    }

    private function crear($empleado, int $desde, int $hasta, array $opciones = []): VacationRequest
    {
        return $this->servicio()->crear($empleado, $this->dia($desde), $this->dia($hasta), null, VacationRequest::SOURCE_PORTAL, null, $opciones);
    }

    private function esperarError(string $mensaje, callable $accion): void
    {
        try {
            $accion();
            $this->fail('Se esperaba VacacionesException: '.$mensaje);
        } catch (VacacionesException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());
            $this->assertSame(422, $e->status);
        }
    }

    public function test_crea_pendiente_con_dias_descontables_y_cadena(): void
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $empleado = $this->empleado($this->empresa, ['reports_to' => $jefe->id]);

        $solicitud = $this->crear($empleado, 0, 6); // lunes a domingo: el domingo no cuenta

        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(6, $solicitud->days_requested);
        $this->assertSame(VacationRequest::SOURCE_PORTAL, $solicitud->source);
        $this->assertSame(1, $solicitud->current_step);
        $this->assertCount(2, $solicitud->approvals);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $jefe->user_id, 'category' => 'vacation']);
        $this->assertSame(36, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_calcular_reporta_excluidos_y_saldo(): void
    {
        $empleado = $this->empleado($this->empresa);
        Holiday::create(['date' => $this->dia(2)->toDateString(), 'name' => 'Festivo de prueba']);

        $calculo = $this->servicio()->calcular($empleado, $this->dia(0), $this->dia(6));

        $this->assertSame(7, $calculo['dias_naturales']);
        $this->assertSame(5, $calculo['dias_descontables']);
        $this->assertContains(['fecha' => $this->dia(2)->toDateString(), 'motivo' => 'festivo: Festivo de prueba'], $calculo['excluidos']);
        $this->assertSame(42, $calculo['disponibles']);
        $this->assertSame(37, $calculo['disponibles_despues']);
        $this->assertSame([], $calculo['errores']);
    }

    public function test_menos_de_un_anio_ya_no_bloquea_se_adelanta(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subMonths(6)->toDateString()]);

        $solicitud = $this->crear($empleado, 0, 4);

        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(5, $solicitud->advance_days);
    }

    public function test_no_pasa_del_saldo_mas_el_adelanto(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subYear()->subDay()->toDateString()]); // 12 días; adelanta hasta 14
        $this->solicitud($empleado, 'approved', 10);

        $this->esperarError('Disponibles: 2, solicitados: 17', fn () => $this->crear($empleado, 0, 18));
    }

    public function test_sin_empalmes_con_pendientes_o_aprobadas(): void
    {
        $empleado = $this->empleado($this->empresa);
        $this->crear($empleado, 0, 2);

        $this->esperarError('se empalma', fn () => $this->crear($empleado, 2, 4));

        $otro = $this->empleado($this->empresa);
        $this->solicitud($otro, 'rejected', 5, ['start_date' => $this->dia(0)->toDateString(), 'end_date' => $this->dia(4)->toDateString()]);
        $this->solicitud($otro, 'cancelled', 5, ['start_date' => $this->dia(0)->toDateString(), 'end_date' => $this->dia(4)->toDateString()]);
        $this->assertSame('pending', $this->crear($otro, 0, 4)->status);
    }

    public function test_fechas_invalidas(): void
    {
        $empleado = $this->empleado($this->empresa);

        $this->esperarError('igual o posterior', fn () => $this->crear($empleado, 4, 0));
        $this->esperarError('más de 60 días naturales', fn () => $this->crear($empleado, 0, 60));
        $this->esperarError('no incluye días laborables', fn () => $this->crear($empleado, 6, 6)); // domingo
        $this->esperarError('anterior a hoy', fn () => $this->servicio()->crear(
            $empleado, today()->subDays(10), today()->subDays(3), null, VacationRequest::SOURCE_PORTAL
        ));

        $pasada = $this->servicio()->crear($empleado, today()->subDays(10), today()->subDays(3), null, VacationRequest::SOURCE_RH, null, ['permitir_pasado' => true]);
        $this->assertSame('pending', $pasada->status);
    }

    public function test_un_festivo_suelto_no_genera_una_solicitud_de_cero_dias(): void
    {
        $empleado = $this->empleado($this->empresa);
        Holiday::create(['date' => $this->dia(1)->toDateString(), 'name' => 'Festivo']);

        $this->esperarError('no incluye días laborables', fn () => $this->crear($empleado, 1, 1));
    }

    public function test_dos_solicitudes_seguidas_no_rebasan_el_saldo(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subYear()->subDay()->toDateString()]); // 12 días

        $this->crear($empleado, 0, 11); // lunes a viernes de la semana siguiente = 11 días
        // Le queda 1 y adelanta hasta 14: 16 días más ya no caben.
        $this->esperarError('Disponibles: 1', fn () => $this->crear($empleado, 14, 31));
    }

    public function test_el_empleado_cancela_una_pendiente_y_recupera_los_dias(): void
    {
        $empleado = $this->empleado($this->empresa);
        $solicitud = $this->crear($empleado, 0, 4);

        $cancelada = $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_EMPLEADO);

        $this->assertSame('cancelled', $cancelada->status);
        $this->assertNull($cancelada->current_step);
        $this->assertSame(0, $cancelada->approvals()->where('decision', VacationRequestApproval::WAITING)->count());
        $this->assertSame(42, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_el_empleado_no_cancela_una_aprobada(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'approved', 3);

        $this->esperarError('siguen en revisión', fn () => $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_EMPLEADO));
    }

    public function test_rh_cancela_una_aprobada_futura_y_borra_su_asistencia(): void
    {
        $empleado = $this->empleado($this->empresa);
        $solicitud = $this->solicitud($empleado, 'approved', 3, ['start_date' => $this->dia(0)->toDateString(), 'end_date' => $this->dia(2)->toDateString()]);
        foreach ([0, 1] as $d) {
            AttendanceRecord::create(['employee_id' => $empleado->id, 'date' => $this->dia($d)->toDateString(), 'status' => 'vacation']);
        }
        AttendanceRecord::create(['employee_id' => $empleado->id, 'date' => $this->dia(2)->toDateString(), 'status' => 'present', 'check_in' => $this->dia(2)->copy()->setTime(8, 0)]);

        $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_RH);

        $this->assertSame('cancelled', $solicitud->fresh()->status);
        $this->assertSame(0, AttendanceRecord::where('employee_id', $empleado->id)->where('status', 'vacation')->count());
        $this->assertSame(1, AttendanceRecord::where('employee_id', $empleado->id)->where('status', 'present')->count());
    }

    public function test_rh_no_cancela_una_aprobada_que_ya_empezo(): void
    {
        $solicitud = $this->solicitud($this->empleado($this->empresa), 'approved', 3, ['start_date' => today()->toDateString(), 'end_date' => today()->addDays(2)->toDateString()]);

        $this->esperarError('aún no empiezan', fn () => $this->servicio()->cancelar($solicitud, VacationRequestService::CANCELA_RH));
    }

    public function test_excluir_id_de_otro_empleado_no_suma_sus_dias(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subYear()->subDay()->toDateString()]); // 12 días
        $otro = $this->empleado($this->empresa);
        $ajena = $this->solicitud($otro, 'pending', 20);
        $propia = $this->crear($empleado, 0, 2); // 3 días

        $calculo = $this->servicio()->calcular($empleado, $this->dia(14), $this->dia(18), ['excluir_id' => $ajena->id]);
        $this->assertSame(9, $calculo['disponibles']);

        // 27 días rebasan los 12 + 14 de adelanto aunque se pase una excluir_id ajena en las opciones.
        $this->esperarError('Disponibles: 12', fn () => $this->servicio()->reprogramar(
            $propia, $this->dia(0), $this->dia(30), null, ['excluir_id' => $ajena->id]
        ));
    }

    public function test_reprogramar_recalcula_sin_chocar_consigo_misma(): void
    {
        $empleado = $this->empleado($this->empresa);
        $solicitud = $this->crear($empleado, 0, 4);

        $reprogramada = $this->servicio()->reprogramar($solicitud, $this->dia(0), $this->dia(3), 'Ajuste');

        $this->assertSame(4, $reprogramada->days_requested);
        $this->assertSame('Ajuste', $reprogramada->reason);
        $this->assertSame(38, VacationCalculatorService::diasDisponibles($empleado));
    }

    /** Cadena de 2 pasos: jefe directo (1) y coordinador de RH del corporativo (2). */
    private function cadenaDeDosPasos(): array
    {
        $jefe = $this->empleadoConUsuario($this->empresa);
        $this->regla(1, ApprovalFlowStep::TYPE_DIRECT_SUPERVISOR);
        $puesto = $this->puesto($this->corporativo, 'Coordinador de RH', 3, true, 'enterprise');
        $this->regla(2, 'position', ['position_id' => $puesto->id]);
        $this->empleadoConUsuario($this->corporativo, ['position_id' => $puesto->id]);

        return [$jefe, $this->empleado($this->empresa, ['reports_to' => $jefe->id])];
    }

    public function test_reprogramar_con_un_paso_ya_aprobado_reconstruye_la_cadena(): void
    {
        [$jefe, $empleado] = $this->cadenaDeDosPasos();
        $solicitud = $this->crear($empleado, 0, 4);
        $solicitud = $this->servicio()->aprobarPaso($solicitud, $jefe->user);
        $this->assertSame(2, $solicitud->current_step);
        $idsAntes = $solicitud->approvals->pluck('id')->all();

        $reprogramada = $this->servicio()->reprogramar($solicitud, $this->dia(7), $this->dia(10), null);

        $this->assertSame(1, $reprogramada->current_step);
        $this->assertCount(3, $reprogramada->approvals);
        $this->assertFalse($reprogramada->approvals->contains('decision', VacationRequestApproval::APPROVED));
        $this->assertSame([], array_intersect($idsAntes, $reprogramada->approvals->pluck('id')->all()));
        $this->assertSame('pending', $reprogramada->status);
    }

    public function test_reprogramar_sin_pasos_aprobados_conserva_la_cadena(): void
    {
        [, $empleado] = $this->cadenaDeDosPasos();
        $solicitud = $this->crear($empleado, 0, 4);
        $idsAntes = $solicitud->approvals->pluck('id')->sort()->values()->all();

        $reprogramada = $this->servicio()->reprogramar($solicitud, $this->dia(7), $this->dia(10), null);

        $this->assertSame($idsAntes, $reprogramada->approvals->pluck('id')->sort()->values()->all());
        $this->assertSame(1, $reprogramada->current_step);
    }
}
