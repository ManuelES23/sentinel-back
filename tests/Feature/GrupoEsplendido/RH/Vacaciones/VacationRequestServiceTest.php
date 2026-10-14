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

        $solicitud = $this->crear($empleado, 0, 6); // lunes a domingo

        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(5, $solicitud->days_requested);
        $this->assertSame(VacationRequest::SOURCE_PORTAL, $solicitud->source);
        $this->assertSame(1, $solicitud->current_step);
        $this->assertCount(2, $solicitud->approvals);
        $this->assertDatabaseHas('system_notifications', ['user_id' => $jefe->user_id, 'category' => 'vacation']);
        $this->assertSame(37, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_calcular_reporta_excluidos_y_saldo(): void
    {
        $empleado = $this->empleado($this->empresa);
        Holiday::create(['date' => $this->dia(2)->toDateString(), 'name' => 'Festivo de prueba']);

        $calculo = $this->servicio()->calcular($empleado, $this->dia(0), $this->dia(6));

        $this->assertSame(7, $calculo['dias_naturales']);
        $this->assertSame(4, $calculo['dias_descontables']);
        $this->assertContains(['fecha' => $this->dia(2)->toDateString(), 'motivo' => 'festivo: Festivo de prueba'], $calculo['excluidos']);
        $this->assertSame(42, $calculo['disponibles']);
        $this->assertSame(38, $calculo['disponibles_despues']);
        $this->assertSame([], $calculo['errores']);
    }

    public function test_menos_de_un_anio_de_antiguedad(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subMonths(6)->toDateString()]);

        $this->esperarError('al menos 1 año de antigüedad', fn () => $this->crear($empleado, 0, 4));

        VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 0, 'used_days' => 0, 'pending_days' => 0, 'carried_over' => 0, 'adjustment_days' => 10]);
        $this->assertSame('pending', $this->crear($empleado, 0, 4, ['omitir_antiguedad' => true])->status);
    }

    public function test_no_pasa_del_saldo(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subYear()->subDay()->toDateString()]); // 12 días
        $this->solicitud($empleado, 'approved', 10);

        $this->esperarError('Disponibles: 2, solicitados: 5', fn () => $this->crear($empleado, 0, 4));
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
        $this->esperarError('no incluye días laborables', fn () => $this->crear($empleado, 5, 6)); // sábado y domingo
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

        $this->crear($empleado, 0, 11); // dos semanas = 10 días
        $this->esperarError('Disponibles: 2', fn () => $this->crear($empleado, 14, 18));
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

    public function test_reprogramar_recalcula_sin_chocar_consigo_misma(): void
    {
        $empleado = $this->empleado($this->empresa);
        $solicitud = $this->crear($empleado, 0, 4);

        $reprogramada = $this->servicio()->reprogramar($solicitud, $this->dia(0), $this->dia(3), 'Ajuste');

        $this->assertSame(4, $reprogramada->days_requested);
        $this->assertSame('Ajuste', $reprogramada->reason);
        $this->assertSame(38, VacationCalculatorService::diasDisponibles($empleado));
    }
}
