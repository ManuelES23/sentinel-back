<?php

namespace Tests\Unit\RH;

use App\Services\VacationCalculatorService;
use App\Support\RH\PeriodosVacaciones;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Tabla visual de periodos: cada aniversario cumplido asigna sus días (LFT) y lo que se toma se descuenta
 * del periodo más antiguo con días (los días son acumulables). No cambia el saldo; solo lo reparte.
 */
class PeriodosVacacionesTest extends TestCase
{
    private function dias(int $anio): int
    {
        return VacationCalculatorService::calculateEntitledDays($anio);
    }

    private function tabla(int $usados = 0, int $otras = 0, int $esta = 0, int $ajustes = 0, string $ingreso = '2022-03-15', string $hoy = '2025-06-01'): array
    {
        return PeriodosVacaciones::tabla(Carbon::parse($ingreso), Carbon::parse($hoy), $ajustes, $usados, $otras, $esta);
    }

    public function test_cada_aniversario_cumplido_es_un_periodo_con_sus_dias(): void
    {
        $t = $this->tabla();

        // Ingresó en 2022: 2023, 2024 y 2025 son los tres aniversarios cumplidos al 1 jun 2025.
        $this->assertSame([1, 2, 3], array_column($t['filas'], 'numero'));
        $this->assertSame(['2023-03-15', '2024-03-15', '2025-03-15'], array_column($t['filas'], 'inicio'));
        $this->assertSame(['2024-03-14', '2025-03-14', '2026-03-14'], array_column($t['filas'], 'fin'));
        $this->assertSame([$this->dias(1), $this->dias(2), $this->dias(3)], array_column($t['filas'], 'asignados'));
        $this->assertSame(0, $t['adelanto']);
    }

    public function test_lo_tomado_se_descuenta_del_periodo_mas_antiguo(): void
    {
        $t = $this->tabla(usados: $this->dias(1) + 3);

        $this->assertSame($this->dias(1), $t['filas'][0]['tomados']);
        $this->assertSame(0, $t['filas'][0]['quedan']);
        $this->assertSame(3, $t['filas'][1]['tomados']);
        $this->assertSame($this->dias(2) - 3, $t['filas'][1]['quedan']);
        $this->assertSame(0, $t['filas'][2]['tomados']);
        $this->assertSame($this->dias(3), $t['filas'][2]['quedan']);
    }

    public function test_sin_nada_tomado_se_acumulan_los_dias_de_varios_periodos(): void
    {
        $t = $this->tabla();

        $this->assertSame([$this->dias(1), $this->dias(2), $this->dias(3)], array_column($t['filas'], 'quedan'));
        $this->assertSame($this->dias(1) + $this->dias(2) + $this->dias(3), $t['total']['quedan']);
    }

    public function test_lo_que_esta_en_revision_y_esta_solicitud_van_despues_de_lo_tomado(): void
    {
        $t = $this->tabla(usados: $this->dias(1) - 2, otras: 3, esta: 4);

        // Tomados: dias(1) - 2 del periodo 1. Otras en revisión (3): 2 completan el periodo 1 y 1 va al 2. Esta (4) va al 2.
        $this->assertSame([$this->dias(1) - 2, 0, 0], array_column($t['filas'], 'tomados'));
        $this->assertSame([2, 1, 0], array_column($t['filas'], 'en_revision'));
        $this->assertSame([0, 4, 0], array_column($t['filas'], 'esta'));
        $this->assertSame(0, $t['filas'][0]['quedan']);
        $this->assertSame($this->dias(2) - 5, $t['filas'][1]['quedan']);
    }

    public function test_lo_que_pasa_de_todo_lo_asignado_es_adelanto(): void
    {
        $todo = $this->dias(1) + $this->dias(2) + $this->dias(3);
        $t = $this->tabla(usados: $todo - 1, esta: 4);

        $this->assertSame([0, 0, 0], array_column($t['filas'], 'quedan'));
        $this->assertSame(3, $t['adelanto']);
        // «Quedan» total negativo = el adelanto: coincide con el saldo (asignados - todo lo consumido).
        $this->assertSame($todo - ($todo - 1) - 4, $t['total']['quedan'] - $t['adelanto']);
    }

    public function test_un_ajuste_positivo_de_rh_es_una_fila_aparte_y_se_consume_primero(): void
    {
        $t = $this->tabla(usados: 5, ajustes: 8);

        $ajuste = $t['filas'][0];
        $this->assertSame('ajuste', $ajuste['tipo']);
        $this->assertNull($ajuste['numero']);
        $this->assertSame(8, $ajuste['asignados']);
        $this->assertSame(5, $ajuste['tomados']);
        $this->assertSame(3, $ajuste['quedan']);
        $this->assertSame($this->dias(1), $t['filas'][1]['quedan']);
    }

    public function test_un_ajuste_negativo_resta_sin_consumir(): void
    {
        $t = $this->tabla(usados: 2, ajustes: -3);

        $ajuste = collect($t['filas'])->firstWhere('tipo', 'ajuste');
        $this->assertSame(-3, $ajuste['asignados']);
        $this->assertSame(-3, $ajuste['quedan']);
        $this->assertSame($this->dias(1) - 2, collect($t['filas'])->firstWhere('numero', 1)['quedan']);
        // El total cuadra con el saldo: asignados + ajuste - tomados.
        $this->assertSame($this->dias(1) + $this->dias(2) + $this->dias(3) - 3 - 2, $t['total']['quedan']);
    }

    public function test_sin_aniversarios_cumplidos_o_sin_ingreso_no_hay_filas(): void
    {
        $this->assertSame([], $this->tabla(ingreso: '2025-01-10', hoy: '2025-06-01')['filas']);
        $this->assertSame([], PeriodosVacaciones::tabla(null, Carbon::parse('2025-06-01'), 0, 0, 0, 0)['filas']);
    }

    public function test_el_total_suma_las_columnas(): void
    {
        $t = $this->tabla(usados: 5, otras: 2, esta: 3);

        $this->assertSame(5, $t['total']['tomados']);
        $this->assertSame(2, $t['total']['en_revision']);
        $this->assertSame(3, $t['total']['esta']);
        $this->assertSame($this->dias(1) + $this->dias(2) + $this->dias(3), $t['total']['asignados']);
        $this->assertSame($t['total']['asignados'] - 10, $t['total']['quedan']);
    }
}
