<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\User;
use App\Models\VacationBalance;
use App\Services\VacationCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class ConciliacionSaldosVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function conciliar(): void
    {
        (require database_path('migrations/2026_10_15_100100_reconcile_vacation_balances.php'))->up();
    }

    private function balance(int $empleadoId, ?int $anio = null): VacationBalance
    {
        return VacationBalance::where('employee_id', $empleadoId)->where('year', $anio ?? now()->year)->firstOrFail();
    }

    public function test_la_diferencia_se_vuelve_ajuste_y_el_saldo_no_cambia(): void
    {
        $empleado = $this->empleado($this->empresa);
        VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 16, 'used_days' => 5, 'pending_days' => 2, 'carried_over' => 0, 'adjustment_days' => 0]);
        $this->solicitud($empleado, 'approved', 3);
        // Fórmula vieja (contadores): 42 + 0 − 5 − 2 = 35.

        $this->conciliar();

        $this->assertSame(35, VacationCalculatorService::diasDisponibles($empleado));
        $this->assertSame(-4, $this->balance($empleado->id)->adjustment_days);
        $this->assertStringContainsString('Conciliación al migrar', $this->balance($empleado->id)->adjustment_reason);
    }

    public function test_saldos_que_ya_cuadran_no_se_tocan(): void
    {
        $empleado = $this->empleado($this->empresa);
        VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 16, 'used_days' => 3, 'pending_days' => 0, 'carried_over' => 0, 'adjustment_days' => 2, 'adjustment_reason' => 'Bono']);
        $this->solicitud($empleado, 'approved', 3);

        $this->conciliar();

        $this->assertSame(2, $this->balance($empleado->id)->adjustment_days);
        $this->assertSame('Bono', $this->balance($empleado->id)->adjustment_reason);
    }

    public function test_es_reejecutable(): void
    {
        $empleado = $this->empleado($this->empresa);
        VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 16, 'used_days' => 5, 'pending_days' => 2, 'carried_over' => 0, 'adjustment_days' => 0]);
        $this->solicitud($empleado, 'approved', 3);

        $this->conciliar();
        $this->conciliar();

        $this->assertSame(-4, $this->balance($empleado->id)->adjustment_days);
        $this->assertSame(35, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_contadores_repartidos_en_varios_anios(): void
    {
        $empleado = $this->empleado($this->empresa);
        VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year - 1, 'entitled_days' => 14, 'used_days' => 4, 'pending_days' => 0, 'carried_over' => 0, 'adjustment_days' => 0]);
        VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 16, 'used_days' => 1, 'pending_days' => 0, 'carried_over' => 0, 'adjustment_days' => 0]);
        $this->solicitud($empleado, 'approved', 5);

        $this->conciliar();

        $this->assertSame(0, $this->balance($empleado->id)->adjustment_days);
        $this->assertSame(0, $this->balance($empleado->id, now()->year - 1)->used_days);
        $this->assertSame(5, $this->balance($empleado->id)->used_days);
    }

    public function test_un_ajuste_de_rh_se_suma_al_de_la_conciliacion_sin_borrarlo(): void
    {
        $empleado = $this->empleado($this->empresa);
        VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 16, 'used_days' => 5, 'pending_days' => 2, 'carried_over' => 0, 'adjustment_days' => 0]);
        $this->solicitud($empleado, 'approved', 3);
        $this->conciliar();
        $antes = VacationCalculatorService::diasDisponibles($empleado);
        $this->assertSame(-4, $this->balance($empleado->id)->adjustment_days);

        $rh = User::factory()->create();
        $this->concederVacaciones($rh, 'edit');
        Sanctum::actingAs($rh);

        $this->postJson('/api/grupoesplendido/rh/vacaciones/empleado/'.$empleado->id.'/ajuste', [
            'adjustment_days' => 2,
            'adjustment_reason' => 'Bono por antigüedad',
        ])->assertOk();

        $balance = $this->balance($empleado->id);
        $this->assertSame(-2, $balance->adjustment_days);
        $this->assertSame($antes + 2, VacationCalculatorService::diasDisponibles($empleado));
        $this->assertStringContainsString('Conciliación al migrar', $balance->adjustment_reason);
        $this->assertStringContainsString('Bono por antigüedad', $balance->adjustment_reason);
        $this->assertSame($rh->id, $balance->adjusted_by);
    }

    public function test_el_motivo_acumulado_no_excede_la_columna(): void
    {
        $empleado = $this->empleado($this->empresa);
        $balance = VacationBalance::create(['employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 16, 'used_days' => 0, 'pending_days' => 0, 'carried_over' => 0, 'adjustment_days' => 5, 'adjustment_reason' => str_repeat('a', 250)]);

        $balance->applyAdjustment(2, str_repeat('b', 100), User::factory()->create()->id);

        $this->assertSame(7, $balance->fresh()->adjustment_days);
        $this->assertSame(255, mb_strlen($balance->fresh()->adjustment_reason));
    }
}
