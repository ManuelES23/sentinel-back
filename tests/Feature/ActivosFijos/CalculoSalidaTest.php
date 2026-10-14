<?php

namespace Tests\Feature\ActivosFijos;

use App\Services\ActivosFijos\CalculadorSalida;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\TestCase;

class CalculoSalidaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;

    private CalculadorSalida $calculador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->calculador = app(CalculadorSalida::class);
    }

    public function test_valores_a_una_fecha_con_depreciacion(): void
    {
        // 12,000 de compra, 20 % anual, 10 % residual, desde enero de 2026: 180.00 al mes.
        $activo = $this->crearActivoDepreciable();

        $v = $this->calculador->valores($activo, CarbonImmutable::parse('2026-05-20'));

        $this->assertSame('12000.00', $v['purchase_value']);
        $this->assertSame('900.00', $v['accumulated_depreciation']); // cinco cuotas, mayo completo
        $this->assertSame('11100.00', $v['book_value']);
        $this->assertSame([], $v['warnings']);
    }

    public function test_antes_del_inicio_no_hay_depreciacion_y_el_libro_es_el_valor_de_compra(): void
    {
        $activo = $this->crearActivoDepreciable();

        $v = $this->calculador->valores($activo, CarbonImmutable::parse('2025-12-10'));

        $this->assertSame('0.00', $v['accumulated_depreciation']);
        $this->assertSame('12000.00', $v['book_value']);
    }

    public function test_totalmente_depreciado_deja_el_valor_residual(): void
    {
        $activo = $this->crearActivoDepreciable();

        $v = $this->calculador->valores($activo, CarbonImmutable::parse('2031-01-10'));

        $this->assertSame('10800.00', $v['accumulated_depreciation']);
        $this->assertSame('1200.00', $v['book_value']);
    }

    public function test_sin_tasa_el_libro_es_el_valor_de_compra_con_advertencia(): void
    {
        $activo = $this->crearActivoDepreciable(['depreciation_rate' => null, 'salvage_percent' => null]);

        $v = $this->calculador->valores($activo, CarbonImmutable::parse('2026-05-20'));

        $this->assertSame('12000.00', $v['purchase_value']);
        $this->assertSame('0.00', $v['accumulated_depreciation']);
        $this->assertSame('12000.00', $v['book_value']);
        $this->assertSame('sin_tasa', $v['warnings'][0]['code']);
    }

    public function test_sin_valor_de_compra_el_libro_es_cero_con_advertencia(): void
    {
        $activo = $this->crearActivoDepreciable(['purchase_value' => null]);

        $v = $this->calculador->valores($activo, CarbonImmutable::parse('2026-05-20'));

        $this->assertNull($v['purchase_value']);
        $this->assertSame('0.00', $v['accumulated_depreciation']);
        $this->assertSame('0.00', $v['book_value']);
        $this->assertSame('sin_valor', $v['warnings'][0]['code']);
    }

    public function test_sin_fecha_el_libro_es_el_valor_de_compra_con_advertencia(): void
    {
        $activo = $this->crearActivoDepreciable(['purchase_date' => null]);

        $v = $this->calculador->valores($activo, CarbonImmutable::parse('2026-05-20'));

        $this->assertSame('12000.00', $v['book_value']);
        $this->assertSame('sin_fecha', $v['warnings'][0]['code']);
    }

    public function test_resultado_de_cada_tipo_de_salida(): void
    {
        $this->assertSame('3900.00', $this->calculador->resultado('venta', '11100.00', '15000.00'));
        $this->assertSame('-1100.00', $this->calculador->resultado('venta', '11100.00', '10000.00'));
        $this->assertSame('-11100.00', $this->calculador->resultado('desecho', '11100.00', null));
        $this->assertSame('-11100.00', $this->calculador->resultado('siniestro', '11100.00', null));
        $this->assertSame('-11100.00', $this->calculador->resultado('donacion', '11100.00', null));
        $this->assertSame('0.00', $this->calculador->resultado('traspaso', '11100.00', null));
    }

    public function test_el_resultado_es_exacto_al_centavo(): void
    {
        $this->assertSame('-0.01', $this->calculador->resultado('venta', '0.01', '0.00'));
        $this->assertSame('0.10', $this->calculador->resultado('venta', '100.10', '100.20'));
        $this->assertSame('0.00', $this->calculador->resultado('venta', '100.10', '100.10'));
    }

    public function test_tasa_y_residual_efectivos(): void
    {
        $this->assertSame([20.0, 10.0], $this->calculador->tasaYResidual($this->crearActivoDepreciable()));
        $this->assertSame([null, null], $this->calculador->tasaYResidual($this->crearActivoDepreciable(['purchase_value' => null])));
    }
}
