<?php

namespace Tests\Unit\Planeacion;

use App\Services\Planeacion\CalculadoraBalance;
use PHPUnit\Framework\TestCase;

class CalculadoraBalanceTest extends TestCase
{
    /** Convierte una serie de 6 días en renglones de necesidad. */
    private function necesidad(string|int $grupo, array $cajas): array
    {
        $filas = [];
        foreach ($cajas as $dia => $c) {
            $filas[] = ['grupo' => $grupo, 'dia' => $dia, 'cajas' => $c];
        }
        return $filas;
    }

    /** Convierte una serie de 6 días en renglones de producción de una temporada. */
    private function produccion(string|int $grupo, array $cajas, ?int $temporadaId = null): array
    {
        $filas = [];
        foreach ($cajas as $dia => $c) {
            $filas[] = ['grupo' => $grupo, 'temporada_id' => $temporadaId, 'dia' => $dia, 'cajas' => $c];
        }
        return $filas;
    }

    public function test_wk48_amarillo_bicolor_se_cubre_con_inventario(): void
    {
        $r = CalculadoraBalance::calcular(
            ['ab' => ['cultivo_id' => 1]],
            $this->produccion('ab', [10350, 4700, 3725, 6500, 3600, 3800], 1),
            $this->necesidad('ab', [7110, 850, 560, 8500, 1520, 3500]),
            ['ab' => 0]
        );

        $g = $r['ab'];
        $this->assertSame([3240, 3850, 3165, -2000, 2080, 300], $g['del_dia']);
        $this->assertSame([3240, 7090, 10255, 8255, 10335, 10635], $g['acumulado']);
        $this->assertSame(10635, $g['final']);
        $this->assertSame(0, $g['inicial']);
        $this->assertSame('cubre_inventario', $g['estado']);
        $this->assertNull($g['primer_dia_falta']);
        $this->assertNull($g['peor_dia']);
        $this->assertNull($g['peor_valor']);
    }

    public function test_wk48_blanco_falta_desde_el_miercoles(): void
    {
        $r = CalculadoraBalance::calcular(
            ['bl' => ['cultivo_id' => 1]],
            $this->produccion('bl', [2100, 1400, 2450, 0, 2800, 2800], 1),
            $this->necesidad('bl', [1120, 1580, 6584, 1548, 1111, 2500]),
            ['bl' => 0]
        );

        $g = $r['bl'];
        $this->assertSame([980, 800, -3334, -4882, -3193, -2893], $g['acumulado']);
        $this->assertSame(-2893, $g['final']);
        $this->assertSame('falta', $g['estado']);
        $this->assertSame(2, $g['primer_dia_falta']);
        $this->assertSame(3, $g['peor_dia']);
        $this->assertSame(-4882, $g['peor_valor']);
    }

    public function test_adelanto_de_2800_el_miercoles_deja_blanco_en_menos_93(): void
    {
        $produccion = $this->produccion('bl', [2100, 1400, 2450, 0, 2800, 2800], 1);
        $produccion[] = ['grupo' => 'bl', 'temporada_id' => 1, 'dia' => 2, 'cajas' => 2800];

        $r = CalculadoraBalance::calcular(
            ['bl' => ['cultivo_id' => 1]],
            $produccion,
            $this->necesidad('bl', [1120, 1580, 6584, 1548, 1111, 2500]),
            ['bl' => 0]
        );

        $this->assertSame(-93, $r['bl']['final']);
        $this->assertSame('falta', $r['bl']['estado']);
        // Varios renglones del mismo día se suman.
        $this->assertSame(5250, $r['bl']['produccion'][2]);
    }

    public function test_grupo_sin_movimientos_es_cubierto_y_respeta_inicial(): void
    {
        $r = CalculadoraBalance::calcular(
            ['x' => ['cultivo_id' => 1]],
            [],
            [],
            ['x' => 500]
        );

        $g = $r['x'];
        $this->assertSame([500, 500, 500, 500, 500, 500], $g['acumulado']);
        $this->assertSame([0, 0, 0, 0, 0, 0], $g['produccion']);
        $this->assertSame([0, 0, 0, 0, 0, 0], $g['necesidad']);
        $this->assertSame(500, $g['inicial']);
        $this->assertSame(500, $g['final']);
        $this->assertSame('cubierto', $g['estado']);
        $this->assertSame([], $g['produccion_por_temporada']);
    }

    public function test_sin_llave_en_inicial_se_trata_como_cero_y_la_falta_desde_el_dia_cero(): void
    {
        $r = CalculadoraBalance::calcular(
            ['a' => ['cultivo_id' => 1], 'b' => ['cultivo_id' => 1]],
            [],
            [['grupo' => 'a', 'dia' => 0, 'cajas' => 10]],
            []
        );

        $this->assertSame(0, $r['b']['inicial']);
        $this->assertSame('falta', $r['a']['estado']);
        $this->assertSame(0, $r['a']['primer_dia_falta']);
        $this->assertSame(0, $r['a']['peor_dia']);
        $this->assertSame(-10, $r['a']['peor_valor']);
    }

    public function test_el_inventario_inicial_cubre_la_falta_del_dia(): void
    {
        $r = CalculadoraBalance::calcular(
            ['a' => ['cultivo_id' => 1]],
            [],
            [['grupo' => 'a', 'dia' => 1, 'cajas' => 300]],
            ['a' => 400]
        );

        $this->assertSame([400, 100, 100, 100, 100, 100], $r['a']['acumulado']);
        // Del día negativo pero el acumulado nunca: se cubre con inventario.
        $this->assertSame('cubre_inventario', $r['a']['estado']);
    }

    public function test_empate_en_el_minimo_toma_el_primer_dia(): void
    {
        $r = CalculadoraBalance::calcular(
            ['a' => ['cultivo_id' => 1]],
            [['grupo' => 'a', 'temporada_id' => 1, 'dia' => 2, 'cajas' => 50]],
            [['grupo' => 'a', 'dia' => 0, 'cajas' => 100]],
            ['a' => 0]
        );

        // acumulado = [-100, -100, -50, -50, -50, -50]
        $this->assertSame(0, $r['a']['primer_dia_falta']);
        $this->assertSame(0, $r['a']['peor_dia']);
        $this->assertSame(-100, $r['a']['peor_valor']);
    }

    public function test_produccion_por_temporada_separa_aportes(): void
    {
        $produccion = array_merge(
            $this->produccion('a', [100, 0, 50, 0, 0, 10], 1),
            $this->produccion('a', [20, 30, 0, 0, 5, 0], 2),
            // Sin temporada: va bajo la llave 'sin_temporada'.
            $this->produccion('a', [1, 1, 1, 1, 1, 1], null)
        );

        $r = CalculadoraBalance::calcular(['a' => ['cultivo_id' => 1]], $produccion, [], ['a' => 0]);
        $pt = $r['a']['produccion_por_temporada'];

        $this->assertSame([100, 0, 50, 0, 0, 10], $pt[1]);
        $this->assertSame([20, 30, 0, 0, 5, 0], $pt[2]);
        $this->assertSame([1, 1, 1, 1, 1, 1], $pt['sin_temporada']);
        $this->assertSame([121, 31, 51, 1, 6, 11], $r['a']['produccion']);
    }

    public function test_las_claves_de_grupo_se_conservan_enteras_o_texto(): void
    {
        $r = CalculadoraBalance::calcular(
            [7 => ['cultivo_id' => 1], 'v12' => ['cultivo_id' => 1]],
            [['grupo' => 'v12', 'temporada_id' => 3, 'dia' => 0, 'cajas' => 5]],
            [['grupo' => 7, 'dia' => 5, 'cajas' => 9]],
            [7 => 100, 'v12' => 1]
        );

        $this->assertSame([7, 'v12'], array_keys($r));
        $this->assertSame(91, $r[7]['final']);
        $this->assertSame(6, $r['v12']['final']);
    }

    public function test_grupo_referenciado_pero_ausente_de_grupos_se_incluye(): void
    {
        $r = CalculadoraBalance::calcular(
            ['a' => ['cultivo_id' => 1]],
            [['grupo' => 'fantasma', 'temporada_id' => 1, 'dia' => 0, 'cajas' => 40]],
            [['grupo' => 'otro', 'dia' => 1, 'cajas' => 15]],
            ['fantasma' => 5]
        );

        $this->assertSame(['a', 'fantasma', 'otro'], array_keys($r));
        $this->assertSame(45, $r['fantasma']['final']);
        $this->assertSame(-15, $r['otro']['final']);
        $this->assertSame('falta', $r['otro']['estado']);
    }
}
