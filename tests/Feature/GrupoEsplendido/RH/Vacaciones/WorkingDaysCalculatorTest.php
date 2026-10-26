<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Holiday;
use App\Models\WorkSchedule;
use App\Services\RH\WorkingDaysCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class WorkingDaysCalculatorTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function calcular($empleado, string $inicio, string $fin): array
    {
        return app(WorkingDaysCalculator::class)->calcular($empleado, Carbon::parse($inicio), Carbon::parse($fin));
    }

    private function horario(array $dias, string $nombre = 'Horario'): WorkSchedule
    {
        $datos = ['name' => $nombre, 'is_active' => true];
        foreach ($dias as $dia) {
            $datos["{$dia}_start"] = '08:00';
            $datos["{$dia}_end"] = '17:00';
        }

        return WorkSchedule::create($datos);
    }

    public function test_sin_horario_cuenta_lunes_a_sabado(): void
    {
        $resultado = $this->calcular($this->empleado($this->empresa), '2026-10-12', '2026-10-18');

        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17'], $resultado['descontables']);
        $this->assertSame([['fecha' => '2026-10-18', 'motivo' => 'descanso']], $resultado['excluidos']);
    }

    public function test_un_festivo_general_no_se_descuenta(): void
    {
        Holiday::create(['date' => '2026-09-16', 'name' => 'Día de la Independencia', 'enterprise_id' => null, 'is_official' => true]);

        $resultado = $this->calcular($this->empleado($this->empresa), '2026-09-14', '2026-09-18');

        $this->assertCount(4, $resultado['descontables']);
        $this->assertContains(['fecha' => '2026-09-16', 'motivo' => 'festivo: Día de la Independencia'], $resultado['excluidos']);
    }

    public function test_el_festivo_de_otra_empresa_si_se_descuenta(): void
    {
        Holiday::create(['date' => '2026-09-16', 'name' => 'Aniversario corporativo', 'enterprise_id' => $this->corporativo->id]);

        $this->assertCount(5, $this->calcular($this->empleado($this->empresa), '2026-09-14', '2026-09-18')['descontables']);
    }

    public function test_horario_propio_que_trabaja_sabado(): void
    {
        $horario = $this->horario(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']);
        $empleado = $this->empleado($this->empresa, ['work_schedule_id' => $horario->id]);

        $resultado = $this->calcular($empleado, '2026-10-12', '2026-10-18');

        $this->assertCount(6, $resultado['descontables']);
        $this->assertSame([['fecha' => '2026-10-18', 'motivo' => 'descanso']], $resultado['excluidos']);
    }

    public function test_sin_horario_propio_usa_el_predeterminado_de_su_empresa(): void
    {
        $horario = $this->horario(['monday', 'tuesday', 'wednesday', 'thursday'], 'Lun-Jue');
        $horario->enterprises()->attach($this->empresa->id, ['is_default' => true]);

        $this->assertCount(4, $this->calcular($this->empleado($this->empresa), '2026-10-12', '2026-10-18')['descontables']);
    }

    public function test_solo_domingo_no_descuenta_nada_y_el_sabado_si(): void
    {
        $this->assertSame([], $this->calcular($this->empleado($this->empresa), '2026-10-18', '2026-10-18')['descontables']);
        $this->assertSame(['2026-10-17'], $this->calcular($this->empleado($this->empresa), '2026-10-17', '2026-10-18')['descontables']);
    }
}
