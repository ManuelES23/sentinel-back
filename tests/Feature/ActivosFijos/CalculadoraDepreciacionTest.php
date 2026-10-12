<?php

namespace Tests\Feature\ActivosFijos;

use App\Services\ActivosFijos\CalculadoraDepreciacion;
use App\Services\ActivosFijos\DatosDepreciacion;
use App\Services\ActivosFijos\ResultadoDepreciacion;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalculadoraDepreciacionTest extends TestCase
{
    private CalculadoraDepreciacion $calculadora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculadora = new CalculadoraDepreciacion();
    }

    private function datos(float $valor = 12000, float $tasa = 20, float $residual = 0, string $inicio = '2026-01-01'): DatosDepreciacion
    {
        return DatosDepreciacion::crear($valor, $tasa, $residual, Carbon::parse($inicio));
    }

    private function mes(string $anioMes): Carbon
    {
        return Carbon::parse($anioMes . '-01');
    }

    public function test_primer_mes_sin_residual(): void
    {
        $r = $this->calculadora->calcular($this->datos(), $this->mes('2026-01'));

        $this->assertSame(ResultadoDepreciacion::DEPRECIANDO, $r->estado);
        $this->assertSame(1, $r->mesesTranscurridos);
        $this->assertSame('200.00', $r->cuota());
        $this->assertSame('200.00', $r->acumulado());
        $this->assertSame('11800.00', $r->valorEnLibros());
    }

    public function test_con_residual_la_base_es_menor(): void
    {
        // 12,000 con 10 % de residual: base 10,800 en 60 meses = 180 al mes.
        $datos = $this->datos(12000, 20, 10);
        $this->assertSame(1080000, $datos->baseCentavos());
        $this->assertSame(60, $datos->vidaMeses());

        $r = $this->calculadora->calcular($datos, $this->mes('2026-02'));

        $this->assertSame('180.00', $r->cuota());
        $this->assertSame('360.00', $r->acumulado());
        $this->assertSame('11640.00', $r->valorEnLibros());
    }

    public function test_antes_del_inicio_no_hay_depreciacion(): void
    {
        $r = $this->calculadora->calcular($this->datos(inicio: '2026-05-01'), $this->mes('2026-04'));

        $this->assertSame(ResultadoDepreciacion::POR_INICIAR, $r->estado);
        $this->assertSame('0.00', $r->cuota());
        $this->assertSame('0.00', $r->acumulado());
        $this->assertSame('12000.00', $r->valorEnLibros());
    }

    public function test_el_mes_de_inicio_cuenta_completo(): void
    {
        $r = $this->calculadora->calcular($this->datos(inicio: '2026-05-20'), $this->mes('2026-05'));

        $this->assertSame(ResultadoDepreciacion::DEPRECIANDO, $r->estado);
        $this->assertSame(1, $r->mesesTranscurridos);
    }

    public function test_el_ultimo_mes_cierra_en_la_base_y_despues_la_cuota_es_cero(): void
    {
        $datos = $this->datos(12000, 20, 10);

        $ultimo = $this->calculadora->calcular($datos, $this->mes('2030-12')); // mes 60
        $this->assertSame(ResultadoDepreciacion::TOTALMENTE_DEPRECIADO, $ultimo->estado);
        $this->assertSame('180.00', $ultimo->cuota());
        $this->assertSame('10800.00', $ultimo->acumulado());
        $this->assertSame('1200.00', $ultimo->valorEnLibros());

        $despues = $this->calculadora->calcular($datos, $this->mes('2031-01'));
        $this->assertSame(ResultadoDepreciacion::TOTALMENTE_DEPRECIADO, $despues->estado);
        $this->assertSame('0.00', $despues->cuota());
        $this->assertSame('10800.00', $despues->acumulado());
        $this->assertSame('1200.00', $despues->valorEnLibros());
    }

    public function test_tasa_que_no_divide_exacto_suma_exactamente_la_base(): void
    {
        // 33.33 % → 36 meses; 10,000 / 36 no es exacto en centavos.
        $datos = $this->datos(10000, 33.33, 0);
        $this->assertSame(36, $datos->vidaMeses());

        $this->assertSame('277.78', $this->calculadora->calcular($datos, $this->mes('2026-01'))->acumulado());
        $this->assertSame('555.56', $this->calculadora->calcular($datos, $this->mes('2026-02'))->acumulado());

        $suma = 0;
        for ($i = 0; $i < 40; $i++) {
            $suma += $this->calculadora->calcular($datos, $this->mes('2026-01')->addMonths($i))->cuotaCentavos;
        }
        $this->assertSame(1000000, $suma);
    }

    public function test_la_vida_util_sale_de_la_tasa(): void
    {
        $this->assertSame(12, $this->datos(tasa: 100)->vidaMeses());
        $this->assertSame(48, $this->datos(tasa: 25)->vidaMeses());
        $this->assertSame(120, $this->datos(tasa: 10)->vidaMeses());
        $this->assertSame(240, $this->datos(tasa: 5)->vidaMeses());
        $this->assertSame(2400, $this->datos(tasa: 0.5)->vidaMeses());
    }

    public function test_el_inicio_se_normaliza_al_primer_dia_del_mes(): void
    {
        $datos = $this->datos(inicio: '2026-03-31');

        $this->assertSame('2026-03-01', $datos->inicio->toDateString());
    }

    public function test_redondea_a_centavos(): void
    {
        // 1,000.05 al 12 % anual = 100 meses: 10.0005 al mes.
        $datos = DatosDepreciacion::crear('1000.05', 12, 0, Carbon::parse('2026-01-01'));

        $this->assertSame('10.00', $this->calculadora->calcular($datos, $this->mes('2026-01'))->acumulado());
        $this->assertSame('1000.05', $this->calculadora->calcular($datos, $this->mes('2034-04'))->acumulado()); // mes 100
    }
}
