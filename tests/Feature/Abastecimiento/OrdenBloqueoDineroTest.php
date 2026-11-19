<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\User;
use App\Services\Abastecimiento\TransaccionDinero;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Orden único de bloqueo de las escrituras de dinero: productor → convenio → recepciones (por id) → pagos (por id)
 * → liquidación. SQLite compila `lockForUpdate` a nada, así que aquí se comprueba el ORDEN de las lecturas de cada
 * tabla en el SQL ejecutado dentro de la transacción (el primer `select *` de cada una es su bloqueo en MySQL), no
 * el bloqueo real.
 */
class OrdenBloqueoDineroTest extends TestCase
{
    use RefreshDatabase, CreatesAbastecimientoFixtures;

    /** @var array<int, string> SQL ejecutado; `#BEGIN` marca el inicio de cada transacción */
    private array $sql = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function grabar(): void
    {
        $this->sql = [];
        DB::listen(function ($q) {
            $this->sql[] = $q->sql;
        });
        Event::listen(TransactionBeginning::class, function () {
            $this->sql[] = '#BEGIN';
        });
    }

    /** SQL desde la primera transacción abierta después de `grabar()`. */
    private function transaccion(): array
    {
        $inicio = array_search('#BEGIN', $this->sql, true);
        $this->assertNotFalse($inicio, 'La escritura debe abrir una transacción.');

        return array_values(array_slice($this->sql, $inicio + 1));
    }

    /**
     * Índice de la primera sentencia de la transacción que empieza con `$prefijo`.
     *
     * @param  array<int, string>  $tx
     */
    private function primera(array $tx, string $prefijo): int
    {
        foreach ($tx as $i => $s) {
            if (str_starts_with($s, $prefijo)) {
                return $i;
            }
        }
        $this->fail("No se ejecutó: {$prefijo}");
    }

    /** @param  array<int, string>  $prefijos  en el orden esperado */
    private function assertEnOrden(array $prefijos): void
    {
        $tx = $this->transaccion();
        $indices = array_map(fn ($p) => $this->primera($tx, $p), $prefijos);
        $ordenados = $indices;
        sort($ordenados);
        $this->assertSame($ordenados, $indices, "Orden de bloqueo distinto al esperado:\n".implode("\n", array_map(
            fn ($p, $i) => "#{$i} {$p}", $prefijos, $indices,
        )));
    }

    private function usuario(array $permisos): User
    {
        $u = User::factory()->create(['role' => 'user']);
        $this->otorgarAbs($u, $this->sbp, 'liquidaciones', $permisos);

        return $u;
    }

    public function test_pagar_bloquea_productor_convenio_recepciones_y_despues_escribe_el_pago(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r1 = $this->crearRecepcion($convenio);
        $r2 = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02']);
        Sanctum::actingAs($this->usuario(['view', 'create']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/cuentas/{$convenio->id}/pagos", [
            'tipo' => 'recepcion', 'fecha' => '2026-06-10', 'metodo_pago' => 'transferencia',
            // En desorden a propósito: se bloquean por id ascendente.
            'recepciones' => [['recepcion_id' => $r2->id, 'monto' => 100], ['recepcion_id' => $r1->id, 'monto' => 100]],
        ])->assertCreated();

        $this->assertEnOrden([
            'select * from "abs_productores"',
            'select * from "abs_convenios"',
            'select * from "abs_recepciones"',
            'insert into "abs_abonos"',
        ]);
        // El bloqueo de recepciones es uno solo, ordenado por id.
        $tx = $this->transaccion();
        $recepciones = $this->primera($tx, 'select * from "abs_recepciones"');
        $this->assertStringContainsString('order by "id" asc', $tx[$recepciones]);
        // Ningún pago (ni la suma de lo pagado) se lee antes de bloquear las recepciones.
        foreach (array_slice($tx, 0, $recepciones) as $s) {
            $this->assertStringNotContainsString('"abs_abonos"', $s, "Se leyeron pagos antes de bloquear las recepciones: {$s}");
        }
    }

    public function test_abonar_bloquea_productor_y_convenio_antes_de_leer_los_pagos(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $this->crearRecepcion($convenio);
        Sanctum::actingAs($this->usuario(['view', 'create']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/cuentas/{$convenio->id}/pagos", [
            'tipo' => 'abono', 'fecha' => '2026-06-10', 'metodo_pago' => 'efectivo', 'monto' => 50,
        ])->assertCreated();

        $tx = $this->transaccion();
        $convenioBloqueado = $this->primera($tx, 'select * from "abs_convenios"');
        $this->assertLessThan($convenioBloqueado, $this->primera($tx, 'select * from "abs_productores"'));
        foreach ($tx as $i => $s) {
            if (str_contains($s, '"abs_abonos"') || str_contains($s, '"abs_liquidaciones"')) {
                $this->assertGreaterThan($convenioBloqueado, $i, "Se leyó dinero antes de bloquear el convenio: {$s}");
            }
        }
    }

    public function test_cancelar_pago_bloquea_productor_y_convenio_antes_del_pago(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $pago = $this->crearPago($convenio);
        Sanctum::actingAs($this->usuario(['view', 'cancelar']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/pagos/{$pago->id}/cancelar", [
            'motivo' => 'Duplicado',
        ])->assertOk();

        $this->assertEnOrden([
            'select * from "abs_productores"',
            'select * from "abs_convenios"',
            'select * from "abs_abonos"',
            'update "abs_abonos"',
        ]);
    }

    public function test_saldo_inicial_bloquea_productor_y_convenio_antes_de_escribir(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        Sanctum::actingAs($this->usuario(['view', 'carga-inicial']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/cuentas/{$convenio->id}/saldo-inicial", [
            'fecha' => '2026-05-01', 'monto' => 100, 'motivo' => 'Saldo del Excel anterior',
        ])->assertCreated();

        $this->assertEnOrden([
            'select * from "abs_productores"',
            'select * from "abs_convenios"',
            'insert into "abs_abonos"',
        ]);
    }

    public function test_bloquear_liquidacion_va_de_convenio_a_recepciones_pagos_y_al_final_la_liquidacion(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['monto_final' => 1000]);
        $this->crearRecepcion($convenio, ['liquidacion_id' => $liq->id]);
        $this->crearPago($convenio, ['tipo' => 'liquidacion', 'liquidacion_id' => $liq->id, 'monto' => 1000]);

        $this->grabar();
        [$c, $l] = TransaccionDinero::ejecutar(fn () => TransaccionDinero::bloquearLiquidacion($this->sbp->id, $liq->id));

        $this->assertInstanceOf(Convenio::class, $c);
        $this->assertSame($convenio->id, $c->id);
        $this->assertInstanceOf(Liquidacion::class, $l);
        $this->assertSame($liq->id, $l->id);
        $this->assertEnOrden([
            'select * from "abs_productores"',
            'select * from "abs_convenios"',
            'select * from "abs_recepciones"',
            'select * from "abs_abonos"',
            'select * from "abs_liquidaciones"',
        ]);

        $this->expectException(ModelNotFoundException::class);
        TransaccionDinero::ejecutar(fn () => TransaccionDinero::bloquearLiquidacion($this->otra->id, $liq->id));
    }

    public function test_liquidar_bloquea_productor_convenio_y_recepciones_antes_de_leer_dinero_y_escribir(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r1 = $this->crearRecepcion($convenio);
        $this->crearRecepcion($convenio, ['fecha' => '2026-06-02']);
        $this->crearPago($convenio, [], [$r1->id => 500]);
        Sanctum::actingAs($this->usuario(['view', 'liquidar']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/cuentas/{$convenio->id}/liquidar", [
            'registrar_pago' => true, 'pago' => ['fecha' => '2026-06-30', 'metodo_pago' => 'transferencia'],
        ])->assertCreated();

        $this->assertEnOrden([
            'select * from "abs_productores"',
            'select * from "abs_convenios"',
            'select * from "abs_recepciones"',
            'insert into "abs_liquidaciones"',
            'insert into "abs_liquidacion_detalles"',
            'update "abs_recepciones"',
            'insert into "abs_abonos"',
        ]);
        $tx = $this->transaccion();
        $recepciones = $this->primera($tx, 'select * from "abs_recepciones"');
        $this->assertStringContainsString('order by "id" asc', $tx[$recepciones]);
        // Ni pagos ni liquidaciones se leen antes de bloquear las recepciones.
        foreach (array_slice($tx, 0, $recepciones) as $s) {
            $this->assertFalse(str_contains($s, '"abs_abonos"') || str_contains($s, '"abs_liquidaciones"'), "Se leyó dinero antes de bloquear: {$s}");
        }
    }

    public function test_cancelar_liquidacion_bloquea_en_orden_antes_de_escribir(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $liq = $this->crearLiquidacion($convenio, ['monto_final' => 1000]);
        $this->crearRecepcion($convenio, ['liquidacion_id' => $liq->id]);
        $this->crearPago($convenio, ['tipo' => 'liquidacion', 'liquidacion_id' => $liq->id, 'monto' => 1000]);
        Sanctum::actingAs($this->usuario(['view', 'delete']));

        $this->grabar();
        $this->postJson("/api/splendidbyporvenir/administration/abastecimiento/liquidaciones/{$liq->id}/cancelar", [
            'motivo' => 'Precio mal capturado',
        ])->assertOk();

        $this->assertEnOrden([
            'select * from "abs_productores"',
            'select * from "abs_convenios"',
            'select * from "abs_recepciones"',
            'select * from "abs_abonos"',
            'select * from "abs_liquidaciones"',
            'update "abs_liquidaciones"',
        ]);
    }

    /** Ruta de recepciones (no la de liquidaciones). */
    private function rutaRecepciones(string $sufijo = ''): string
    {
        return '/api/splendidbyporvenir/administration/abastecimiento/recepciones'.$sufijo;
    }

    /** Nada de pagos ni liquidaciones se lee antes del bloqueo de la recepción (el dinero se revisa ya bloqueado). */
    private function assertSinLecturaDeDineroAntesDeLaRecepcion(): void
    {
        $tx = $this->transaccion();
        $recepcion = $this->primera($tx, 'select * from "abs_recepciones"');
        foreach (array_slice($tx, 0, $recepcion) as $s) {
            $this->assertFalse(
                str_contains($s, '"abs_abonos"') || str_contains($s, '"abs_liquidaciones"') || str_contains($s, '"abs_abono_recepciones"'),
                "Se leyó dinero antes de bloquear la recepción: {$s}",
            );
        }
    }

    public function test_editar_una_recepcion_bloquea_el_convenio_antes_que_la_recepcion_y_revisa_el_dinero_despues(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->grabar();
        $this->putJson($this->rutaRecepciones("/{$r->id}"), [
            'convenio_id' => $convenio->id, 'fecha' => '2026-06-02', 'tipo_carga_id' => $r->tipo_carga_id, 'cantidad' => 12,
        ])->assertOk();

        $this->assertEnOrden([
            'select * from "abs_convenios"',
            'select * from "abs_recepciones"',
            'select "folio" from "abs_abonos"',
            'update "abs_recepciones"',
        ]);
        $this->assertSinLecturaDeDineroAntesDeLaRecepcion();
    }

    public function test_pasar_una_recepcion_a_otro_convenio_bloquea_los_dos_convenios_juntos_por_id_antes_de_la_recepcion(): void
    {
        $origen = $this->crearConvenio($this->sbp);
        $destino = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($origen, ['fecha' => '2026-06-02']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->grabar();
        $this->putJson($this->rutaRecepciones("/{$r->id}"), [
            'convenio_id' => $destino->id, 'fecha' => '2026-06-02', 'tipo_carga_id' => $r->tipo_carga_id, 'cantidad' => 10,
        ])->assertOk();

        $tx = $this->transaccion();
        $convenios = $this->primera($tx, 'select * from "abs_convenios"');
        // Un solo bloqueo de los dos convenios, por id ascendente.
        $this->assertStringContainsString('order by "id" asc', $tx[$convenios]);
        $this->assertStringContainsString('in (?, ?)', $tx[$convenios]);
        $this->assertLessThan($this->primera($tx, 'select * from "abs_recepciones"'), $convenios);
        $this->assertSinLecturaDeDineroAntesDeLaRecepcion();
        $this->assertSame($destino->id, $r->fresh()->convenio_id);
    }

    public function test_borrar_una_recepcion_bloquea_el_convenio_antes_que_la_recepcion(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($convenio, ['fecha' => '2026-06-02']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->grabar();
        $this->deleteJson($this->rutaRecepciones("/{$r->id}"))->assertOk();

        $this->assertEnOrden([
            'select * from "abs_convenios"',
            'select * from "abs_recepciones"',
            'select "folio" from "abs_abonos"',
            'update "abs_recepciones"',
        ]);
        $this->assertSinLecturaDeDineroAntesDeLaRecepcion();
    }

    public function test_una_recepcion_movida_a_otro_convenio_mientras_tanto_pide_reintentar(): void
    {
        $origen = $this->crearConvenio($this->sbp);
        $otro = $this->crearConvenio($this->sbp);
        $r = $this->crearRecepcion($origen, ['fecha' => '2026-06-02']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        // Simula otra edición que mueve la recepción justo después de que esta leyó su convenio sin bloquear.
        $hecho = false;
        DB::listen(function ($q) use (&$hecho, $r, $otro) {
            if (! $hecho && str_starts_with($q->sql, 'select * from "abs_convenios"')) {
                $hecho = true;
                DB::table('abs_recepciones')->where('id', $r->id)->update(['convenio_id' => $otro->id]);
            }
        });

        $this->putJson($this->rutaRecepciones("/{$r->id}"), [
            'convenio_id' => $origen->id, 'fecha' => '2026-06-02', 'tipo_carga_id' => $r->tipo_carga_id, 'cantidad' => 12,
        ])->assertStatus(422)->assertJsonPath('errors.id.0', 'La recepción cambió mientras se guardaba; vuelve a intentarlo.');
        $this->assertTrue($hecho);
        $this->assertSame('10.00', $r->fresh()->cantidad);
    }

    public function test_el_alta_de_una_recepcion_bloquea_el_convenio_antes_de_revisar_la_liquidacion_y_de_escribir(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $tipo = $convenio->precios()->where('es_principal', true)->value('tipo_carga_id');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->grabar();
        $this->postJson($this->rutaRecepciones(), [
            'convenio_id' => $convenio->id, 'fecha' => '2026-06-02', 'tipo_carga_id' => $tipo, 'cantidad' => 5,
        ])->assertCreated();

        $this->assertEnOrden([
            'select * from "abs_convenios"',
            'select exists',
            'insert into "abs_recepciones"',
        ]);
        $tx = $this->transaccion();
        $this->assertStringContainsString('"abs_liquidaciones"', $tx[$this->primera($tx, 'select exists')]);
    }
    public function test_bloquear_pago_devuelve_convenio_y_pago_y_da_404_fuera_de_la_empresa(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $pago = $this->crearPago($convenio);

        [$c, $p] = TransaccionDinero::ejecutar(fn () => TransaccionDinero::bloquearPago($this->sbp->id, $pago->id));
        $this->assertSame($convenio->id, $c->id);
        $this->assertInstanceOf(Abono::class, $p);
        $this->assertSame($pago->id, $p->id);

        $this->expectException(ModelNotFoundException::class);
        TransaccionDinero::ejecutar(fn () => TransaccionDinero::bloquearPago($this->otra->id, $pago->id));
    }

    public function test_bloquear_convenio_da_404_si_no_es_de_la_empresa(): void
    {
        $convenio = $this->crearConvenio($this->otra);

        $this->expectException(ModelNotFoundException::class);
        TransaccionDinero::ejecutar(fn () => TransaccionDinero::bloquearConvenio($this->sbp->id, $convenio->id));
    }

    public function test_bloquear_convenio_falla_si_el_productor_cambio_entre_lecturas(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $otro = Productor::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Otro', 'tipo' => 'productor', 'is_active' => true]);

        // Simula otra transacción que cambia el productor justo después de leer `productor_id` sin bloqueo.
        $hecho = false;
        DB::listen(function ($q) use (&$hecho, $convenio, $otro) {
            if (! $hecho && str_starts_with($q->sql, 'select "productor_id" from "abs_convenios"')) {
                $hecho = true;
                DB::table('abs_convenios')->where('id', $convenio->id)->update(['productor_id' => $otro->id]);
            }
        });

        try {
            TransaccionDinero::ejecutar(fn () => TransaccionDinero::bloquearConvenio($this->sbp->id, $convenio->id));
            $this->fail('Debió fallar porque el productor cambió.');
        } catch (ValidationException $e) {
            $this->assertSame(['convenio' => ['El convenio cambió mientras se guardaba; vuelve a intentarlo.']], $e->errors());
        }
        $this->assertTrue($hecho);
    }
}
