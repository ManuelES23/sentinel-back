<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Exceptions\RH\VacacionesException;
use App\Models\Employee;
use App\Models\User;
use App\Models\VacationRequest;
use App\Models\WorkSchedule;
use App\Services\RH\VacationRequestService;
use App\Services\RH\WorkingDaysCalculator;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** Los domingos nunca se descuentan y quien no tiene saldo puede adelantar días de su siguiente periodo. */
class AdelantoYDomingosVacacionesTest extends TestCase
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

    private function crear(Employee $empleado, int $desde, int $hasta): VacationRequest
    {
        return $this->servicio()->crear($empleado, $this->dia($desde), $this->dia($hasta), null, VacationRequest::SOURCE_PORTAL);
    }

    /** Horario que marca los 7 días, como el «Mexicano» real. */
    private function horarioDeSieteDias(): WorkSchedule
    {
        $dias = [];
        foreach (WorkSchedule::DAYS as $nombre) {
            $dias["{$nombre}_start"] = '08:00:00';
            $dias["{$nombre}_end"] = '17:00:00';
        }

        return WorkSchedule::create(['name' => 'Mexicano'] + $dias);
    }

    private function nuevo(int $meses = 6): Employee
    {
        return $this->empleado($this->empresa, ['hire_date' => now()->subMonths($meses)->toDateString()]);
    }

    // ===== Domingos =====

    public function test_el_domingo_no_se_descuenta_aunque_el_horario_lo_marque(): void
    {
        $empleado = $this->empleado($this->empresa, ['work_schedule_id' => $this->horarioDeSieteDias()->id]);

        $calculo = app(WorkingDaysCalculator::class)->calcular($empleado, $this->dia(0), $this->dia(6));

        $this->assertCount(6, $calculo['descontables']);
        $this->assertSame([['fecha' => $this->dia(6)->toDateString(), 'motivo' => 'descanso']], $calculo['excluidos']);
    }

    public function test_sin_horario_se_cuenta_de_lunes_a_sabado(): void
    {
        $calculo = app(WorkingDaysCalculator::class)->calcular($this->empleado($this->empresa), $this->dia(0), $this->dia(13));

        $this->assertCount(12, $calculo['descontables']);
        $this->assertSame([$this->dia(6)->toDateString(), $this->dia(13)->toDateString()], array_column($calculo['excluidos'], 'fecha'));
    }

    // ===== Adelanto =====

    public function test_personal_nuevo_puede_adelantar_y_queda_en_negativo(): void
    {
        $empleado = $this->nuevo();

        $calculo = $this->servicio()->calcular($empleado, $this->dia(0), $this->dia(4));
        $this->assertSame([], $calculo['errores']);
        $this->assertSame(5, $calculo['adelanto']);
        $this->assertSame(12, $calculo['limite_adelanto']);
        $this->assertSame(now()->subMonths(6)->addYear()->toDateString(), $calculo['proximo_aniversario']);

        $solicitud = $this->crear($empleado, 0, 4);
        $this->assertSame('pending', $solicitud->status);
        $this->assertSame(5, $solicitud->advance_days);
        $this->assertSame(-5, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_no_adelanta_mas_que_su_siguiente_periodo(): void
    {
        $empleado = $this->nuevo();

        // Dos semanas de lunes a sábado = 12: justo lo del primer año.
        $this->assertSame([], $this->servicio()->calcular($empleado, $this->dia(0), $this->dia(12))['errores']);

        try {
            $this->crear($empleado, 0, 14);
            $this->fail('Se esperaba el límite del adelanto');
        } catch (VacacionesException $e) {
            $this->assertStringContainsString('Puede adelantar hasta 12 días', $e->getMessage());
            $this->assertSame(422, $e->status);
        }
    }

    public function test_los_adelantos_previos_cuentan_para_el_limite(): void
    {
        $empleado = $this->nuevo();
        $this->solicitud($empleado, 'approved', 10);

        $calculo = $this->servicio()->calcular($empleado, $this->dia(0), $this->dia(2));

        $this->assertSame(-10, $calculo['disponibles']);
        $this->assertStringContainsString('Puede adelantar hasta 12 días', implode(' ', $calculo['errores']));
        $this->assertSame([], $this->servicio()->calcular($empleado, $this->dia(0), $this->dia(1))['errores']);
    }

    public function test_con_saldo_parcial_solo_lo_que_falta_es_adelanto(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subYear()->subDay()->toDateString()]); // 12 días
        $this->solicitud($empleado, 'approved', 10);

        $solicitud = $this->crear($empleado, 0, 4);

        $this->assertSame(5, $solicitud->days_requested);
        $this->assertSame(3, $solicitud->advance_days);
        $this->assertSame(14, $this->servicio()->calcular($empleado, $this->dia(14), $this->dia(14))['limite_adelanto']);
    }

    public function test_con_saldo_suficiente_no_hay_adelanto(): void
    {
        $solicitud = $this->crear($this->empleado($this->empresa), 0, 4);

        $this->assertSame(0, $solicitud->advance_days);
    }

    public function test_al_cumplir_el_aniversario_el_adelanto_se_descuenta_del_periodo(): void
    {
        $empleado = $this->nuevo(11);
        $this->crear($empleado, 0, 4);
        $this->assertSame(-5, VacationCalculatorService::diasDisponibles($empleado));

        Carbon::setTestNow(now()->addMonths(2));
        try {
            $this->assertSame(7, VacationCalculatorService::diasDisponibles($empleado->fresh()));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reprogramar_recalcula_el_adelanto(): void
    {
        $empleado = $this->nuevo();
        $solicitud = $this->crear($empleado, 0, 4);

        $nueva = $this->servicio()->reprogramar($solicitud, $this->dia(0), $this->dia(1), null);

        $this->assertSame(2, $nueva->days_requested);
        $this->assertSame(2, $nueva->advance_days);
    }

    // ===== Lo que ven el portal, el jefe, RH y el perfil =====

    public function test_el_portal_y_el_jefe_ven_el_adelanto(): void
    {
        $empleado = $this->nuevo();
        $token = $this->postJson('/api/portal-vacaciones/login', ['employee_number' => $empleado->employee_number, 'pin' => '123456'])->json('data.token');
        $encabezados = ['X-Portal-Token' => $token];
        $fechas = ['start_date' => $this->dia(0)->toDateString(), 'end_date' => $this->dia(4)->toDateString()];

        $this->postJson('/api/portal-vacaciones/calcular', $fechas, $encabezados)->assertOk()
            ->assertJsonPath('data.adelanto', 5)
            ->assertJsonPath('data.limite_adelanto', 12)
            ->assertJsonPath('data.errores', []);
        $id = $this->postJson('/api/portal-vacaciones/solicitudes', $fechas, $encabezados)->assertCreated()->json('data.id');

        $this->getJson('/api/portal-vacaciones/solicitudes', $encabezados)->assertOk()->assertJsonPath('data.0.adelanto', 5);
        $this->getJson('/api/portal-vacaciones/yo', $encabezados)->assertOk()->assertJsonPath('data.saldo.disponibles', -5);
        $this->assertSame(5, \App\Support\RH\PresentadorPortalVacaciones::paraRevisar(VacationRequest::find($id))['adelanto']);
    }

    public function test_rh_calcula_la_vista_previa_con_las_mismas_reglas(): void
    {
        $this->submoduloVacaciones();
        $rh = User::factory()->create();
        Sanctum::actingAs($rh);
        $empleado = $this->nuevo();
        $datos = ['employee_id' => $empleado->id, 'start_date' => $this->dia(0)->toDateString(), 'end_date' => $this->dia(6)->toDateString()];

        $this->postJson('/api/grupoesplendido/rh/vacaciones/calcular', $datos)->assertForbidden();

        $this->concederVacaciones($rh, 'view');
        $this->postJson('/api/grupoesplendido/rh/vacaciones/calcular', $datos)->assertOk()
            ->assertJsonPath('data.dias_descontables', 6)
            ->assertJsonPath('data.excluidos.0.motivo', 'descanso')
            ->assertJsonPath('data.adelanto', 6)
            ->assertJsonPath('data.limite_adelanto', 12)
            ->assertJsonPath('data.errores', []);
        $this->assertDatabaseCount('vacation_requests', 0);
    }

    public function test_rh_y_el_perfil_ven_el_adelanto(): void
    {
        $this->submoduloVacaciones();
        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'view', 'create');
        Sanctum::actingAs($rh);
        $empleado = $this->empleadoConUsuario($this->empresa, ['hire_date' => now()->subMonths(4)->toDateString()]);

        $this->postJson('/api/grupoesplendido/rh/vacaciones', [
            'employee_id' => $empleado->id,
            'start_date' => $this->dia(0)->toDateString(),
            'end_date' => $this->dia(2)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.advance_days', 3);
        $this->getJson('/api/grupoesplendido/rh/vacaciones')->assertOk()->assertJsonPath('data.0.advance_days', 3);

        Sanctum::actingAs($empleado->user);
        $this->getJson('/api/profile')->assertOk()
            ->assertJsonPath('data.vacation.balance.available_days', -3)
            ->assertJsonPath('data.vacation.requests.0.advance_days', 3);
    }
}
