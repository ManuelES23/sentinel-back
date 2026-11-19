<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/** `GET liquidaciones/cuentas/{convenio}/estado-cuenta`: movimientos de la cuenta con saldo corrido (spec 6 y 5.3). */
class EstadoCuentaConvenioTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function base(): string
    {
        return '/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/cuentas';
    }

    private function estado(Convenio $convenio): array
    {
        return $this->getJson($this->base().'/'.$convenio->id.'/estado-cuenta')->assertOk()->json('data');
    }

    private function porPagar(Convenio $convenio): float
    {
        return (float) $this->getJson($this->base().'/'.$convenio->id)->assertOk()->json('data.totales.por_pagar');
    }

    /** Convenio clásico (100 por caja) con $n recepciones de 10 cajas (1,000 c/u) en 06-01, 06-02… */
    private function convenioCon(int $n = 2): array
    {
        $convenio = $this->crearConvenio($this->sbp);
        $recepciones = [];
        for ($i = 0; $i < $n; $i++) {
            $recepciones[] = $this->crearRecepcion($convenio, ['fecha' => '2026-06-0'.($i + 1)]);
        }

        return [$convenio, $recepciones];
    }

    /** @return list<float> */
    private function saldos(array $estado): array
    {
        return array_column($estado['movimientos'], 'saldo');
    }

    public function test_saldo_corrido_cuadra_con_por_pagar_abierta(): void
    {
        [$convenio, [$r1, $r2]] = $this->convenioCon(2);
        $this->crearPago($convenio, ['fecha' => '2026-06-05'], [$r1->id => 400]);
        $this->crearPago($convenio, ['fecha' => '2026-06-10', 'tipo' => 'abono', 'monto' => 100]);
        $this->crearPago($convenio, ['fecha' => '2026-06-12', 'tipo' => 'adelanto', 'monto' => 50]);
        $this->crearPago($convenio, ['fecha' => '2026-05-30', 'tipo' => 'saldo_inicial', 'monto' => 250, 'notas' => 'Deuda de la temporada pasada']);

        $e = $this->estado($convenio);

        $this->assertSame(
            ['saldo_inicial', 'recepcion', 'recepcion', 'pago', 'pago', 'pago'],
            array_column($e['movimientos'], 'tipo'),
        );
        $this->assertEqualsWithDelta(0, $e['movimientos'][0]['abono'], 0.001);
        $this->assertEqualsWithDelta(250, $e['movimientos'][0]['cargo'], 0.001);
        $this->assertSame('Deuda de la temporada pasada', $e['movimientos'][0]['concepto']);
        $this->assertEquals([250.0, 1250.0, 2250.0, 1850.0, 1750.0, 1700.0], $this->saldos($e));
        $this->assertEqualsWithDelta(1700, $e['saldo_final'], 0.001);
        $this->assertEqualsWithDelta($this->porPagar($convenio), $e['saldo_final'], 0.001);
        $this->assertSame('abierta', $e['estado']);
        $this->assertEqualsWithDelta(1700, $e['totales']['por_pagar'], 0.001);
        $this->assertSame(['valor_recibido', 'pagado', 'cargos_iniciales', 'por_pagar'], array_keys($e['totales']));
    }

    public function test_forma_del_json_y_conceptos(): void
    {
        [$convenio, [$r1, $r2]] = $this->convenioCon(2);
        $tipoCarga = TipoCarga::where('enterprise_id', $this->sbp->id)->orderBy('id')->firstOrFail()->nombre;
        $p1 = $this->crearPago($convenio, ['fecha' => '2026-06-05'], [$r1->id => 400, $r2->id => 100]);
        $this->crearPago($convenio, ['fecha' => '2026-06-06', 'tipo' => 'abono']);
        $this->crearPago($convenio, ['fecha' => '2026-06-07', 'tipo' => 'adelanto']);

        $e = $this->estado($convenio);

        $this->assertSame(
            ['convenio', 'productor', 'moneda', 'estado', 'totales', 'movimientos', 'saldo_final', 'empresa'],
            array_keys($e),
        );
        $this->assertSame($convenio->id, $e['convenio']['id']);
        $this->assertSame($convenio->folio, $e['convenio']['folio']);
        $this->assertSame(
            ['id', 'nombre', 'tipo', 'rfc', 'contacto', 'telefono', 'email', 'ubicacion'],
            array_keys($e['productor']),
        );
        $this->assertSame(
            ['id', 'nombre', 'razon_social', 'rfc', 'direccion', 'ciudad', 'pais', 'telefono'],
            array_keys($e['empresa']),
        );
        $this->assertSame($this->sbp->id, $e['empresa']['id']);
        $this->assertSame('Splendid by Porvenir', $e['empresa']['nombre']);
        $this->assertSame('MXN', $e['moneda']);

        $m = $e['movimientos'];
        $this->assertSame(
            ['tipo', 'fecha', 'folio', 'concepto', 'cargo', 'abono', 'saldo', 'cancelado'],
            array_keys($m[0]),
        );
        $this->assertSame('recepcion', $m[0]['tipo']);
        $this->assertSame('2026-06-01', $m[0]['fecha']);
        $this->assertSame($r1->folio, $m[0]['folio']);
        $this->assertSame('Recepción: '.$tipoCarga, $m[0]['concepto']);
        $this->assertEqualsWithDelta(1000, $m[0]['cargo'], 0.001);
        $this->assertEqualsWithDelta(0, $m[0]['abono'], 0.001);
        $this->assertFalse($m[0]['cancelado']);
        $this->assertSame($p1->folio, $m[2]['folio']);
        $this->assertSame('Pago por recepción: '.$r1->folio.', '.$r2->folio, $m[2]['concepto']);
        $this->assertEqualsWithDelta(500, $m[2]['abono'], 0.001);
        $this->assertSame('Abono', $m[3]['concepto']);
        $this->assertSame('Adelanto', $m[4]['concepto']);
    }

    public function test_recepcion_por_calibre_lista_los_calibres_en_el_concepto(): void
    {
        $variedad = Variedad::orderBy('id')->firstOrFail();
        $a = $this->crearCalibre($variedad, 'Extra');
        $b = $this->crearCalibre($variedad, 'Primera');
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $a, 'volumen' => 50, 'precio' => 110],
            ['calibre' => $b, 'volumen' => 50, 'precio' => 90],
        ], ['variedad_id' => $variedad->id]);
        $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $a->id, 'cantidad' => 10],
            ['calibre_id' => $b->id, 'cantidad' => 10],
        ]);

        $e = $this->estado($convenio);

        $this->assertSame('Recepción: Extra, Primera', $e['movimientos'][0]['concepto']);
        $this->assertEqualsWithDelta(2000, $e['movimientos'][0]['cargo'], 0.001);
        $this->assertEqualsWithDelta(2000, $e['saldo_final'], 0.001);
    }

    public function test_liquidada_agrega_el_ajuste_y_cuadra_con_monto_final_menos_pagado(): void
    {
        // Restante: recepciones 2,000 + saldo inicial 50, liquidada en 1,900 (rezaga), pagado 600 → 1,300.
        [$convenio, [$r1]] = $this->convenioCon(2);
        $this->crearPago($convenio, ['fecha' => '2026-06-05', 'tipo' => 'saldo_inicial', 'monto' => 50, 'notas' => 'Saldo del año pasado']);
        $this->crearPago($convenio, ['fecha' => '2026-06-06'], [$r1->id => 600]);
        $liq = $this->crearLiquidacion($convenio, ['monto_final' => 1900, 'liquidada_at' => '2026-06-20 12:00:00']);

        $e = $this->estado($convenio);

        $this->assertSame('liquidada', $e['estado']);
        $ajuste = collect($e['movimientos'])->firstWhere('tipo', 'ajuste_liquidacion');
        $this->assertSame('2026-06-20', $ajuste['fecha']);
        $this->assertSame($liq->folio, $ajuste['folio']);
        $this->assertSame("Liquidación {$liq->folio}: rezaga y ajustes", $ajuste['concepto']);
        $this->assertEqualsWithDelta(0, $ajuste['cargo'], 0.001);
        $this->assertEqualsWithDelta(150, $ajuste['abono'], 0.001);
        $this->assertSame('ajuste_liquidacion', end($e['movimientos'])['tipo']);
        $this->assertEqualsWithDelta(1300, $e['saldo_final'], 0.001);
        $this->assertEqualsWithDelta(1900 - 600, $e['saldo_final'], 0.001);
        $this->assertEqualsWithDelta($this->porPagar($convenio), $e['saldo_final'], 0.001);
        $this->assertEqualsWithDelta(end($e['movimientos'])['saldo'], $e['saldo_final'], 0.001);
    }

    public function test_liquidada_con_ajuste_positivo_es_cargo(): void
    {
        [$convenio] = $this->convenioCon(1);
        $this->crearLiquidacion($convenio, ['monto_final' => 1100]);

        $e = $this->estado($convenio);

        $ajuste = collect($e['movimientos'])->firstWhere('tipo', 'ajuste_liquidacion');
        $this->assertEqualsWithDelta(100, $ajuste['cargo'], 0.001);
        $this->assertEqualsWithDelta(0, $ajuste['abono'], 0.001);
        $this->assertEqualsWithDelta(1100, $e['saldo_final'], 0.001);
        $this->assertEqualsWithDelta($this->porPagar($convenio), $e['saldo_final'], 0.001);
    }

    public function test_liquidada_sin_diferencia_no_agrega_ajuste(): void
    {
        [$convenio] = $this->convenioCon(1);
        $this->crearLiquidacion($convenio, ['monto_final' => 1000]);

        $e = $this->estado($convenio);

        $this->assertSame(['recepcion'], array_column($e['movimientos'], 'tipo'));
        $this->assertEqualsWithDelta(1000, $e['saldo_final'], 0.001);
    }

    public function test_liquidada_con_pagado_de_mas_deja_saldo_negativo(): void
    {
        [$convenio, [$r1]] = $this->convenioCon(1);
        $this->crearPago($convenio, ['fecha' => '2026-06-05'], [$r1->id => 700]);
        $this->crearPago($convenio, ['fecha' => '2026-06-06', 'tipo' => 'adelanto', 'monto' => 200]);
        $this->crearLiquidacion($convenio, ['monto_final' => 800, 'liquidada_at' => '2026-06-20 12:00:00']);

        $e = $this->estado($convenio);

        $this->assertEqualsWithDelta(-100, $e['saldo_final'], 0.001);
        $this->assertEqualsWithDelta(-100, $this->porPagar($convenio), 0.001);
        $this->assertEqualsWithDelta(-100, end($e['movimientos'])['saldo'], 0.001);
    }

    public function test_cancelados_se_ven_pero_no_mueven_el_saldo(): void
    {
        [$convenio, [$r1]] = $this->convenioCon(1);
        $this->crearPago($convenio, ['fecha' => '2026-06-05', 'status' => 'cancelado', 'monto' => 300, 'cancelado_motivo' => 'Error']);
        $this->crearPago($convenio, ['fecha' => '2026-06-06'], [$r1->id => 200]);
        $this->crearPago($convenio, ['fecha' => '2026-06-07', 'tipo' => 'saldo_inicial', 'status' => 'cancelado', 'monto' => 90, 'notas' => 'Cargo equivocado']);

        $e = $this->estado($convenio);

        $m = $e['movimientos'];
        $this->assertSame([false, true, false, true], array_column($m, 'cancelado'));
        $this->assertEqualsWithDelta(300, $m[1]['abono'], 0.001);
        $this->assertEquals([1000.0, 1000.0, 800.0, 800.0], $this->saldos($e));
        $this->assertEqualsWithDelta(800, $e['saldo_final'], 0.001);
        $this->assertEqualsWithDelta($this->porPagar($convenio), $e['saldo_final'], 0.001);
    }

    public function test_orden_cargos_antes_que_abonos_a_igual_fecha(): void
    {
        [$convenio] = $this->convenioCon(1);
        // El abono se crea antes (id menor) y trae la misma fecha que la recepción y el saldo inicial.
        $this->crearPago($convenio, ['fecha' => '2026-06-01', 'monto' => 100]);
        $this->crearPago($convenio, ['fecha' => '2026-06-01', 'tipo' => 'saldo_inicial', 'monto' => 50, 'notas' => 'Saldo viejo de prueba']);

        $e = $this->estado($convenio);

        $this->assertSame(['recepcion', 'saldo_inicial', 'pago'], array_column($e['movimientos'], 'tipo'));
        $this->assertEquals([1000.0, 1050.0, 950.0], $this->saldos($e));
    }

    public function test_sin_view_403_y_otra_empresa_404(): void
    {
        [$convenio] = $this->convenioCon(1);
        $deOtra = $this->crearConvenio($this->otra);
        $this->crearRecepcion($deOtra);
        Sanctum::actingAs($this->captor);
        $url = fn (Convenio $c) => $this->base().'/'.$c->id.'/estado-cuenta';

        $this->getJson($url($convenio))->assertForbidden();
        $this->otorgarAbs($this->captor, $this->sbp, 'convenios', ['view']);
        $this->getJson($url($convenio))->assertForbidden();

        $this->otorgarAbs($this->captor, $this->sbp, 'liquidaciones', ['view']);
        $this->getJson($url($convenio))->assertOk();
        $this->getJson($url($deOtra))->assertNotFound();
        $this->getJson($this->base().'/999999/estado-cuenta')->assertNotFound();
    }

    public function test_el_numero_de_consultas_no_crece_con_recepciones_ni_pagos(): void
    {
        $variedad = Variedad::orderBy('id')->firstOrFail();
        $calibre = $this->crearCalibre($variedad, 'Extra');
        $convenio = $this->crearConvenioPorCalibre(
            $this->sbp,
            [['calibre' => $calibre, 'volumen' => 500, 'precio' => 110]],
            ['variedad_id' => $variedad->id],
        );
        $agregar = function (int $n) use ($convenio, $calibre) {
            for ($i = 0; $i < $n; $i++) {
                $r = $this->crearRecepcionConRenglones($convenio, [['calibre_id' => $calibre->id, 'cantidad' => 4]]);
                $this->crearPago($convenio, ['fecha' => '2026-06-10'], [$r->id => 100]);
                $this->crearPago($convenio, ['fecha' => '2026-06-11', 'tipo' => 'abono', 'monto' => 10]);
            }
        };
        $consultas = function () use ($convenio): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->base().'/'.$convenio->id.'/estado-cuenta')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $agregar(1);
        $con1 = $consultas();
        $agregar(5);
        $con6 = $consultas();

        $this->assertCount(18, $this->estado($convenio)['movimientos']);
        $this->assertSame($con1, $con6, 'El número de consultas no debe crecer con las recepciones ni los pagos');
    }
}
