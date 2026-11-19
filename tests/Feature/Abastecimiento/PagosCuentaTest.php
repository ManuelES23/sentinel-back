<?php

namespace Tests\Feature\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoRecepcion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Productor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Pagos de la cuenta de un convenio (spec 5.2 y 5.6): pagos por recepción, abonos, adelantos, saldo inicial y
 * cancelación. `POST liquidaciones/cuentas/{convenio}/pagos`, `POST liquidaciones/pagos/{id}/cancelar` y
 * `POST liquidaciones/cuentas/{convenio}/saldo-inicial`.
 */
class PagosCuentaTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    // ---------------------------------------------------------------- ayudas

    private function url(string $sufijo): string
    {
        return '/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/'.$sufijo;
    }

    private function comoAdmin(): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($u);

        return $u;
    }

    private function pagar(Convenio $convenio, array $datos): TestResponse
    {
        return $this->postJson($this->url("cuentas/{$convenio->id}/pagos"), array_merge([
            'fecha' => '2026-06-10', 'metodo_pago' => 'transferencia',
        ], $datos));
    }

    /** @param  array<int, float>  $montos  recepcion_id => monto */
    private function pagarRecepciones(Convenio $convenio, array $montos, array $extra = []): TestResponse
    {
        $filas = [];
        foreach ($montos as $id => $monto) {
            $filas[] = ['recepcion_id' => $id, 'monto' => $monto];
        }

        return $this->pagar($convenio, array_merge(['tipo' => 'recepcion', 'recepciones' => $filas], $extra));
    }

    private function cancelar(int $pagoId, string $motivo = 'Capturado por error'): TestResponse
    {
        return $this->postJson($this->url("pagos/{$pagoId}/cancelar"), ['motivo' => $motivo]);
    }

    private function saldoInicial(Convenio $convenio, array $datos): TestResponse
    {
        return $this->postJson($this->url("cuentas/{$convenio->id}/saldo-inicial"), array_merge([
            'fecha' => '2026-05-15', 'monto' => 300, 'motivo' => 'Deuda de la temporada pasada en Excel',
        ], $datos));
    }

    /** Primer mensaje de error de una llave (las llaves con puntos no se pueden pedir con assertJsonPath). */
    private function error(TestResponse $res, string $llave): ?string
    {
        $res->assertStatus(422);

        return $res->json('errors')[$llave][0] ?? null;
    }

    private function productor(string $nombre): Productor
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

    // ---------------------------------------------------------------- pagos por recepción

    public function test_paga_dos_recepciones_y_el_total_es_la_suma(): void
    {
        [$convenio, $r1, $r2] = $this->conDosRecepciones();
        $quien = $this->comoAdmin();

        $res = $this->pagarRecepciones($convenio, [$r1->id => 1000, $r2->id => 250.5], [
            'referencia' => 'SPEI-1', 'notas' => 'Primer pago',
        ])->assertCreated();

        $res->assertJsonPath('message', 'Pago registrado.');
        $this->assertSame($convenio->id, $res->json('data.convenio.id'));
        $this->assertEqualsWithDelta(1250.5, $res->json('data.totales.pagado'), 0.001);
        $this->assertEqualsWithDelta(249.5, $res->json('data.totales.por_pagar'), 0.001);
        $estados = array_column($res->json('data.recepciones'), 'estado', 'id');
        $this->assertSame([$r1->id => 'pagada', $r2->id => 'parcial'], $estados);

        $pago = Abono::sole();
        $this->assertSame('recepcion', $pago->tipo);
        $this->assertSame('AB-0001', $pago->folio);
        $this->assertSame('1250.50', $pago->monto);
        $this->assertSame('MXN', $pago->moneda);
        $this->assertSame('aplicado', $pago->status);
        $this->assertSame($convenio->productor_id, $pago->productor_id);
        $this->assertSame($convenio->id, $pago->convenio_id);
        $this->assertSame($quien->id, $pago->created_by);
        $this->assertSame('2026-06-10', $pago->fecha->format('Y-m-d'));
        $this->assertSame('transferencia', $pago->metodo_pago);
        $this->assertSame('SPEI-1', $pago->referencia);
        $this->assertSame('Primer pago', $pago->notas);
        $this->assertSame(
            [$r1->id => '1000.00', $r2->id => '250.50'],
            AbonoRecepcion::where('abono_id', $pago->id)->orderBy('recepcion_id')->pluck('monto', 'recepcion_id')->all(),
        );
    }

    public function test_pago_parcial_y_luego_el_resto_exacto_pasa_pero_un_centavo_mas_no(): void
    {
        // 3 cajas a 33.33 = 99.99.
        $convenio = $this->crearConvenio($this->sbp, ['precio' => 33.33]);
        $r = $this->crearRecepcion($convenio, ['cantidad' => 3]);
        $this->comoAdmin();

        $this->pagarRecepciones($convenio, [$r->id => 33.33])->assertCreated();

        $res = $this->pagarRecepciones($convenio, [$r->id => 66.67]);
        $this->assertSame("El monto de {$r->folio} pasa de lo que le falta (66.66 MXN).", $this->error($res, 'recepciones.0.monto'));
        $this->assertSame(1, Abono::count());

        $res = $this->pagarRecepciones($convenio, [$r->id => 66.66])->assertCreated();
        $this->assertSame('pagada', $res->json('data.recepciones.0.estado'));
        $this->assertEqualsWithDelta(0, $res->json('data.totales.por_pagar'), 0.001);

        // Ya pagada: cualquier monto pasa de lo que falta.
        $res = $this->pagarRecepciones($convenio, [$r->id => 0.01]);
        $this->assertSame("El monto de {$r->folio} pasa de lo que le falta (0.00 MXN).", $this->error($res, 'recepciones.0.monto'));
    }

    public function test_lo_que_falta_se_muestra_con_separador_de_miles(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['moneda' => 'USD']);
        $r = $this->crearRecepcion($convenio, ['cantidad' => 15]);
        $this->comoAdmin();

        $res = $this->pagarRecepciones($convenio, [$r->id => 1500.01]);
        $this->assertSame("El monto de {$r->folio} pasa de lo que le falta (1,500.00 USD).", $this->error($res, 'recepciones.0.monto'));
    }

    public function test_recepcion_de_otro_convenio_borrada_o_inexistente_no_es_de_este_convenio(): void
    {
        [$convenio, $r1] = $this->conDosRecepciones();
        $otro = $this->crearConvenio($this->sbp);
        $ajena = $this->crearRecepcion($otro);
        $borrada = $this->crearRecepcion($convenio, ['fecha' => '2026-06-03']);
        $borrada->delete();
        $this->comoAdmin();

        foreach ([$ajena->id, $borrada->id, 999999] as $id) {
            $res = $this->pagarRecepciones($convenio, [$r1->id => 100, $id => 10]);
            $this->assertSame('La recepción no es de este convenio.', $this->error($res, 'recepciones.1.recepcion_id'));
        }
        $this->assertSame(0, Abono::count());
    }

    public function test_recepcion_repetida(): void
    {
        [$convenio, $r1] = $this->conDosRecepciones();
        $this->comoAdmin();

        $res = $this->pagar($convenio, ['tipo' => 'recepcion', 'recepciones' => [
            ['recepcion_id' => $r1->id, 'monto' => 100],
            ['recepcion_id' => $r1->id, 'monto' => 100],
        ]]);

        $this->assertSame("La recepción {$r1->folio} está repetida.", $this->error($res, 'recepciones.1.recepcion_id'));
    }

    public function test_recepcion_que_no_se_puede_pagar_da_su_motivo(): void
    {
        $convenio = $this->crearConvenio($this->sbp, ['porcentaje_rezaga' => 5]);
        $sinRezaga = $this->crearRecepcion($convenio, ['rezaga_kg' => null]);
        $this->comoAdmin();

        $res = $this->pagarRecepciones($convenio, [$sinRezaga->id => 100]);

        $this->assertSame("{$sinRezaga->folio}: Falta la rezaga.", $this->error($res, 'recepciones.0.recepcion_id'));
    }

    public function test_cuenta_liquidada_no_paga_recepciones(): void
    {
        [$convenio, $r1] = $this->conDosRecepciones();
        $this->crearLiquidacion($convenio, ['monto_final' => 1500]);
        $this->comoAdmin();

        $res = $this->pagarRecepciones($convenio, [$r1->id => 100]);

        $this->assertSame('El convenio ya está liquidado: no se pagan recepciones.', $this->error($res, 'convenio'));
    }

    public function test_convenio_en_borrador_o_cancelado_no_admite_pagos_y_los_demas_si(): void
    {
        $this->comoAdmin();
        $borrador = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        $cancelado = $this->crearConvenio($this->sbp, ['status' => 'cancelado']);

        $this->assertSame('El convenio está en borrador: no admite pagos.', $this->error($this->pagar($borrador, ['tipo' => 'abono', 'monto' => 10]), 'convenio'));
        $this->assertSame('El convenio está cancelado: no admite pagos.', $this->error($this->pagar($cancelado, ['tipo' => 'adelanto', 'monto' => 10]), 'convenio'));
        $this->assertSame('El convenio está en borrador: no admite pagos.', $this->error($this->saldoInicial($borrador, []), 'convenio'));

        foreach (['activo', 'suspendido', 'finalizado'] as $status) {
            $c = $this->crearConvenio($this->sbp, ['status' => $status]);
            $this->pagar($c, ['tipo' => 'abono', 'monto' => 10])->assertCreated();
        }
    }

    public function test_validacion_de_campos(): void
    {
        [$convenio, $r1] = $this->conDosRecepciones();
        $this->comoAdmin();

        $this->pagar($convenio, ['tipo' => 'abono'])->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 0])->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10.123])->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->pagar($convenio, ['tipo' => 'recepcion'])->assertStatus(422)->assertJsonValidationErrors('recepciones');
        $this->pagar($convenio, ['tipo' => 'liquidacion', 'monto' => 10])->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->pagar($convenio, ['tipo' => 'saldo_inicial', 'monto' => 10])->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'metodo_pago' => null])->assertStatus(422)->assertJsonValidationErrors('metodo_pago');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'metodo_pago' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('metodo_pago');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'referencia' => str_repeat('x', 121)])->assertStatus(422)->assertJsonValidationErrors('referencia');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'notas' => str_repeat('x', 5001)])->assertStatus(422)->assertJsonValidationErrors('notas');
        $this->pagarRecepciones($convenio, [$r1->id => 10.555])->assertStatus(422)->assertJsonValidationErrors('recepciones.0.monto');
        $this->pagarRecepciones($convenio, [$r1->id => 0])->assertStatus(422)->assertJsonValidationErrors('recepciones.0.monto');
        $muchas = array_fill(0, 201, ['recepcion_id' => $r1->id, 'monto' => 1]);
        $this->pagar($convenio, ['tipo' => 'recepcion', 'recepciones' => $muchas])->assertStatus(422)->assertJsonValidationErrors('recepciones');
        $this->assertSame(0, Abono::count());
    }

    public function test_fecha_futura_da_422(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $this->comoAdmin();

        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'fecha' => now()->addDay()->format('Y-m-d')])
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'fecha' => '10/06/2026'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'fecha' => now()->format('Y-m-d')])->assertCreated();
    }

    public function test_moneda_es_la_del_convenio_aunque_mande_otra(): void
    {
        [$convenio, $r1] = $this->conDosRecepciones(['moneda' => 'USD']);
        $this->comoAdmin();

        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10, 'moneda' => 'MXN'])->assertCreated();
        $this->pagarRecepciones($convenio, [$r1->id => 10], ['moneda' => 'MXN'])->assertCreated();
        $this->saldoInicial($convenio, ['moneda' => 'MXN'])->assertCreated();

        $this->assertSame(['USD'], Abono::pluck('moneda')->unique()->values()->all());
    }

    // ---------------------------------------------------------------- abonos y adelantos

    public function test_adelanto_sin_tope_con_cuenta_abierta_deja_pagado_de_mas(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($convenio);
        $this->comoAdmin();

        $res = $this->pagar($convenio, ['tipo' => 'adelanto', 'monto' => 5000])->assertCreated();

        $this->assertEqualsWithDelta(-4000, $res->json('data.totales.por_pagar'), 0.001);
        $this->assertSame('adelanto', Abono::sole()->tipo);
        $this->assertSame([], Abono::sole()->recepciones()->get()->all());

        // Un abono tampoco tiene tope con la cuenta abierta, aunque no haya recepciones.
        $vacio = $this->crearConvenio($this->sbp);
        $this->pagar($vacio, ['tipo' => 'abono', 'monto' => 99999.99])->assertCreated();
    }

    public function test_abono_con_cuenta_liquidada_hasta_el_restante(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $this->crearLiquidacion($convenio, ['monto_final' => 1000]);
        $this->crearPago($convenio, ['tipo' => 'liquidacion', 'monto' => 400]);
        $this->comoAdmin();

        $res = $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 600.01]);
        $this->assertSame('El abono pasa de lo que falta por pagar (600.00 MXN).', $this->error($res, 'monto'));
        $res = $this->pagar($convenio, ['tipo' => 'adelanto', 'monto' => 600.01]);
        $this->assertSame('El abono pasa de lo que falta por pagar (600.00 MXN).', $this->error($res, 'monto'));

        $res = $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 600])->assertCreated();
        $this->assertSame('liquidada', $res->json('data.estado'));
        $this->assertEqualsWithDelta(0, $res->json('data.totales.por_pagar'), 0.001);

        $res = $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 0.01]);
        $this->assertSame('El abono pasa de lo que falta por pagar (0.00 MXN).', $this->error($res, 'monto'));
    }

    // ---------------------------------------------------------------- cancelar

    public function test_cancelar_un_pago_por_recepcion_libera_todas_sus_recepciones_y_conserva_el_reparto(): void
    {
        [$convenio, $r1, $r2] = $this->conDosRecepciones();
        $quien = $this->comoAdmin();
        $this->pagarRecepciones($convenio, [$r1->id => 1000, $r2->id => 500])->assertCreated();
        $pago = Abono::sole();

        $res = $this->cancelar($pago->id, 'Se pagó al productor equivocado')->assertOk();

        $res->assertJsonPath('message', 'Pago cancelado.');
        $this->assertEqualsWithDelta(0, $res->json('data.totales.pagado'), 0.001);
        $this->assertEqualsWithDelta(1500, $res->json('data.totales.por_pagar'), 0.001);
        $this->assertSame(['pendiente', 'pendiente'], array_column($res->json('data.recepciones'), 'estado'));
        $pago->refresh();
        $this->assertSame('cancelado', $pago->status);
        $this->assertSame('Se pagó al productor equivocado', $pago->cancelado_motivo);
        $this->assertSame($quien->id, $pago->cancelado_by);
        $this->assertNotNull($pago->cancelado_at);
        $this->assertSame(2, AbonoRecepcion::where('abono_id', $pago->id)->count());

        // Liberadas: se pueden volver a pagar completas.
        $this->pagarRecepciones($convenio, [$r1->id => 1000])->assertCreated();
    }

    public function test_cancelar_exige_motivo(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $pago = $this->crearPago($convenio);
        $this->comoAdmin();

        $this->postJson($this->url("pagos/{$pago->id}/cancelar"), [])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->cancelar($pago->id, str_repeat('x', 2001))->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->assertSame('aplicado', $pago->fresh()->status);
    }

    public function test_cancelar_un_pago_ya_cancelado(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $pago = $this->crearPago($convenio, ['status' => 'cancelado', 'cancelado_motivo' => 'x', 'cancelado_at' => now()]);
        $this->comoAdmin();

        $this->assertSame('El pago ya está cancelado.', $this->error($this->cancelar($pago->id), 'id'));
    }

    public function test_un_pago_de_liquidacion_no_se_cancela_suelto(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $liq = $this->crearLiquidacion($convenio, ['monto_final' => 1500]);
        $pago = $this->crearPago($convenio, ['tipo' => 'liquidacion', 'monto' => 1500, 'liquidacion_id' => $liq->id]);
        $this->comoAdmin();

        $this->assertSame(
            "Este pago es de la liquidación {$liq->folio}: se cancela al cancelar la liquidación.",
            $this->error($this->cancelar($pago->id), 'id'),
        );
    }

    public function test_con_la_cuenta_liquidada_no_se_cancela_ningun_pago(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $abono = $this->crearPago($convenio, ['monto' => 200]);
        $liq = $this->crearLiquidacion($convenio, ['monto_final' => 1500]);
        $this->comoAdmin();

        $this->assertSame(
            "El convenio está liquidado: cancela primero la liquidación {$liq->folio}.",
            $this->error($this->cancelar($abono->id), 'id'),
        );
        $this->assertSame('aplicado', $abono->fresh()->status);
    }

    public function test_cancelar_pago_de_otra_empresa_da_404(): void
    {
        $deOtra = $this->crearConvenio($this->otra);
        $pago = $this->crearPago($deOtra);
        $this->comoAdmin();

        $this->cancelar($pago->id)->assertNotFound();
        $this->cancelar(999999)->assertNotFound();
        $this->pagar($deOtra, ['tipo' => 'abono', 'monto' => 10])->assertNotFound();
        $this->saldoInicial($deOtra, [])->assertNotFound();
    }

    // ---------------------------------------------------------------- saldo inicial

    public function test_saldo_inicial_suma_a_por_pagar_y_exige_motivo_de_10(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $this->comoAdmin();

        $this->saldoInicial($convenio, ['motivo' => 'Corto'])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->saldoInicial($convenio, ['motivo' => null])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->saldoInicial($convenio, ['monto' => 0])->assertStatus(422)->assertJsonValidationErrors('monto');
        $this->saldoInicial($convenio, ['fecha' => now()->addDay()->format('Y-m-d')])->assertStatus(422)->assertJsonValidationErrors('fecha');

        $res = $this->saldoInicial($convenio, ['monto' => 300.25, 'motivo' => 'Deuda del Excel 2025'])->assertCreated();

        $res->assertJsonPath('message', 'Saldo inicial registrado.');
        $this->assertEqualsWithDelta(300.25, $res->json('data.totales.cargos_iniciales'), 0.001);
        $this->assertEqualsWithDelta(0, $res->json('data.totales.pagado'), 0.001);
        $this->assertEqualsWithDelta(1800.25, $res->json('data.totales.por_pagar'), 0.001);
        $pago = Abono::sole();
        $this->assertSame('saldo_inicial', $pago->tipo);
        $this->assertSame('Deuda del Excel 2025', $pago->notas);
        $this->assertSame('300.25', $pago->monto);
        $this->assertSame('2026-05-15', $pago->fecha->format('Y-m-d'));
        $this->assertSame('aplicado', $pago->status);
        $this->assertStringStartsWith('AB-', $pago->folio);

        // Se cancela como cualquier pago.
        $this->cancelar($pago->id)->assertOk();
        $this->assertEqualsWithDelta(1500, $this->getJson($this->url("cuentas/{$convenio->id}"))->json('data.totales.por_pagar'), 0.001);
    }

    public function test_saldo_inicial_con_cuenta_liquidada(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $this->crearLiquidacion($convenio, ['monto_final' => 1500]);
        $this->comoAdmin();

        $this->assertSame('El convenio ya está liquidado: no admite saldo inicial.', $this->error($this->saldoInicial($convenio, []), 'convenio'));
    }

    // ---------------------------------------------------------------- evento y permisos

    public function test_el_evento_va_recortado_sin_montos(): void
    {
        [$convenio, $r1] = $this->conDosRecepciones();
        $this->comoAdmin();
        Event::fake([AbastecimientoUpdated::class]);
        $llaves = ['id', 'folio', 'tipo', 'status', 'convenio_id', 'productor_id', 'moneda', 'entidad'];

        $id = $this->pagarRecepciones($convenio, [$r1->id => 1000])->assertCreated()->json('data.pagos.0.id');
        Event::assertDispatched(AbastecimientoUpdated::class, function (AbastecimientoUpdated $e) use ($llaves, $id, $convenio) {
            return $e->action === 'created' && array_keys($e->data) === $llaves && $e->data['entidad'] === 'pago'
                && $e->data['id'] === $id && $e->data['tipo'] === 'recepcion' && $e->data['status'] === 'aplicado'
                && $e->data['convenio_id'] === $convenio->id && $e->data['productor_id'] === $convenio->productor_id
                && $e->data['moneda'] === 'MXN';
        });

        $this->cancelar($id)->assertOk();
        Event::assertDispatched(AbastecimientoUpdated::class, fn (AbastecimientoUpdated $e) => $e->action === 'updated'
            && array_keys($e->data) === $llaves && $e->data['id'] === $id && $e->data['status'] === 'cancelado');

        $this->saldoInicial($convenio, [])->assertCreated();
        Event::assertDispatched(AbastecimientoUpdated::class, fn (AbastecimientoUpdated $e) => $e->action === 'created'
            && array_keys($e->data) === $llaves && $e->data['tipo'] === 'saldo_inicial');

        Event::assertDispatchedTimes(AbastecimientoUpdated::class, 3);
        // Un error de negocio no emite nada.
        $this->cancelar($id)->assertStatus(422);
        Event::assertDispatchedTimes(AbastecimientoUpdated::class, 3);
    }

    public function test_una_falla_al_difundir_no_rompe_la_respuesta(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $this->comoAdmin();
        Event::listen(AbastecimientoUpdated::class, function () {
            throw new \RuntimeException('websockets caído');
        });

        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10])->assertCreated();
        $this->assertSame(1, Abono::count());
    }

    public function test_permisos(): void
    {
        [$convenio] = $this->conDosRecepciones();
        $pago = $this->crearPago($convenio);
        $usuario = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($usuario);

        // Sin nada (ni siquiera ver): 403.
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10])->assertForbidden();
        $this->cancelar($pago->id)->assertForbidden();
        $this->saldoInicial($convenio, [])->assertForbidden();

        // Ver no basta para escribir.
        $this->otorgarAbs($usuario, $this->sbp, 'liquidaciones', ['view']);
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10])->assertForbidden();
        $this->cancelar($pago->id)->assertForbidden();
        $this->saldoInicial($convenio, [])->assertForbidden();

        // `create` paga, pero no cancela ni registra saldo inicial.
        $this->otorgarAbs($usuario, $this->sbp, 'liquidaciones', ['create']);
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10])->assertCreated();
        $this->cancelar($pago->id)->assertForbidden();
        $this->saldoInicial($convenio, [])->assertForbidden();

        $this->otorgarAbs($usuario, $this->sbp, 'liquidaciones', ['cancelar']);
        $this->cancelar($pago->id)->assertOk();
        $this->saldoInicial($convenio, [])->assertForbidden();

        $this->otorgarAbs($usuario, $this->sbp, 'liquidaciones', ['carga-inicial']);
        $this->saldoInicial($convenio, [])->assertCreated();

        // Permisos de otro submódulo no cuentan.
        $otro = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($otro, $this->sbp, 'recepciones', ['view', 'create']);
        $this->otorgarAbs($otro, $this->sbp, 'convenios', ['view', 'create', 'edit']);
        Sanctum::actingAs($otro);
        $this->pagar($convenio, ['tipo' => 'abono', 'monto' => 10])->assertForbidden();
    }

    public function test_precio_corregido_despues_de_pagar_limita_por_lo_que_falta_vivo(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($convenio);
        $this->comoAdmin();
        $this->pagarRecepciones($convenio, [$r->id => 1000])->assertCreated();

        // Sube el precio: ahora falta la diferencia y se puede pagar exacta.
        ConvenioPrecio::where('convenio_id', $convenio->id)->update(['precio' => 120]);
        $res = $this->pagarRecepciones($convenio, [$r->id => 200.01]);
        $this->assertSame("El monto de {$r->folio} pasa de lo que le falta (200.00 MXN).", $this->error($res, 'recepciones.0.monto'));
        $this->pagarRecepciones($convenio, [$r->id => 200])->assertCreated();
    }
}
