<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\Variedad;
use App\Services\Abastecimiento\CuentaConvenio;
use App\Services\Abastecimiento\SaldoProductor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Cuenta por convenio (spec 5.3): valor recibido (valor a cuenta vivo, sin rezaga), pagado (sin saldo inicial),
 * cargos iniciales y por pagar; y `SaldoProductor` sumando las cuentas por productor y moneda.
 */
class SaldoProductorTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    // ---------------------------------------------------------------- ayudas

    /**
     * Pago de un tipo a la cuenta. Con `$recepciones` (recepcion_id => monto) es un pago por recepción.
     *
     * @param  array<int, float|int>|null  $recepciones
     */
    private function pagar(Convenio $convenio, string $tipo, float $monto, ?array $recepciones = null, array $over = []): Abono
    {
        return $this->crearPago($convenio, array_merge(['tipo' => $tipo, 'monto' => $monto], $over), $recepciones ?? []);
    }

    private function totales(Convenio $convenio): array
    {
        return CuentaConvenio::totales(new EloquentCollection([Convenio::findOrFail($convenio->id)]))[$convenio->id];
    }

    /** @return array<int, array> recepcion_id => detalle */
    private function detalle(Convenio $convenio): array
    {
        return array_column(CuentaConvenio::recepciones(Convenio::findOrFail($convenio->id)), null, 'id');
    }

    private function saldo(Productor|int $productor, ?int $temporadaId = null): array
    {
        $id = $productor instanceof Productor ? $productor->id : $productor;

        return SaldoProductor::porProductores($this->sbp, [$id], $temporadaId)->get($id, []);
    }

    private function productorNuevo(string $nombre): Productor
    {
        return Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => $nombre, 'tipo' => 'productor', 'is_active' => true]);
    }

    /** Convenio clásico (100 por caja) con dos recepciones de valor 1000 y 500. */
    private function conDosRecepciones(array $over = []): array
    {
        $convenio = $this->crearConvenio($this->sbp, $over);
        $r1 = $this->crearRecepcion($convenio, ['cantidad' => 10]);
        $r2 = $this->crearRecepcion($convenio, ['cantidad' => 5, 'fecha' => '2026-06-02']);

        return [$convenio, $r1, $r2];
    }

    private function cambiarPrecio(Convenio $convenio, float $precio): void
    {
        ConvenioPrecio::where('convenio_id', $convenio->id)->update(['precio' => $precio]);
    }

    // ---------------------------------------------------------------- cuenta del convenio

    public function test_cuenta_abierta_por_pagar_es_valor_mas_cargos_menos_pagado(): void
    {
        [$convenio, $r1, $r2] = $this->conDosRecepciones();
        $pago = $this->pagar($convenio, 'recepcion', 600, [$r1->id => 600]);
        $this->pagar($convenio, 'adelanto', 100);
        $this->pagar($convenio, 'saldo_inicial', 50);

        $t = $this->totales($convenio);
        $this->assertSame([
            'moneda' => 'MXN',
            'valor_recibido' => 1500.0,
            'pagado' => 700.0,
            'cargos_iniciales' => 50.0,
            'por_pagar' => 850.0,
            'estado' => 'abierta',
            'liquidacion_id' => null,
            'sin_valor' => 0,
            'recepciones' => 2,
        ], $t);

        $d = $this->detalle($convenio);
        $this->assertSame([$r1->id, $r2->id], array_keys($d));
        $this->assertSame(1000.0, $d[$r1->id]['valor']);
        $this->assertSame(600.0, $d[$r1->id]['pagado']);
        $this->assertSame(400.0, $d[$r1->id]['falta']);
        $this->assertSame(0.0, $d[$r1->id]['de_mas']);
        $this->assertSame('parcial', $d[$r1->id]['estado']);
        $this->assertNull($d[$r1->id]['motivo']);
        $this->assertSame([$pago->folio], $d[$r1->id]['pagos']);
        $this->assertSame(100.0, $d[$r1->id]['precio']);
        $this->assertSame([], $d[$r1->id]['renglones']);
        $this->assertSame($r1->folio, $d[$r1->id]['folio']);
        $this->assertSame('2026-06-01', $d[$r1->id]['fecha']);
        $this->assertSame((int) $r1->tipo_carga_id, $d[$r1->id]['tipo_carga']['id']);
        $this->assertSame(10.0, $d[$r1->id]['cantidad']);

        $this->assertSame(500.0, $d[$r2->id]['valor']);
        $this->assertSame(0.0, $d[$r2->id]['pagado']);
        $this->assertSame(500.0, $d[$r2->id]['falta']);
        $this->assertSame('pendiente', $d[$r2->id]['estado']);
        $this->assertSame([], $d[$r2->id]['pagos']);

        // El saldo del productor es la misma cuenta.
        $mxn = $this->saldo($convenio->productor_id)['MXN'];
        $this->assertSame(['moneda' => 'MXN', 'valor_recibido' => 1500.0, 'pagado' => 700.0, 'cargos_iniciales' => 50.0, 'por_pagar' => 850.0], $mxn);
    }

    public function test_pagos_cancelados_no_cuentan(): void
    {
        [$convenio, $r1] = $this->conDosRecepciones();
        $cancelado = ['status' => 'cancelado', 'cancelado_motivo' => 'Capturado por error', 'cancelado_at' => now()];
        $this->pagar($convenio, 'recepcion', 1000, [$r1->id => 1000], $cancelado);
        $this->pagar($convenio, 'adelanto', 300, null, $cancelado);
        $this->pagar($convenio, 'saldo_inicial', 80, null, $cancelado);
        // Un pago borrado (soft delete) tampoco cuenta.
        $this->pagar($convenio, 'abono', 70)->delete();
        $vigente = $this->pagar($convenio, 'abono', 200);

        $t = $this->totales($convenio);
        $this->assertSame(200.0, $t['pagado']);
        $this->assertSame(0.0, $t['cargos_iniciales']);
        $this->assertSame(1300.0, $t['por_pagar']);

        $d = $this->detalle($convenio);
        $this->assertSame(0.0, $d[$r1->id]['pagado']);
        $this->assertSame('pendiente', $d[$r1->id]['estado']);
        $this->assertSame([], $d[$r1->id]['pagos']);
        $this->assertNotNull($vigente->id);
    }

    public function test_cuenta_liquidada_usa_el_monto_final(): void
    {
        [$convenio, $r1, $r2] = $this->conDosRecepciones();
        $this->pagar($convenio, 'recepcion', 600, [$r1->id => 600]);
        $this->pagar($convenio, 'adelanto', 100);
        // Una liquidación cancelada no cierra la cuenta.
        $this->crearLiquidacion($convenio, ['status' => 'cancelada', 'monto_final' => 999, 'monto_calculado' => 999, 'liquidada_at' => null]);
        $this->assertSame('abierta', $this->totales($convenio)['estado']);

        $liq = $this->crearLiquidacion($convenio, ['monto_calculado' => 1400, 'monto_final' => 1400]);
        Recepcion::whereIn('id', [$r1->id, $r2->id])->update(['liquidacion_id' => $liq->id]);

        $t = $this->totales($convenio);
        $this->assertSame('liquidada', $t['estado']);
        $this->assertSame($liq->id, $t['liquidacion_id']);
        $this->assertSame(1500.0, $t['valor_recibido']);
        $this->assertSame(700.0, $t['pagado']);
        $this->assertSame(700.0, $t['por_pagar']);

        $d = $this->detalle($convenio);
        $this->assertSame('liquidada', $d[$r1->id]['estado']);
        $this->assertSame('liquidada', $d[$r2->id]['estado']);
    }

    public function test_pagado_de_mas_es_negativo(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $this->pagar($convenio, 'adelanto', 2000);

        $this->assertSame(-500.0, $this->totales($convenio)['por_pagar']);
        $this->assertSame(-500.0, $this->saldo($convenio->productor_id)['MXN']['por_pagar']);
    }

    public function test_precio_corregido_despues_de_pagar_deja_la_recepcion_parcial_y_la_cuenta_cuadra(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($convenio, ['cantidad' => 10]);
        $this->pagar($convenio, 'recepcion', 1000, [$r->id => 1000]);
        $this->assertSame('pagada', $this->detalle($convenio)[$r->id]['estado']);
        $this->assertSame(0.0, $this->totales($convenio)['por_pagar']);

        // Sube el precio: el valor es vivo, falta la diferencia.
        $this->cambiarPrecio($convenio, 120);
        $d = $this->detalle($convenio)[$r->id];
        $t = $this->totales($convenio);
        $this->assertSame(1200.0, $d['valor']);
        $this->assertSame('parcial', $d['estado']);
        $this->assertSame(200.0, $d['falta']);
        $this->assertSame(0.0, $d['de_mas']);
        $this->assertSame(200.0, $t['por_pagar']);
        $this->assertSame($t['por_pagar'], round($t['valor_recibido'] + $t['cargos_iniciales'] - $t['pagado'], 2));

        // Baja el precio: queda pagada con lo de más a la vista.
        $this->cambiarPrecio($convenio, 80);
        $d = $this->detalle($convenio)[$r->id];
        $t = $this->totales($convenio);
        $this->assertSame(800.0, $d['valor']);
        $this->assertSame('pagada', $d['estado']);
        $this->assertSame(0.0, $d['falta']);
        $this->assertSame(200.0, $d['de_mas']);
        $this->assertSame(-200.0, $t['por_pagar']);
        $this->assertSame($t['por_pagar'], round($t['valor_recibido'] + $t['cargos_iniciales'] - $t['pagado'], 2));
    }

    public function test_recepcion_sin_precio_cuenta_cero_y_se_cuenta_en_sin_valor(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $con = $this->crearRecepcion($convenio, ['cantidad' => 10]);
        // Antes de la vigencia del único precio: sin precio vigente.
        $sin = $this->crearRecepcion($convenio, ['cantidad' => 7, 'fecha' => '2026-05-20']);

        $t = $this->totales($convenio);
        $this->assertSame(1000.0, $t['valor_recibido']);
        $this->assertSame(1, $t['sin_valor']);
        $this->assertSame(2, $t['recepciones']);

        $d = $this->detalle($convenio);
        $this->assertSame(0.0, $d[$sin->id]['valor']);
        $this->assertSame('Sin precio vigente', $d[$sin->id]['motivo']);
        $this->assertSame('no_pagable', $d[$sin->id]['estado']);
        $this->assertNull($d[$sin->id]['precio']);
        $this->assertSame('pendiente', $d[$con->id]['estado']);
    }

    public function test_por_calibre_un_renglon_sin_rezaga_queda_no_pagable_pero_su_valor_cuenta(): void
    {
        $base = $this->crearConvenio($this->sbp);
        $variedad = Variedad::findOrFail($base->variedad_id);
        $jumbo = $this->crearCalibre($variedad, 'Jumbo', true, 1);
        $grande = $this->crearCalibre($variedad, 'Grande', true, 2);
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $jumbo, 'volumen' => 100, 'precio' => 120],
            ['calibre' => $grande, 'volumen' => 100, 'precio' => 100],
        ], ['porcentaje_rezaga' => 5, 'productor_id' => $this->productorNuevo('Calibres')->id]);

        $r = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $jumbo->id, 'cantidad' => 10, 'rezaga_kg' => 1],
            ['calibre_id' => $grande->id, 'cantidad' => 5, 'rezaga_kg' => null],
        ]);

        $t = $this->totales($convenio);
        $this->assertSame(1700.0, $t['valor_recibido']);
        $this->assertSame(0, $t['sin_valor']);
        $this->assertSame(1700.0, $t['por_pagar']);

        $d = $this->detalle($convenio)[$r->id];
        $this->assertSame(1700.0, $d['valor']);
        $this->assertSame('Falta la rezaga', $d['motivo']);
        $this->assertSame('no_pagable', $d['estado']);
        $this->assertNull($d['precio']);
        $this->assertSame([
            ['calibre_id' => $jumbo->id, 'calibre' => 'Jumbo', 'cantidad' => 10.0, 'kilos' => null, 'rezaga_kg' => 1.0, 'precio' => 120.0],
            ['calibre_id' => $grande->id, 'calibre' => 'Grande', 'cantidad' => 5.0, 'kilos' => null, 'rezaga_kg' => null, 'precio' => 100.0],
        ], $d['renglones']);
    }

    // ---------------------------------------------------------------- saldo del productor

    public function test_mxn_y_usd_nunca_se_mezclan(): void
    {
        $productor = $this->productorNuevo('Dos monedas');
        [$mxn, $r] = $this->conDosRecepciones(['productor_id' => $productor->id]);
        $this->pagar($mxn, 'recepcion', 300, [$r->id => 300]);
        $usd = $this->crearConvenio($this->sbp, ['productor_id' => $productor->id, 'moneda' => 'USD', 'precio' => 12]);
        $this->crearRecepcion($usd, ['cantidad' => 10]);
        $this->pagar($usd, 'adelanto', 20);
        $this->pagar($usd, 'saldo_inicial', 5);

        $saldo = $this->saldo($productor);
        $this->assertSame(['MXN', 'USD'], array_keys($saldo));
        $this->assertSame(['moneda' => 'MXN', 'valor_recibido' => 1500.0, 'pagado' => 300.0, 'cargos_iniciales' => 0.0, 'por_pagar' => 1200.0], $saldo['MXN']);
        $this->assertSame(['moneda' => 'USD', 'valor_recibido' => 120.0, 'pagado' => 20.0, 'cargos_iniciales' => 5.0, 'por_pagar' => 105.0], $saldo['USD']);
        $this->assertSame('USD', $this->totales($usd)['moneda']);
    }

    public function test_con_temporada_solo_cuentan_sus_convenios(): void
    {
        $productor = $this->productorNuevo('Dos temporadas');
        [$actual] = $this->conDosRecepciones(['productor_id' => $productor->id]);
        $siguiente = Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $actual->cultivo_id, 'nombre' => 'Temporada siguiente',
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2027-03-31', 'estado' => 'activa',
        ]);
        $otro = $this->crearConvenio($this->sbp, ['productor_id' => $productor->id, 'temporada_id' => $siguiente->id]);
        $this->crearRecepcion($otro, ['cantidad' => 3]);
        $this->pagar($otro, 'adelanto', 50);
        // Un convenio cancelado no cuenta en ningún caso.
        $cancelado = $this->crearConvenio($this->sbp, ['productor_id' => $productor->id, 'status' => 'cancelado']);
        $this->crearRecepcion($cancelado, ['cantidad' => 4]);

        $this->assertSame(1500.0, $this->saldo($productor, $actual->temporada_id)['MXN']['por_pagar']);
        $this->assertSame(250.0, $this->saldo($productor, $siguiente->id)['MXN']['por_pagar']);
        $this->assertSame(1750.0, $this->saldo($productor)['MXN']['por_pagar']);
        $this->assertSame(1800.0, $this->saldo($productor)['MXN']['valor_recibido']);
    }

    public function test_productores_sin_movimiento_regresan_arreglo_vacio(): void
    {
        $sinConvenio = $this->productorNuevo('Sin convenio');
        $sinMovimiento = $this->productorNuevo('Sin movimiento');
        $quieto = $this->crearConvenio($this->sbp, ['productor_id' => $sinMovimiento->id]);
        // Un pago cancelado no es movimiento.
        $this->pagar($quieto, 'adelanto', 100, null, ['status' => 'cancelado', 'cancelado_motivo' => 'Capturado por error']);
        $conMovimiento = $this->productorNuevo('Con movimiento');
        $this->conDosRecepciones(['productor_id' => $conMovimiento->id]);

        $saldos = SaldoProductor::porProductores($this->sbp, [$sinConvenio->id, $sinMovimiento->id, $conMovimiento->id]);

        $this->assertSame([], $saldos->get($sinConvenio->id));
        $this->assertSame([], $saldos->get($sinMovimiento->id));
        $this->assertSame(['MXN'], array_keys($saldos->get($conMovimiento->id)));
        $this->assertTrue(SaldoProductor::porProductores($this->sbp, [])->isEmpty());
        // Otra empresa no se cuela.
        $ajeno = $this->crearConvenio($this->otra);
        $this->crearRecepcion($ajeno);
        $this->assertSame([], SaldoProductor::porProductores($this->sbp, [$ajeno->productor_id])->get($ajeno->productor_id));
    }

    // ---------------------------------------------------------------- consultas

    /** Convenio con movimiento completo: recepciones, pago por recepción, adelanto, saldo inicial y, si se pide, liquidado. */
    private function conMovimiento(string $nombre, bool $liquidado = false, bool $porCalibre = false): Convenio
    {
        $productor = $this->productorNuevo($nombre);
        if ($porCalibre) {
            $base = Convenio::firstOrFail();
            $calibre = $this->crearCalibre(Variedad::findOrFail($base->variedad_id), "Calibre {$nombre}");
            $convenio = $this->crearConvenioPorCalibre($this->sbp, [['calibre' => $calibre, 'precio' => 90]], ['productor_id' => $productor->id]);
            $r = $this->crearRecepcionConRenglones($convenio, [['calibre_id' => $calibre->id, 'cantidad' => 4]]);
        } else {
            $convenio = $this->crearConvenio($this->sbp, ['productor_id' => $productor->id]);
            $r = $this->crearRecepcion($convenio, ['cantidad' => 3]);
        }
        $this->crearRecepcion($convenio, ['cantidad' => 2, 'fecha' => '2026-06-03']);
        $this->pagar($convenio, 'recepcion', 50, [$r->id => 50]);
        $this->pagar($convenio, 'adelanto', 10);
        $this->pagar($convenio, 'saldo_inicial', 5);
        if ($liquidado) {
            $this->crearLiquidacion($convenio, ['monto_final' => 400, 'monto_calculado' => 395]);
        }

        return $convenio;
    }

    private function contarConsultas(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_numero_de_consultas_no_depende_de_los_convenios(): void
    {
        $this->conMovimiento('P0', liquidado: true);
        $this->conMovimiento('P1', porCalibre: true);

        // Convenios recién leídos (sin precios cargados): las consultas de la cuenta son las mismas con 2 o con 6.
        $totales = function (): int {
            $convenios = Convenio::orderBy('id')->get();

            return $this->contarConsultas(fn () => CuentaConvenio::totales($convenios));
        };
        $saldo = fn () => $this->contarConsultas(fn () => SaldoProductor::porProductores($this->sbp, Productor::pluck('id')->all()));
        $temporada = fn () => $this->contarConsultas(fn () => SaldoProductor::porPagarDeTemporada($this->sbp, Convenio::firstOrFail()->temporada_id));
        $lote = fn () => $this->contarConsultas(fn () => SaldoProductor::porPagarDeConvenios($this->sbp, Convenio::pluck('id')->all()));

        $con2 = [$totales(), $saldo(), $temporada(), $lote()];

        $this->conMovimiento('P2', liquidado: true);
        $this->conMovimiento('P3', porCalibre: true);
        $this->conMovimiento('P4');
        $this->conMovimiento('P5', liquidado: true, porCalibre: true);
        $this->assertSame(6, Convenio::count());

        $this->assertSame($con2, [$totales(), $saldo(), $temporada(), $lote()]);
        // Recepciones, renglones, calibres, precios, pagos y liquidaciones: nunca una por convenio.
        $this->assertSame(6, $con2[0]);
    }

    public function test_de_convenio_y_por_pagar_de_convenios_y_de_temporada_coinciden_con_por_productores(): void
    {
        $a = $this->conMovimiento('A', liquidado: true);
        $b = $this->conMovimiento('B', porCalibre: true);
        $c = $this->conMovimiento('C');
        $usd = $this->crearConvenio($this->sbp, ['productor_id' => $c->productor_id, 'moneda' => 'USD', 'precio' => 12]);
        $this->crearRecepcion($usd, ['cantidad' => 10]);
        $this->pagar($usd, 'abono', 33.33);

        $saldos = SaldoProductor::porProductores($this->sbp);
        $lote = SaldoProductor::porPagarDeConvenios($this->sbp, [$b->id, $a->id, $c->id, $usd->id, $a->id, 999999]);

        $this->assertSame([$b->id, $a->id, $c->id, $usd->id, 999999], array_keys($lote));
        $this->assertSame(0.0, $lote[999999]);
        foreach ([$a, $b, $usd] as $convenio) {
            $deConvenio = SaldoProductor::deConvenio(Convenio::findOrFail($convenio->id));
            $this->assertSame(array_intersect_key($this->totales($convenio), $deConvenio), $deConvenio);
            $this->assertSame($deConvenio['por_pagar'], $lote[$convenio->id]);
        }
        // A y B tienen un solo convenio: su saldo es el de su convenio.
        $this->assertSame(SaldoProductor::deConvenio(Convenio::findOrFail($a->id)), $saldos->get($a->productor_id)['MXN']);
        $this->assertSame(SaldoProductor::deConvenio(Convenio::findOrFail($b->id)), $saldos->get($b->productor_id)['MXN']);
        $this->assertSame(SaldoProductor::deConvenio(Convenio::findOrFail($usd->id)), $saldos->get($c->productor_id)['USD']);

        // La temporada suma a todos los productores, por moneda.
        $porMoneda = [];
        foreach ($saldos as $monedas) {
            foreach ($monedas as $moneda => $s) {
                $porMoneda[$moneda] = round(($porMoneda[$moneda] ?? 0) + $s['por_pagar'], 2);
            }
        }
        ksort($porMoneda);
        $this->assertSame($porMoneda, SaldoProductor::porPagarDeTemporada($this->sbp, $a->temporada_id));
        $this->assertSame([], SaldoProductor::porPagarDeConvenios($this->sbp, []));
    }
}
