<?php

namespace Tests\Unit\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Recepcion;
use App\Services\Abastecimiento\CalculadoraLiquidacion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Modelos armados en memoria: la calculadora no toca la base de datos. */
class CalculadoraLiquidacionTest extends TestCase
{
    private function convenio(string $modalidad = 'compra_directa', string $unidad = 'caja', float $rezagaAceptada = 4, array $precios = []): Convenio
    {
        $convenio = new Convenio([
            'modalidad' => $modalidad,
            'unidad' => $unidad,
            'porcentaje_rezaga' => $rezagaAceptada,
        ]);
        $convenio->id = 1;
        $precios = $precios ?: [['precio' => 100, 'pct' => null, 'desde' => '2026-01-01']];
        $convenio->setRelation('precios', collect(array_map(fn (array $p) => new ConvenioPrecio([
            'convenio_id' => 1,
            'tipo_carga_id' => $p['tipo'] ?? 1,
            'precio' => $p['precio'],
            'porcentaje_productor' => $p['pct'],
            'vigencia_inicio' => $p['desde'],
        ]), $precios)));

        return $convenio;
    }

    private function recepcion(int $id, float $cantidad, ?float $kilos, ?float $rezaga, string $fecha = '2026-03-10', int $tipo = 1): Recepcion
    {
        $r = new Recepcion([
            'convenio_id' => 1,
            'fecha' => $fecha,
            'tipo_carga_id' => $tipo,
            'cantidad' => $cantidad,
            'kilos' => $kilos,
            'rezaga_kg' => $rezaga,
        ]);
        $r->id = $id;

        return $r;
    }

    private function dosRecepciones(): Collection
    {
        return collect([
            $this->recepcion(1, 10, 200, 30),
            $this->recepcion(2, 20, 400, 30),
        ]);
    }

    public function test_compra_directa_en_cajas_descuenta_la_rezaga_excedente(): void
    {
        $r = CalculadoraLiquidacion::calcular($this->convenio(), $this->dosRecepciones());

        $this->assertSame(30.0, $r['cantidad']);
        $this->assertSame(600.0, $r['kilos']);
        $this->assertSame(3000.0, $r['bruto']);
        $this->assertSame(10.0, $r['rezaga_real_pct']);
        $this->assertSame(4.0, $r['rezaga_aceptada_pct']);
        $this->assertSame(180.0, $r['descuento']);
        $this->assertSame(2820.0, $r['monto_calculado']);
        $this->assertSame(2820.0, $r['monto_final']);
        $this->assertNull($r['monto_ajustado']);
        $this->assertNull($r['motivo_ajuste']);

        $this->assertCount(2, $r['detalles']);
        $d = $r['detalles'][0];
        $this->assertSame(1, $d['recepcion_id']);
        $this->assertSame(10.0, $d['cantidad']);
        $this->assertSame(200.0, $d['kilos']);
        $this->assertSame(30.0, $d['rezaga_kg']);
        $this->assertSame(100.0, $d['precio']);
        $this->assertSame(100.0, $d['porcentaje_productor']);
        $this->assertSame(1000.0, $d['subtotal']);
        $this->assertSame(60.0, $d['descuento']);
        $this->assertSame(940.0, $d['a_pagar']);
        $this->assertSame(2000.0, $r['detalles'][1]['subtotal']);
        $this->assertSame(1880.0, $r['detalles'][1]['a_pagar']);
    }

    public function test_consignacion_descuenta_primero_y_aplica_el_porcentaje_despues(): void
    {
        $convenio = $this->convenio('consignacion', precios: [['precio' => 100, 'pct' => 70, 'desde' => '2026-01-01']]);

        $r = CalculadoraLiquidacion::calcular($convenio, $this->dosRecepciones());

        $this->assertSame(658.0, $r['detalles'][0]['a_pagar']);
        $this->assertSame(1316.0, $r['detalles'][1]['a_pagar']);
        $this->assertSame(70.0, $r['detalles'][0]['porcentaje_productor']);
        $this->assertSame(180.0, $r['descuento']);
        $this->assertSame(1974.0, $r['monto_calculado']);
    }

    public function test_convenio_por_kilo_multiplica_los_kilos_por_el_precio(): void
    {
        $convenio = $this->convenio('compra_directa', 'kg', 0, [['precio' => 12.5, 'pct' => null, 'desde' => '2026-01-01']]);
        $recepciones = collect([$this->recepcion(1, 10, 200, 0), $this->recepcion(2, 20, 400, 0)]);

        $r = CalculadoraLiquidacion::calcular($convenio, $recepciones);

        $this->assertSame(2500.0, $r['detalles'][0]['subtotal']);
        $this->assertSame(5000.0, $r['detalles'][1]['subtotal']);
        $this->assertSame(7500.0, $r['bruto']);
        $this->assertSame(7500.0, $r['monto_calculado']);
    }

    public function test_sin_kilos_no_hay_rezaga_ni_division_entre_cero(): void
    {
        $sinKilos = collect([$this->recepcion(1, 10, null, null), $this->recepcion(2, 20, null, null)]);
        $r = CalculadoraLiquidacion::calcular($this->convenio(), $sinKilos);
        $this->assertSame(0.0, $r['rezaga_real_pct']);
        $this->assertSame(0.0, $r['descuento']);
        $this->assertSame(3000.0, $r['monto_calculado']);
        $this->assertSame(0.0, $r['kilos']);

        $enCero = collect([$this->recepcion(1, 10, 0, 0), $this->recepcion(2, 20, 0, 5)]);
        $r = CalculadoraLiquidacion::calcular($this->convenio(), $enCero);
        $this->assertSame(0.0, $r['rezaga_real_pct']);
        $this->assertSame(0.0, $r['descuento']);
    }

    public function test_la_rezaga_solo_cuenta_las_recepciones_con_kilos(): void
    {
        // La recepción sin kilos no diluye el porcentaje: 30 / 200 = 15 %.
        $r = CalculadoraLiquidacion::calcular($this->convenio(), collect([
            $this->recepcion(1, 10, 200, 30),
            $this->recepcion(2, 20, null, null),
        ]));

        $this->assertSame(15.0, $r['rezaga_real_pct']);
    }

    public function test_rezaga_menor_a_la_aceptada_no_descuenta(): void
    {
        $r = CalculadoraLiquidacion::calcular($this->convenio(rezagaAceptada: 12), $this->dosRecepciones());

        $this->assertSame(10.0, $r['rezaga_real_pct']);
        $this->assertSame(0.0, $r['descuento']);
        $this->assertSame(3000.0, $r['monto_calculado']);
    }

    public function test_el_monto_ajustado_manda_y_exige_motivo(): void
    {
        $r = CalculadoraLiquidacion::calcular($this->convenio(), $this->dosRecepciones(), 2500.0, 'Acuerdo con el productor');

        $this->assertSame(2820.0, $r['monto_calculado']);
        $this->assertSame(2500.0, $r['monto_ajustado']);
        $this->assertSame('Acuerdo con el productor', $r['motivo_ajuste']);
        $this->assertSame(2500.0, $r['monto_final']);

        foreach ([null, '', '   '] as $motivo) {
            try {
                CalculadoraLiquidacion::calcular($this->convenio(), $this->dosRecepciones(), 2500.0, $motivo);
                $this->fail('Debió exigir el motivo del ajuste.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('motivo_ajuste', $e->errors());
            }
        }
    }

    public function test_cada_renglon_usa_el_precio_vigente_a_su_fecha(): void
    {
        $convenio = $this->convenio(precios: [
            ['precio' => 100, 'pct' => null, 'desde' => '2026-01-01'],
            ['precio' => 120, 'pct' => null, 'desde' => '2026-03-15'],
            ['precio' => 50, 'pct' => null, 'desde' => '2026-01-01', 'tipo' => 2],
        ]);
        $r = CalculadoraLiquidacion::calcular($convenio, collect([
            $this->recepcion(1, 10, null, null, '2026-03-14'),
            $this->recepcion(2, 10, null, null, '2026-03-15'),
            $this->recepcion(3, 10, null, null, '2026-03-20', 2),
        ]));

        $this->assertSame([100.0, 120.0, 50.0], array_column($r['detalles'], 'precio'));
        $this->assertSame([1000.0, 1200.0, 500.0], array_column($r['detalles'], 'subtotal'));
    }

    public function test_recepcion_sin_precio_vigente_da_422_en_recepcion_ids(): void
    {
        $convenio = $this->convenio(precios: [['precio' => 100, 'pct' => null, 'desde' => '2026-03-01']]);

        try {
            CalculadoraLiquidacion::calcular($convenio, collect([$this->recepcion(1, 10, null, null, '2026-02-28')]));
            $this->fail('Debió fallar por falta de precio vigente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recepcion_ids', $e->errors());
        }
    }

    public function test_los_renglones_suman_exactamente_el_monto_calculado(): void
    {
        // Subtotales de 33.33 con descuento y 70 %: cada renglón redondea y los totales son su suma.
        $convenio = $this->convenio('consignacion', 'caja', 1, [['precio' => 3.333, 'pct' => 70, 'desde' => '2026-01-01']]);
        $r = CalculadoraLiquidacion::calcular($convenio, collect([
            $this->recepcion(1, 10, 100, 7),
            $this->recepcion(2, 10, 100, 7),
            $this->recepcion(3, 10, 100, 7),
        ]));

        $this->assertSame(round(array_sum(array_column($r['detalles'], 'a_pagar')), 2), $r['monto_calculado']);
        $this->assertSame(round(array_sum(array_column($r['detalles'], 'subtotal')), 2), $r['bruto']);
        $this->assertSame(round(array_sum(array_column($r['detalles'], 'descuento')), 2), $r['descuento']);
        foreach ($r['detalles'] as $d) {
            $this->assertSame(round($d['a_pagar'], 2), $d['a_pagar']);
        }
        // 33.33 - 2.00 (6 % de 33.33) = 31.33 x 70 % = 21.93, por tres.
        $this->assertSame(21.93, $r['detalles'][0]['a_pagar']);
        $this->assertSame(65.79, $r['monto_calculado']);
    }
}
