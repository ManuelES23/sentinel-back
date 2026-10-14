<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\VacationBalance;
use App\Services\VacationCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class SaldoVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    public function test_usados_y_pendientes_salen_de_las_solicitudes(): void
    {
        $empleado = $this->empleado($this->empresa);
        VacationBalance::create([
            'employee_id' => $empleado->id, 'year' => now()->year, 'entitled_days' => 16,
            'used_days' => 99, 'pending_days' => 99, 'carried_over' => 0, 'adjustment_days' => 1,
        ]);
        $this->solicitud($empleado, 'approved', 5);
        $this->solicitud($empleado, 'pending', 2);
        $this->solicitud($empleado, 'cancelled', 4);
        $this->solicitud($empleado, 'rejected', 3);

        $info = VacationCalculatorService::getEmployeeVacationInfo($empleado);

        $this->assertSame(42, $info['accumulated']['total_accumulated_days']);
        $this->assertSame(5, $info['accumulated']['total_used_days']);
        $this->assertSame(2, $info['accumulated']['total_pending_days']);
        $this->assertSame(1, $info['accumulated']['total_adjustment_days']);
        $this->assertSame(36, $info['accumulated']['total_available_days']);
        $this->assertSame(36, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_una_solicitud_borrada_no_cuenta(): void
    {
        $empleado = $this->empleado($this->empresa);
        $this->solicitud($empleado, 'approved', 5)->delete();

        $this->assertSame(42, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_redondeo_despues_de_35_anios(): void
    {
        foreach ([35 => 32, 36 => 34, 40 => 34, 41 => 36] as $anios => $dias) {
            $this->assertSame($dias, VacationCalculatorService::calculateEntitledDays($anios), "{$anios} años");
            $this->assertSame($dias, VacationBalance::calculateEntitledDays($anios), "{$anios} años (balance)");
        }
    }

    public function test_ingreso_en_el_futuro_da_cero_dias(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->addMonth()->toDateString()]);

        $info = VacationCalculatorService::getEmployeeVacationInfo($empleado);

        $this->assertSame(0, $info['years_of_service']);
        $this->assertSame(0, VacationCalculatorService::diasDisponibles($empleado));
    }

    public function test_antiguedad_es_entera_aunque_carbon_devuelva_fracciones(): void
    {
        $empleado = $this->empleado($this->empresa, ['hire_date' => now()->subYears(2)->subMonths(7)->toDateString()]);

        $info = VacationCalculatorService::getEmployeeVacationInfo($empleado);

        $this->assertSame(2, $info['years_of_service']);
        $this->assertSame(26, $info['accumulated']['total_accumulated_days']);
    }
}
