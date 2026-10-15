<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\User;
use App\Models\VacationBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class AjusteSaldoVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    public function test_dos_ajustes_desde_instancias_viejas_se_suman_y_la_instancia_queda_al_dia(): void
    {
        $a = VacationBalance::initializeForEmployee($this->empleado($this->empresa), now()->year);
        $b = VacationBalance::find($a->id);
        $usuario = User::factory()->create();

        $a->applyAdjustment(2, 'Primero', $usuario->id);
        $b->applyAdjustment(3, 'Segundo', $usuario->id);

        $guardado = VacationBalance::find($a->id);
        $this->assertSame(5, (int) $guardado->adjustment_days);
        $this->assertSame('Primero | Segundo', $guardado->adjustment_reason);
        $this->assertSame(5, (int) $b->adjustment_days);
    }

    public function test_con_un_motivo_previo_largo_se_conserva_el_texto_nuevo_y_cabe_en_la_columna(): void
    {
        $balance = VacationBalance::initializeForEmployee($this->empleado($this->empresa), now()->year);
        $usuario = User::factory()->create();
        $balance->applyAdjustment(1, str_repeat('A', 250), $usuario->id);

        $balance->applyAdjustment(1, 'Motivo nuevo', $usuario->id);

        $motivo = VacationBalance::find($balance->id)->adjustment_reason;
        $this->assertLessThanOrEqual(255, mb_strlen($motivo));
        $this->assertStringEndsWith(' | Motivo nuevo', $motivo);
    }

    public function test_un_motivo_nuevo_de_255_caracteres_cabe_completo(): void
    {
        $balance = VacationBalance::initializeForEmployee($this->empleado($this->empresa), now()->year);
        $usuario = User::factory()->create();
        $balance->applyAdjustment(1, 'Previo', $usuario->id);

        $balance->applyAdjustment(1, str_repeat('B', 300), $usuario->id);

        $this->assertSame(str_repeat('B', 255), VacationBalance::find($balance->id)->adjustment_reason);
    }
}
