<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoRecepcion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * «Liquidar total» del convenio (spec 5.4 y 5.6): `POST liquidaciones/cuentas/{convenio}/previa`,
 * `POST liquidaciones/cuentas/{convenio}/liquidar`, `GET liquidaciones/{id}` y `POST liquidaciones/{id}/cancelar`.
 *
 * Escenario por calibre (el de LiquidacionCalibresTest): Jumbo con precio especial de 180 y Grande con el general
 * de 150, rezaga aceptada 5 %. R1 (2 jun): Jumbo 10 / 100 kg / 10 kg de rezaga y Grande 4 / 40 / 4; R2 (3 jun):
 * Grande 6 / 60 / 0. Rezaga real 7 % → excedente 2 %. Bruto 3300, descuento 66, monto 3234.
 * Valor a cuenta (sin rezaga): R1 = 2400, R2 = 900.
 */
class LiquidarConvenioTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    // ---------------------------------------------------------------- ayudas

    private function url(string $sufijo): string
    {
        return '/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/'.$sufijo;
    }

    /** @return array{0: Convenio, 1: Recepcion, 2: Recepcion} */
    private function escenario(array $convenioOver = []): array
    {
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();
        $variedad = Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
        $jumbo = $this->crearCalibre($variedad, 'Jumbo', true, 1);
        $grande = $this->crearCalibre($variedad, 'Grande', true, 2);
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $jumbo, 'volumen' => 100, 'precio' => 180.0],
            ['calibre' => $grande, 'volumen' => 100, 'precio' => 150.0],
        ], array_merge(['variedad_id' => $variedad->id, 'porcentaje_rezaga' => 5], $convenioOver));
        ConvenioPrecio::where('convenio_id', $convenio->id)->where('calibre_id', $grande->id)->update(['calibre_id' => null]);

        $r1 = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $jumbo->id, 'cantidad' => 10, 'kilos' => 100, 'rezaga_kg' => 10],
            ['calibre_id' => $grande->id, 'cantidad' => 4, 'kilos' => 40, 'rezaga_kg' => 4],
        ], ['fecha' => '2026-06-02']);
        $r2 = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $grande->id, 'cantidad' => 6, 'kilos' => 60, 'rezaga_kg' => 0],
        ], ['fecha' => '2026-06-03']);

        return [$convenio->fresh(), $r1, $r2];
    }

    private function previa(Convenio $c, array $datos = []): TestResponse
    {
        return $this->postJson($this->url("cuentas/{$c->id}/previa"), $datos);
    }

    private function liquidar(Convenio $c, array $datos = []): TestResponse
    {
        return $this->postJson($this->url("cuentas/{$c->id}/liquidar"), $datos + ['registrar_pago' => false]);
    }

    private function liquidarPagando(Convenio $c, array $datos = []): TestResponse
    {
        return $this->liquidar($c, $datos + [
            'registrar_pago' => true,
            'pago' => ['fecha' => '2026-06-30', 'metodo_pago' => 'transferencia', 'referencia' => 'SPEI-9', 'notas' => 'Finiquito'],
        ]);
    }

    private function cancelar(int $liquidacionId, string $motivo = 'Rezaga mal capturada'): TestResponse
    {
        return $this->postJson($this->url("{$liquidacionId}/cancelar"), ['motivo' => $motivo]);
    }

    private function pagarRecepcion(Convenio $c, Recepcion $r, float $monto): TestResponse
    {
        return $this->postJson($this->url("cuentas/{$c->id}/pagos"), [
            'tipo' => 'recepcion', 'fecha' => '2026-06-10', 'metodo_pago' => 'transferencia',
            'recepciones' => [['recepcion_id' => $r->id, 'monto' => $monto]],
        ]);
    }

    private function abonar(Convenio $c, float $monto): TestResponse
    {
        return $this->postJson($this->url("cuentas/{$c->id}/pagos"), [
            'tipo' => 'abono', 'fecha' => '2026-06-10', 'metodo_pago' => 'efectivo', 'monto' => $monto,
        ]);
    }

    private function saldoInicial(Convenio $c, float $monto): TestResponse
    {
        return $this->postJson($this->url("cuentas/{$c->id}/saldo-inicial"), [
            'fecha' => '2026-05-15', 'monto' => $monto, 'motivo' => 'Deuda de la temporada pasada en Excel',
        ]);
    }

    private function error(TestResponse $res, string $llave): ?string
    {
        $res->assertStatus(422);

        return $res->json('errors')[$llave][0] ?? null;
    }

    // ---------------------------------------------------------------- previa

    public function test_previa_calcula_rezaga_del_convenio_y_resta_lo_pagado(): void
    {
        [$c, $r1, $r2] = $this->escenario();
        $this->pagarRecepcion($c, $r1, 2400)->assertCreated();

        $data = $this->previa($c)->assertOk()->json('data');

        $this->assertEqualsWithDelta(3300, $data['bruto'], 0.001);
        $this->assertEqualsWithDelta(7, $data['rezaga_real_pct'], 0.0001);
        $this->assertEqualsWithDelta(5, $data['rezaga_aceptada_pct'], 0.0001);
        $this->assertEqualsWithDelta(66, $data['descuento'], 0.001);
        $this->assertEqualsWithDelta(3234, $data['monto_calculado'], 0.001);
        $this->assertNull($data['monto_ajustado']);
        $this->assertEqualsWithDelta(0, $data['saldo_inicial'], 0.001);
        $this->assertEqualsWithDelta(3234, $data['monto_final'], 0.001);
        $this->assertEqualsWithDelta(2400, $data['pagado'], 0.001);
        $this->assertSame(834.0, (float) $data['restante']);
        $this->assertSame('MXN', $data['moneda']);
        $this->assertSame('caja', $data['unidad']);

        $this->assertCount(3, $data['detalles']);
        $primero = $data['detalles'][0];
        $this->assertSame($r1->id, $primero['recepcion_id']);
        $this->assertSame($r1->folio, $primero['folio']);
        $this->assertSame('2026-06-02', $primero['fecha']);
        $this->assertSame(['id', 'nombre'], array_keys($primero['tipo_carga']));
        $this->assertSame('Jumbo', $primero['calibre']['nombre']);
        $this->assertSame([$r1->folio, $r1->folio, $r2->folio], array_column($data['detalles'], 'folio'));

        // La previa no escribe nada.
        $this->assertSame(0, Liquidacion::count());
        $this->assertNull($r1->fresh()->liquidacion_id);
    }

    public function test_saldo_inicial_se_suma_al_monto_final_y_el_ajuste_solo_reemplaza_las_recepciones(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($c); // 10 cajas × 100 = 1000
        $this->saldoInicial($c, 300)->assertCreated();
        $this->crearPago($c, ['tipo' => 'saldo_inicial', 'monto' => 50, 'status' => 'cancelado']); // no cuenta

        $data = $this->previa($c)->assertOk()->json('data');
        $this->assertEqualsWithDelta(1000, $data['monto_calculado'], 0.001);
        $this->assertEqualsWithDelta(300, $data['saldo_inicial'], 0.001);
        $this->assertEqualsWithDelta(1300, $data['monto_final'], 0.001);
        $this->assertEqualsWithDelta(1300, $data['restante'], 0.001);

        // El ajuste reemplaza solo la parte de recepciones; el saldo inicial se sigue sumando.
        $data = $this->previa($c, ['monto_ajustado' => 900, 'motivo_ajuste' => 'Acuerdo con el productor'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(1000, $data['monto_calculado'], 0.001);
        $this->assertEqualsWithDelta(900, $data['monto_ajustado'], 0.001);
        $this->assertSame('Acuerdo con el productor', $data['motivo_ajuste']);
        $this->assertEqualsWithDelta(1200, $data['monto_final'], 0.001);

        // Ajuste sin motivo: el mensaje de siempre.
        $this->assertSame('Indica el motivo del ajuste manual.', $this->error($this->previa($c, ['monto_ajustado' => 900]), 'motivo_ajuste'));
        $this->assertSame('Indica el motivo del ajuste manual.', $this->error($this->liquidar($c, ['monto_ajustado' => 900]), 'motivo_ajuste'));
        $this->assertSame(0, Liquidacion::count());

        $res = $this->liquidar($c, ['monto_ajustado' => 900, 'motivo_ajuste' => 'Acuerdo con el productor'])->assertCreated();
        $this->assertEqualsWithDelta(1200, $res->json('data.totales.por_pagar'), 0.001);

        $liq = Liquidacion::sole();
        $this->assertSame('1000.00', $liq->monto_calculado);
        $this->assertSame('900.00', $liq->monto_ajustado);
        $this->assertSame('Acuerdo con el productor', $liq->motivo_ajuste);
        $this->assertSame('300.00', $liq->saldo_inicial);
        $this->assertSame('1200.00', $liq->monto_final);
        $this->assertSame($liq->id, $r->fresh()->liquidacion_id);
    }

    // ---------------------------------------------------------------- liquidar

    public function test_liquidar_con_registrar_pago_deja_la_cuenta_en_cero(): void
    {
        [$c, $r1, $r2] = $this->escenario();
        $this->pagarRecepcion($c, $r1, 2400)->assertCreated();
        $borrada = $this->crearRecepcionConRenglones($c, [
            ['calibre_id' => $r2->renglones->first()->calibre_id, 'cantidad' => 99, 'kilos' => 990, 'rezaga_kg' => 0],
        ], ['fecha' => '2026-06-04']);
        $borrada->delete(); // no entra a la liquidación
        $previa = $this->previa($c)->assertOk()->json('data');
        Event::fake([AbastecimientoUpdated::class]);

        $res = $this->liquidarPagando($c, ['notas' => 'Cierre de temporada'])->assertCreated();

        $res->assertJsonPath('message', 'Convenio liquidado.');
        $this->assertSame('liquidada', $res->json('data.estado'));
        $this->assertEqualsWithDelta(0, $res->json('data.totales.por_pagar'), 0.001);
        $this->assertEqualsWithDelta(3234, $res->json('data.totales.pagado'), 0.001);

        $liq = Liquidacion::sole();
        $this->assertSame($res->json('data.liquidacion.id'), $liq->id);
        $this->assertSame('LQ-0001', $liq->folio);
        $this->assertSame('liquidada', $liq->status);
        $this->assertNull($liq->periodo_inicio);
        $this->assertNull($liq->periodo_fin);
        $this->assertSame($c->id, $liq->convenio_id);
        $this->assertSame($c->productor_id, $liq->productor_id);
        $this->assertSame('MXN', $liq->moneda);
        $this->assertSame('20.00', $liq->cantidad);
        $this->assertSame('200.00', $liq->kilos);
        $this->assertSame('3300.00', $liq->bruto);
        $this->assertEqualsWithDelta(7, (float) $liq->rezaga_real_pct, 0.0001);
        $this->assertSame('5.00', $liq->rezaga_aceptada_pct);
        $this->assertSame('66.00', $liq->descuento);
        $this->assertSame('3234.00', $liq->monto_calculado);
        $this->assertNull($liq->monto_ajustado);
        $this->assertSame('0.00', $liq->saldo_inicial);
        $this->assertSame('3234.00', $liq->monto_final);
        // Lo que se ve en la previa es lo que se escribe.
        $this->assertEqualsWithDelta($previa['monto_final'], (float) $liq->monto_final, 0.001);
        $this->assertSame($this->admin->id, $liq->liquidada_by);
        $this->assertSame($this->admin->id, $liq->created_by);
        $this->assertNotNull($liq->liquidada_at);
        $this->assertSame('Cierre de temporada', $liq->notas);

        $this->assertSame(3, LiquidacionDetalle::where('liquidacion_id', $liq->id)->count());
        $this->assertEquals(
            array_column($previa['detalles'], 'a_pagar'),
            LiquidacionDetalle::where('liquidacion_id', $liq->id)->orderBy('id')->pluck('a_pagar')->map(fn ($v) => (float) $v)->all(),
        );
        $this->assertSame($liq->id, $r1->fresh()->liquidacion_id);
        $this->assertSame($liq->id, $r2->fresh()->liquidacion_id);
        $this->assertNull(Recepcion::withTrashed()->find($borrada->id)->liquidacion_id);

        $pago = Abono::where('tipo', 'liquidacion')->sole();
        $this->assertSame($liq->id, $pago->liquidacion_id);
        $this->assertSame('834.00', $pago->monto);
        $this->assertSame('MXN', $pago->moneda);
        $this->assertSame('aplicado', $pago->status);
        $this->assertSame('2026-06-30', $pago->fecha->format('Y-m-d'));
        $this->assertSame('transferencia', $pago->metodo_pago);
        $this->assertSame('SPEI-9', $pago->referencia);
        $this->assertSame('Finiquito', $pago->notas);
        $this->assertSame('AB-0002', $pago->folio);
        $this->assertSame(0, AbonoRecepcion::where('abono_id', $pago->id)->count());

        // Eventos recortados: la liquidación y su pago, sin montos.
        Event::assertDispatched(AbastecimientoUpdated::class, fn (AbastecimientoUpdated $e) => $e->action === 'created'
            && $e->data['entidad'] === 'liquidacion'
            && array_keys($e->data) === ['id', 'folio', 'status', 'convenio_id', 'productor_id', 'moneda', 'entidad']
            && $e->data['id'] === $liq->id && $e->data['status'] === 'liquidada');
        Event::assertDispatched(AbastecimientoUpdated::class, fn (AbastecimientoUpdated $e) => $e->action === 'created'
            && $e->data['entidad'] === 'pago'
            && array_keys($e->data) === ['id', 'folio', 'tipo', 'status', 'convenio_id', 'productor_id', 'moneda', 'entidad']
            && $e->data['id'] === $pago->id && $e->data['tipo'] === 'liquidacion');
        Event::assertDispatchedTimes(AbastecimientoUpdated::class, 2);
    }

    public function test_liquidar_sin_registrar_pago_deja_el_restante_y_se_abona_despues(): void
    {
        [$c, $r1] = $this->escenario();
        $this->pagarRecepcion($c, $r1, 2400)->assertCreated();

        // `registrar_pago` es obligatorio; si se pide registrar y hay restante, también los datos del pago.
        $this->postJson($this->url("cuentas/{$c->id}/liquidar"), [])->assertStatus(422)->assertJsonValidationErrors('registrar_pago');
        $this->assertSame('Faltan la fecha y el método del pago.', $this->error($this->liquidar($c, ['registrar_pago' => true]), 'pago'));
        $this->assertSame(0, Liquidacion::count());

        Event::fake([AbastecimientoUpdated::class]);
        $res = $this->liquidar($c, ['registrar_pago' => false])->assertCreated();
        $this->assertEqualsWithDelta(834, $res->json('data.totales.por_pagar'), 0.001);
        $this->assertSame(0, Abono::where('tipo', 'liquidacion')->count());
        Event::assertDispatchedTimes(AbastecimientoUpdated::class, 1); // solo la liquidación

        $this->assertSame('El abono pasa de lo que falta por pagar (834.00 MXN).', $this->error($this->abonar($c, 834.01), 'monto'));
        $res = $this->abonar($c, 834)->assertCreated();
        $this->assertEqualsWithDelta(0, $res->json('data.totales.por_pagar'), 0.001);
        $this->assertSame('liquidada', $res->json('data.estado'));
    }

    public function test_sin_restante_no_crea_pago_aunque_se_pida(): void
    {
        $c = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($c); // 1000
        $this->abonar($c, 1200)->assertCreated(); // pagado de más

        $data = $this->previa($c)->assertOk()->json('data');
        $this->assertEqualsWithDelta(-200, $data['restante'], 0.001);

        // Sin `pago`: no se exige porque no hay restante.
        $res = $this->liquidar($c, ['registrar_pago' => true])->assertCreated();
        $this->assertEqualsWithDelta(-200, $res->json('data.totales.por_pagar'), 0.001);
        $this->assertSame(0, Abono::where('tipo', 'liquidacion')->count());
    }

    public function test_cuenta_liquidada_bloquea_pagar_recepciones_y_saldo_inicial_pero_deja_abonar(): void
    {
        [$c, $r1, $r2] = $this->escenario();
        $this->liquidar($c)->assertCreated();

        $this->assertSame('El convenio ya está liquidado: no se pagan recepciones.', $this->error($this->pagarRecepcion($c, $r2, 100), 'convenio'));
        $this->assertSame('El convenio ya está liquidado: no admite saldo inicial.', $this->error($this->saldoInicial($c, 100), 'convenio'));
        $this->assertSame('El convenio ya está liquidado (LQ-0001).', $this->error($this->previa($c), 'convenio'));
        $this->abonar($c, 100)->assertCreated();
    }

    public function test_no_se_liquida_dos_veces(): void
    {
        [$c] = $this->escenario();
        $this->liquidar($c)->assertCreated();

        $this->assertSame('El convenio ya está liquidado (LQ-0001).', $this->error($this->liquidarPagando($c), 'convenio'));
        $this->assertSame(1, Liquidacion::count());
        $this->assertSame(0, Abono::count());
    }

    public function test_sin_recepciones_o_con_una_sin_rezaga_da_422_con_los_folios(): void
    {
        $vacio = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($vacio)->delete(); // las borradas no cuentan
        $mensaje = 'El convenio no tiene recepciones para liquidar.';
        $this->assertSame($mensaje, $this->error($this->previa($vacio), 'convenio'));
        $this->assertSame($mensaje, $this->error($this->liquidarPagando($vacio), 'convenio'));

        // Por kilo con rezaga aceptada: una sin rezaga y otra sin kilos; la completa no aparece.
        $c = $this->crearConvenio($this->sbp, ['unidad' => 'kg', 'porcentaje_rezaga' => 5]);
        $sinRezaga = $this->crearRecepcion($c, ['fecha' => '2026-06-02', 'kilos' => 100, 'rezaga_kg' => null]);
        $sinKilos = $this->crearRecepcion($c, ['fecha' => '2026-06-03', 'kilos' => null, 'rezaga_kg' => null]);
        $this->crearRecepcion($c, ['fecha' => '2026-06-01', 'kilos' => 100, 'rezaga_kg' => 0]);

        $mensaje = "No se puede liquidar: {$sinRezaga->folio} (Falta la rezaga), {$sinKilos->folio} (Faltan los kilos).";
        $this->assertSame($mensaje, $this->error($this->previa($c), 'convenio'));
        $this->assertSame($mensaje, $this->error($this->liquidarPagando($c), 'convenio'));
        $this->assertSame(0, Liquidacion::count());
        $this->assertSame(0, Abono::count());
        $this->assertSame(0, Recepcion::whereNotNull('liquidacion_id')->count());
    }

    public function test_un_convenio_en_borrador_o_cancelado_no_se_liquida_ni_en_la_previa(): void
    {
        foreach (['borrador' => 'El convenio está en borrador: no se liquida.', 'cancelado' => 'El convenio está cancelado: no se liquida.'] as $status => $mensaje) {
            $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
            $this->crearRecepcion($c, ['fecha' => '2026-06-02']);
            $c->update(['status' => $status]);

            $this->assertSame($mensaje, $this->error($this->previa($c), 'convenio'));
            $this->assertSame($mensaje, $this->error($this->liquidarPagando($c), 'convenio'));
        }
        $this->assertSame(0, Liquidacion::count());
        $this->assertSame(0, Abono::count());
        $this->assertSame(0, Recepcion::whereNotNull('liquidacion_id')->count());

        // Suspendido y finalizado siguen pudiéndose liquidar.
        foreach (['suspendido', 'finalizado'] as $status) {
            $c = $this->crearConvenio($this->sbp, ['status' => $status]);
            $this->crearRecepcion($c, ['fecha' => '2026-06-02']);
            $this->previa($c)->assertOk();
            $this->liquidar($c)->assertCreated();
        }
    }

    public function test_por_calibre_un_solo_renglon_sin_rezaga_impide_liquidar_pero_la_recepcion_sigue_valiendo(): void
    {
        [$c, $r1, $r2] = $this->escenario();
        // Solo UN renglón (Grande de R1) sin dato de rezaga; el resto de R1 y R2 lo traen.
        $renglon = $r1->renglones()->whereHas('calibre', fn ($q) => $q->where('nombre', 'Grande'))->sole();
        $renglon->update(['rezaga_kg' => null]);

        $mensaje = "No se puede liquidar: {$r1->folio} (Falta la rezaga).";
        $this->assertSame($mensaje, $this->error($this->previa($c), 'convenio'));
        $this->assertSame($mensaje, $this->error($this->liquidarPagando($c), 'convenio'));
        $this->assertSame(0, Liquidacion::count());
        $this->assertSame(0, Abono::count());
        $this->assertSame(0, Recepcion::whereNotNull('liquidacion_id')->count());

        // La falta de rezaga no quita valor: la cuenta sigue contando las dos recepciones (2400 + 900).
        $totales = $this->getJson($this->url("cuentas/{$c->id}"))->assertOk()->json('data.totales');
        $this->assertEqualsWithDelta(3300, $totales['valor_recibido'], 0.001);
    }

    // ---------------------------------------------------------------- cancelar

    public function test_cancelar_libera_recepciones_cancela_el_pago_de_liquidacion_y_conserva_los_demas_y_se_puede_liquidar_de_nuevo(): void
    {
        [$c, $r1, $r2] = $this->escenario();
        $this->pagarRecepcion($c, $r1, 2400)->assertCreated();
        $this->abonar($c, 100)->assertCreated();
        $id = $this->liquidarPagando($c)->assertCreated()->json('data.liquidacion.id');
        $pagoLiq = Abono::where('tipo', 'liquidacion')->sole();
        $this->assertSame('734.00', $pagoLiq->monto); // 3234 − 2400 − 100

        $this->postJson($this->url("{$id}/cancelar"), [])->assertStatus(422)->assertJsonValidationErrors('motivo');
        Event::fake([AbastecimientoUpdated::class]);

        $res = $this->cancelar($id)->assertOk();
        $res->assertJsonPath('message', 'Liquidación cancelada.');
        $this->assertSame('abierta', $res->json('data.estado'));
        // Cuenta abierta: 3300 recibido − 2500 pagado.
        $this->assertEqualsWithDelta(800, $res->json('data.totales.por_pagar'), 0.001);
        $this->assertNull($res->json('data.liquidacion'));

        $liq = Liquidacion::findOrFail($id);
        $this->assertSame('cancelada', $liq->status);
        $this->assertSame('Rezaga mal capturada', $liq->cancelada_motivo);
        $this->assertSame($this->admin->id, $liq->cancelada_by);
        $this->assertNotNull($liq->cancelada_at);
        $this->assertNull($r1->fresh()->liquidacion_id);
        $this->assertNull($r2->fresh()->liquidacion_id);

        $pagoLiq->refresh();
        $this->assertSame('cancelado', $pagoLiq->status);
        $this->assertSame("Se canceló la liquidación {$liq->folio}: Rezaga mal capturada", $pagoLiq->cancelado_motivo);
        $this->assertSame($this->admin->id, $pagoLiq->cancelado_by);
        $this->assertNotNull($pagoLiq->cancelado_at);
        $this->assertSame(['aplicado', 'aplicado'], Abono::whereIn('tipo', ['recepcion', 'abono'])->orderBy('id')->pluck('status')->all());

        Event::assertDispatched(AbastecimientoUpdated::class, fn (AbastecimientoUpdated $e) => $e->action === 'updated'
            && $e->data['entidad'] === 'liquidacion' && $e->data['id'] === $id && $e->data['status'] === 'cancelada');
        Event::assertDispatched(AbastecimientoUpdated::class, fn (AbastecimientoUpdated $e) => $e->action === 'updated'
            && $e->data['entidad'] === 'pago' && $e->data['id'] === $pagoLiq->id && $e->data['status'] === 'cancelado');
        Event::assertDispatchedTimes(AbastecimientoUpdated::class, 2);

        $this->assertSame('La liquidación ya está cancelada.', $this->error($this->cancelar($id), 'id'));
        Event::assertDispatchedTimes(AbastecimientoUpdated::class, 2);

        // Se liquida de nuevo: folio nuevo y un pago nuevo por el mismo restante.
        $res = $this->liquidarPagando($c)->assertCreated();
        $nueva = Liquidacion::where('status', 'liquidada')->sole();
        $this->assertSame('LQ-0002', $nueva->folio);
        $this->assertSame($nueva->id, $r1->fresh()->liquidacion_id);
        $this->assertSame('734.00', Abono::where('liquidacion_id', $nueva->id)->sole()->monto);
        $this->assertEqualsWithDelta(0, $res->json('data.totales.por_pagar'), 0.001);
    }

    public function test_cancelar_liquidacion_de_otra_empresa_da_404(): void
    {
        $liq = $this->crearLiquidacion($this->crearConvenio($this->otra));

        $this->cancelar($liq->id)->assertNotFound();
        $this->assertSame('liquidada', $liq->fresh()->status);
    }

    // ---------------------------------------------------------------- USD por kilo

    public function test_usd_por_kilo_de_punta_a_punta(): void
    {
        $c = $this->crearConvenio($this->sbp, ['unidad' => 'kg', 'moneda' => 'USD', 'porcentaje_rezaga' => 5, 'precio' => 2.5]);
        ConvenioPrecio::where('convenio_id', $c->id)->update(['porcentaje_productor' => 80]);
        // Rezaga real 120 ÷ 1500 = 8 % → excedente 3 %.
        // r1: 1000 kg × 2.5 = 2500, descuento 75, (2425) × 80 % = 1940; valor a cuenta 2000.
        // r2:  500 kg × 2.5 = 1250, descuento 37.5, (1212.5) × 80 % = 970; valor a cuenta 1000.
        $r1 = $this->crearRecepcion($c, ['cantidad' => 50, 'kilos' => 1000, 'rezaga_kg' => 80]);
        $r2 = $this->crearRecepcion($c, ['cantidad' => 25, 'kilos' => 500, 'rezaga_kg' => 40, 'fecha' => '2026-06-02']);
        $this->pagarRecepcion($c, $r1, 2000)->assertCreated();

        $data = $this->previa($c)->assertOk()->json('data');
        $this->assertSame('USD', $data['moneda']);
        $this->assertSame('kg', $data['unidad']);
        $this->assertEquals([2500, 1250], array_column($data['detalles'], 'subtotal'));
        $this->assertEquals([75, 37.5], array_column($data['detalles'], 'descuento'));
        $this->assertEquals([1940, 970], array_column($data['detalles'], 'a_pagar'));
        $this->assertArrayNotHasKey('calibre', $data['detalles'][0]);
        $this->assertEqualsWithDelta(2910, $data['monto_final'], 0.001);
        $this->assertEqualsWithDelta(910, $data['restante'], 0.001);

        $res = $this->liquidarPagando($c)->assertCreated();
        $this->assertEqualsWithDelta(0, $res->json('data.totales.por_pagar'), 0.001);
        $liq = Liquidacion::sole();
        $this->assertSame('USD', $liq->moneda);
        $this->assertSame('2910.00', $liq->monto_final);
        $pago = Abono::where('tipo', 'liquidacion')->sole();
        $this->assertSame('USD', $pago->moneda);
        $this->assertSame('910.00', $pago->monto);
        $this->assertSame($liq->id, $r2->fresh()->liquidacion_id);
    }

    // ---------------------------------------------------------------- ficha

    public function test_show_trae_detalles_con_calibre_saldos_iniciales_pagado_y_restante(): void
    {
        [$c, $r1] = $this->escenario();
        $saldo = $this->saldoInicial($c, 500)->assertCreated()->json('data.pagos.0');
        $this->crearPago($c, ['tipo' => 'saldo_inicial', 'monto' => 70, 'status' => 'cancelado', 'fecha' => '2026-05-01']);
        $this->pagarRecepcion($c, $r1, 2400)->assertCreated();
        $id = $this->liquidar($c)->assertCreated()->json('data.liquidacion.id');

        $data = $this->getJson($this->url((string) $id))->assertOk()->json('data');

        $this->assertSame(['Jumbo', 'Grande', 'Grande'], array_column(array_column($data['detalles'], 'calibre'), 'nombre'));
        $this->assertSame($r1->folio, $data['detalles'][0]['folio']);
        $this->assertEqualsWithDelta(500, $data['saldo_inicial'], 0.001);
        $this->assertEqualsWithDelta(3734, $data['monto_final'], 0.001);
        $this->assertEquals([[
            'id' => $saldo['id'],
            'folio' => $saldo['folio'],
            'fecha' => '2026-05-15',
            'monto' => 500.0,
            'motivo' => 'Deuda de la temporada pasada en Excel',
        ]], $data['saldos_iniciales']);
        $this->assertEqualsWithDelta(2400, $data['pagado'], 0.001);
        $this->assertEqualsWithDelta(1334, $data['restante'], 0.001);
        $this->assertTrue($data['puede']['cancelar']);
    }

    public function test_la_ficha_de_una_liquidacion_cancelada_no_muestra_la_cuenta_de_hoy(): void
    {
        [$c, $r1] = $this->escenario();
        $this->saldoInicial($c, 500)->assertCreated();
        $this->pagarRecepcion($c, $r1, 2400)->assertCreated();
        $id = $this->liquidar($c)->assertCreated()->json('data.liquidacion.id');
        $this->cancelar($id)->assertOk();
        // Hoy la cuenta está abierta de nuevo, con su propio pagado y restante.
        $this->abonar($c, 50)->assertCreated();

        $data = $this->getJson($this->url((string) $id))->assertOk()->json('data');

        $this->assertSame('cancelada', $data['status']);
        // Lo congelado se conserva; lo vivo de la cuenta (pagado, restante y saldos iniciales de hoy) no se muestra.
        $this->assertCount(3, $data['detalles']);
        $this->assertEqualsWithDelta(3734, $data['monto_final'], 0.001);
        $this->assertNull($data['pagado']);
        $this->assertNull($data['restante']);
        $this->assertSame([], $data['saldos_iniciales']);
        $this->assertFalse($data['puede']['cancelar']);
    }

    // ---------------------------------------------------------------- permisos

    public function test_permisos_liquidar_y_delete(): void
    {
        [$c] = $this->escenario();
        $usuario = function (array $permisos): User {
            $u = User::factory()->create(['role' => 'user']);
            $this->otorgarAbs($u, $this->sbp, 'liquidaciones', $permisos);
            Sanctum::actingAs($u);

            return $u;
        };

        $usuario([]);
        $this->previa($c)->assertForbidden();
        $this->liquidarPagando($c)->assertForbidden();

        $usuario(['view']);
        $this->previa($c)->assertForbidden();
        $this->liquidarPagando($c)->assertForbidden();

        // `liquidar` sin `view`: 403 antes de validar, sin escribir nada.
        $usuario(['liquidar', 'delete', 'create']);
        $this->previa($c)->assertForbidden();
        $this->liquidarPagando($c)->assertForbidden();
        $this->postJson($this->url("cuentas/{$c->id}/liquidar"), [])->assertForbidden();
        $this->assertSame(0, Liquidacion::count());
        $this->assertSame(0, Abono::count());

        $usuario(['view', 'liquidar']);
        $this->previa($c)->assertOk();
        $id = $this->liquidarPagando($c)->assertCreated()->json('data.liquidacion.id');
        $this->cancelar($id)->assertForbidden();

        // `delete` sin `view`: 403 y la liquidación sigue vigente.
        $usuario(['delete', 'liquidar']);
        $this->cancelar($id)->assertForbidden();
        $this->postJson($this->url("{$id}/cancelar"), [])->assertForbidden();
        $this->assertSame('liquidada', Liquidacion::findOrFail($id)->status);
        $this->assertSame('aplicado', Abono::where('tipo', 'liquidacion')->sole()->status);

        $usuario(['view', 'delete']);
        $this->cancelar($id)->assertOk();

        // Convenio de otra empresa: 404.
        $ajeno = $this->crearConvenio($this->otra);
        Sanctum::actingAs($this->admin);
        $this->previa($ajeno)->assertNotFound();
        $this->liquidar($ajeno)->assertNotFound();
    }
}
