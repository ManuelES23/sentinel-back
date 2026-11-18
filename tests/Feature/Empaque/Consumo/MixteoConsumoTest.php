<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueConsumo;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Product;
use App\Models\RecipeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * Mixteo de colas y su reversa: las cajas cambian de pallet pero lo consumido por caja no se
 * vuelve a descontar ni se devuelve (los renglones se reasignan con las cajas); la cola que queda
 * vacía libera su tarima, el mixto carga con tarima y cierre, y deshacer hace lo inverso.
 */
class MixteoConsumoTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    private const URL = '/api/splendidfarms/operacion-agricola/empaque/produccion';
    private const INICIAL = 1000.0;
    private const TOL = 0.0001;

    private int $procesoId;
    private int $consecutivoApi = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->procesoId = $this->crearRecepcionConProduccion($this->productorPrincipal, 'API')['proceso']->id;

        foreach ($this->insumos() as $insumo) {
            $this->darExistencia($insumo, self::INICIAL);
        }
    }

    /** Cada petición HTTP empieza con el servicio Scoped vacío, como en producción. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    // ---- helpers ----

    /** @return Product[] */
    private function insumos(): array
    {
        return [$this->tarima, $this->esquineros, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15, $this->cajaAlterna];
    }

    private function neto(Product $insumo): float
    {
        return round($this->existencia($insumo) - self::INICIAL, 4);
    }

    private function assertNeto(float $esperado, Product $insumo): void
    {
        $this->assertEqualsWithDelta($esperado, $this->neto($insumo), self::TOL, $insumo->name);
    }

    private function cola(int $cajas): ProduccionEmpaque
    {
        $n = ++$this->consecutivoApi;
        $response = $this->postJson(self::URL, [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->procesoId,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => sprintf('COLA-%04d', $n),
            'fecha_produccion' => '2026-02-03',
            'total_cajas' => $cajas,
            'peso_neto_kg' => $cajas * 4,
            'clasificacion' => 'convencional',
            'is_cola' => true,
        ]);
        $response->assertCreated();

        return ProduccionEmpaque::findOrFail($response->json('data.id'));
    }

    /** @param array<int, int> $tomas cola_id => cajas */
    private function mixtear(array $tomas): ProduccionEmpaque
    {
        $response = $this->mixtearRespuesta($tomas);
        $response->assertOk();

        return ProduccionEmpaque::findOrFail($response->json('data.id'));
    }

    private function mixtearRespuesta(array $tomas): TestResponse
    {
        return $this->postJson(self::URL.'/mixtear-colas', [
            'mix_items' => collect($tomas)->map(fn ($cajas, $id) => ['cola_id' => $id, 'cajas' => $cajas])->values()->all(),
        ]);
    }

    /** Σ cajas vigentes por caja del producto en el pallet (o en una entrada). */
    private function cajasVigentes(ProduccionEmpaque $p, Product $producto, ?ProduccionEmpaqueDetalle $d = null): int
    {
        return (int) ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
            ->where('tipo', RecipeItem::CONSUMO_POR_CAJA)->where('product_id', $producto->id)
            ->when($d, fn ($q) => $q->where('detalle_id', $d->id))
            ->vigentes()->sum('cajas');
    }

    private function cantidadVigente(ProduccionEmpaque $p, Product $producto, string $tipo = RecipeItem::CONSUMO_POR_CAJA): float
    {
        return (float) ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
            ->where('tipo', $tipo)->where('product_id', $producto->id)->vigentes()
            ->get()->sum(fn (ProduccionEmpaqueConsumo $c) => $c->vigente());
    }

    /**
     * Invariantes del mixteo: (1) lo vigente por caja de cada entrada viva con consumo = sus cajas;
     * nada vigente cuelga de un pallet borrado ni de una entrada que no existe; (2) ningún renglón
     * devuelve más de lo que salió; (3) existencia de cajas = inicial − cajas de los pallets vivos
     * no congelados ($cajasCongeladas: las de colas congeladas que viajan dentro de un mixto).
     */
    private function assertInvariantes(int $cajasCongeladas = 0): void
    {
        $cajasVivas = 0;

        foreach (ProduccionEmpaque::with('detalles')->get() as $p) {
            $entradas = $p->detalles->isEmpty() ? collect([null]) : $p->detalles;

            foreach ($entradas as $d) {
                $cajasEntrada = (int) ($d?->total_cajas ?? $p->total_cajas);
                $vigentes = [];
                foreach ([$this->cajaB15, $this->etiqueta] as $producto) {
                    $vigentes[$producto->id] = (int) ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
                        ->where('tipo', RecipeItem::CONSUMO_POR_CAJA)->where('product_id', $producto->id)
                        ->when($d, fn ($q) => $q->where('detalle_id', $d->id), fn ($q) => $q->whereNull('detalle_id'))
                        ->vigentes()->sum('cajas');
                    if ($vigentes[$producto->id] > 0) {
                        $this->assertSame($cajasEntrada, $vigentes[$producto->id], "Pallet {$p->numero_pallet} entrada {$d?->numero_entrada}: {$producto->name}");
                    }
                }
                if ($vigentes[$this->cajaB15->id] > 0) {
                    $cajasVivas += $cajasEntrada;
                }
            }

            // Lo vigente por caja de un pallet vivo cuelga de sus entradas (o del pallet sin entradas).
            $huerfanos = ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
                ->where('tipo', RecipeItem::CONSUMO_POR_CAJA)->vigentes()
                ->when($p->detalles->isNotEmpty(),
                    fn ($q) => $q->where(fn ($w) => $w->whereNull('detalle_id')->orWhereNotIn('detalle_id', $p->detalles->pluck('id'))),
                    fn ($q) => $q->whereNotNull('detalle_id'))
                ->count();
            $this->assertSame(0, $huerfanos, "Pallet {$p->numero_pallet}: renglones por caja fuera de sus entradas");
        }

        $borrados = ProduccionEmpaque::onlyTrashed()->pluck('id');
        $this->assertSame(0, ProduccionEmpaqueConsumo::whereIn('produccion_id', $borrados)->vigentes()->count(),
            'Un pallet borrado conserva consumo vigente');

        $this->assertFalse(ProduccionEmpaqueConsumo::whereColumn('cantidad_devuelta', '>', 'cantidad')->exists(),
            'Un renglón devolvió más de lo que salió');

        $cajasEnPallets = (int) ProduccionEmpaque::has('consumos')->sum('total_cajas');
        $this->assertSame($cajasEnPallets - $cajasCongeladas, $cajasVivas, 'Cajas vivas con consumo');
        $this->assertNeto(-$cajasVivas, $this->cajaB15);

        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    // ---- mixteo ----

    public function test_mixtear_dos_colas_completas_neto_una_tarima(): void
    {
        $a = $this->cola(120);
        $b = $this->cola(120);
        $this->assertNeto(-2, $this->tarima);

        $mixto = $this->mixtear([$a->id => 120, $b->id => 120]);

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
        $this->assertSame(240, (int) $mixto->total_cajas);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);
        // Las cajas ya habían salido al capturar las colas: el mixteo no las toca.
        $this->assertNeto(-240, $this->cajaB15);
        $this->assertNeto(-242, $this->etiqueta);
        $this->assertSame(240, $this->cajasVigentes($mixto, $this->cajaB15));
        $this->assertEqualsWithDelta(1, $this->cantidadVigente($mixto, $this->tarima, RecipeItem::CONSUMO_AL_ARMAR), self::TOL);
        $this->assertEqualsWithDelta(4, $this->cantidadVigente($mixto, $this->esquineros, RecipeItem::CONSUMO_AL_CERRAR), self::TOL);
        $this->assertInvariantes();
    }

    public function test_mixteo_parcial_mueve_consumo_por_caja(): void
    {
        $a = $this->cola(100);
        $b = $this->cola(50);

        $mixto = $this->mixtear([$a->id => 40, $b->id => 50]);

        $a->refresh();
        $this->assertFalse($a->trashed());
        $this->assertSame(60, (int) $a->total_cajas);
        $baseA = $a->detalles()->sole();
        $this->assertSame(60, $this->cajasVigentes($a, $this->cajaB15, $baseA));
        $this->assertEqualsWithDelta(60, $this->cantidadVigente($a, $this->cajaB15), self::TOL);
        $this->assertEqualsWithDelta(60.5, $this->cantidadVigente($a, $this->etiqueta), self::TOL);
        // La cola que conserva cajas conserva su tarima; la que se vacía la libera.
        $this->assertEqualsWithDelta(1, $this->cantidadVigente($a, $this->tarima, RecipeItem::CONSUMO_AL_ARMAR), self::TOL);

        $entradaDeA = $mixto->detalles()->where('total_cajas', 40)->sole();
        $this->assertSame(40, $this->cajasVigentes($mixto, $this->cajaB15, $entradaDeA));
        $this->assertSame(90, $this->cajasVigentes($mixto, $this->cajaB15));
        $this->assertEqualsWithDelta(90, $this->cantidadVigente($mixto, $this->cajaB15), self::TOL);

        $this->assertNeto(-150, $this->cajaB15);
        $this->assertNeto(-2, $this->tarima); // A conserva la suya, B devolvió la suya, el mixto lleva una
        $this->assertNeto(-4, $this->esquineros);
        $this->assertInvariantes();
    }

    public function test_borrar_cola_parcial_y_deshacer_mixteo_no_devuelve_de_mas(): void
    {
        $a = $this->cola(100);
        $b = $this->cola(80);
        $mixto = $this->mixtear([$a->id => 40, $b->id => 80]);
        $this->assertNeto(-180, $this->cajaB15);
        $this->assertInvariantes();

        // Borrar A devuelve exactamente sus 60 cajas restantes (y su tarima).
        $this->deleteJson(self::URL."/{$a->id}")->assertOk();
        $this->assertNeto(-120, $this->cajaB15);
        $this->assertNeto(-1, $this->tarima);
        $this->assertInvariantes();

        // Deshacer el mixteo: A vuelve con las 40 del mixto (las 60 borradas no reviven) y B con 80.
        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo")->assertOk();

        $this->assertSoftDeleted($mixto);
        $this->assertSame(40, (int) $a->fresh()->total_cajas);
        $this->assertSame(80, (int) $b->fresh()->total_cajas);
        $this->assertNeto(-120, $this->cajaB15);
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(0, $this->esquineros);
        $this->assertInvariantes();
    }

    public function test_deshacer_mixteo_restaura_tarimas(): void
    {
        $a = $this->cola(120);
        $b = $this->cola(100);
        $mixto = $this->mixtear([$a->id => 120, $b->id => 50]);
        $this->assertNeto(-2, $this->tarima); // A devolvió la suya, B la conserva, el mixto lleva una
        $this->assertNeto(-4, $this->esquineros);

        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo")->assertOk();

        $this->assertSoftDeleted($mixto);
        $this->assertEqualsWithDelta(0, $this->cantidadVigente($mixto, $this->tarima, RecipeItem::CONSUMO_AL_ARMAR), self::TOL);
        $this->assertSame(120, (int) $a->fresh()->total_cajas);
        $this->assertSame(100, (int) $b->fresh()->total_cajas);
        $this->assertEqualsWithDelta(1, $this->cantidadVigente($a, $this->tarima, RecipeItem::CONSUMO_AL_ARMAR), self::TOL);
        $this->assertEqualsWithDelta(1, $this->cantidadVigente($b, $this->tarima, RecipeItem::CONSUMO_AL_ARMAR), self::TOL);
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(0, $this->esquineros);
        $this->assertNeto(0, $this->fleje);
        $this->assertNeto(0, $this->grapa);
        $this->assertNeto(-220, $this->cajaB15);
        $this->assertInvariantes();
    }

    public function test_mixteo_con_cola_congelada(): void
    {
        $congelada = $this->crearPallet(['total_cajas' => 100, 'is_cola' => true]);
        $this->assertTrue($congelada->congelado());
        $nueva = $this->cola(80);

        $mixto = $this->mixtear([$congelada->id => 100, $nueva->id => 80]);

        // Solo se mueven los renglones de la nueva; las cajas de la congelada nunca salieron.
        $entradaCongelada = $mixto->detalles()->where('total_cajas', 100)->sole();
        $entradaNueva = $mixto->detalles()->where('total_cajas', 80)->sole();
        $this->assertSame(0, $this->cajasVigentes($mixto, $this->cajaB15, $entradaCongelada));
        $this->assertSame(80, $this->cajasVigentes($mixto, $this->cajaB15, $entradaNueva));
        $this->assertNeto(-80, $this->cajaB15);
        // El mixto es un pallet nuevo: consume tarima y cierre; la nueva devolvió su tarima.
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertInvariantes(100);

        // Deshacer: la congelada vuelve sin mover inventario; la nueva vuelve a llevar su tarima.
        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo")->assertOk();

        $this->assertTrue($congelada->fresh()->congelado());
        $this->assertSame(100, (int) $congelada->fresh()->total_cajas);
        $this->assertSame(80, (int) $nueva->fresh()->total_cajas);
        $this->assertNeto(-80, $this->cajaB15);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(0, $this->esquineros);
        $this->assertInvariantes();
    }

    public function test_mixto_solo_de_congeladas_consume_tarima_y_cierre(): void
    {
        $a = $this->crearPallet(['total_cajas' => 100, 'is_cola' => true]);
        $b = $this->crearPallet(['total_cajas' => 100, 'is_cola' => true]);

        $mixto = $this->mixtear([$a->id => 100, $b->id => 100]);

        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(0, $this->cajaB15);
        $this->assertSame(0, ProduccionEmpaqueConsumo::where('tipo', RecipeItem::CONSUMO_POR_CAJA)->count());
        $this->assertInvariantes(200);

        $this->deleteJson(self::URL."/{$mixto->id}")->assertOk();

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertTrue($a->fresh()->congelado());
        $this->assertTrue($b->fresh()->congelado());
        $this->assertInvariantes();
    }

    public function test_borrar_mixto_restaura_colas_sin_devolver_cajas(): void
    {
        $a = $this->cola(120);
        $b = $this->cola(100);
        $mixto = $this->mixtear([$a->id => 120, $b->id => 60]);

        $this->deleteJson(self::URL."/{$mixto->id}")->assertOk();

        $this->assertSoftDeleted($mixto);
        $this->assertSame(120, (int) $a->fresh()->total_cajas);
        $this->assertSame(100, (int) $b->fresh()->total_cajas);
        $this->assertNeto(-220, $this->cajaB15);
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(0, $this->esquineros);
        $this->assertInvariantes();

        // Borrar después las colas devuelve exactamente todo.
        $this->deleteJson(self::URL."/{$a->id}")->assertOk();
        $this->deleteJson(self::URL."/{$b->id}")->assertOk();
        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertInvariantes();
    }

    public function test_extraer_una_cola_del_mixto(): void
    {
        $a = $this->cola(120);
        // B con dos entradas de distinto calibre: sin A, el pallet sigue siendo mixto.
        $b = $this->cola(60);
        $this->postJson(self::URL."/{$b->id}/completar-cola", [
            'proceso_id' => $this->procesoId,
            'fecha_produccion' => '2026-02-04',
            'total_cajas' => 40,
            'peso_neto_kg' => 160,
        ])->assertOk();
        $b->detalles()->where('numero_entrada', 1)->update(['calibre' => '16']);
        $b->detalles()->where('numero_entrada', 2)->update(['calibre' => '18']);
        $mixto = $this->mixtear([$a->id => 120, $b->id => 100]);
        $this->assertSame(3, $mixto->detalles()->count());
        $this->assertNeto(-1, $this->tarima);
        $this->assertInvariantes();

        // Sale A: vuelve con su tarima; el mixto sigue armado y cerrado con B.
        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo-cola", ['source_cola_id' => $a->id])->assertOk();

        $this->assertSame(120, (int) $a->fresh()->total_cajas);
        $this->assertSame(100, (int) $mixto->fresh()->total_cajas);
        $this->assertTrue((bool) $mixto->fresh()->is_mixto);
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertInvariantes();

        // Sale B: el mixto queda vacío y se borra, devolviendo su tarima y su cierre.
        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo-cola", ['source_cola_id' => $b->id])->assertOk();

        $this->assertSoftDeleted($mixto);
        $this->assertSame(100, (int) $b->fresh()->total_cajas);
        $this->assertSame(2, $b->fresh()->detalles()->count());
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(0, $this->esquineros);
        $this->assertNeto(-220, $this->cajaB15);
        $this->assertInvariantes();
    }

    public function test_mixteo_rechazado_no_deja_nada_a_medias(): void
    {
        $a = $this->cola(120);
        $b = $this->cola(100);

        // El número de pallet ya existe: se detecta después de vaciar las colas y todo se revierte.
        $this->postJson(self::URL.'/mixtear-colas', [
            'mix_items' => [['cola_id' => $a->id, 'cajas' => 120], ['cola_id' => $b->id, 'cajas' => 50]],
            'numero_pallet' => $a->numero_pallet,
        ])->assertStatus(422);

        $this->assertFalse($a->fresh()->trashed());
        $this->assertSame(120, (int) $a->fresh()->total_cajas);
        $this->assertSame(100, (int) $b->fresh()->total_cajas);
        $this->assertSame(0, $a->fresh()->detalles()->count());
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(0, $this->esquineros);
        $this->assertNeto(-220, $this->cajaB15);
        $this->assertInvariantes();
    }

    public function test_cola_sin_entradas_en_el_mixto_no_revive_ni_consume_tarima(): void
    {
        $a = $this->cola(120);
        $b = $this->cola(100);
        $mixto = $this->mixtear([$a->id => 120, $b->id => 50]);
        $this->assertSoftDeleted($a);

        // Se borra del mixto la entrada que vino de A: devuelve sus 120 cajas; el JSON aún lista a A.
        $entradaDeA = $mixto->detalles()->where('total_cajas', 120)->sole();
        $this->deleteJson(self::URL."/{$mixto->id}/detalle/{$entradaDeA->id}")->assertOk();
        $this->assertNeto(-100, $this->cajaB15);
        $this->assertInvariantes();

        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo")->assertOk();

        // A no tiene nada que regresar: sigue borrada y no se lleva una tarima.
        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($mixto);
        $this->assertEqualsWithDelta(0, $this->cantidadVigente($a, $this->tarima, RecipeItem::CONSUMO_AL_ARMAR), self::TOL);
        $this->assertSame(100, (int) $b->fresh()->total_cajas);
        $this->assertNeto(-1, $this->tarima); // solo la de B, el único pallet vivo
        $this->assertNeto(0, $this->esquineros);
        $this->assertNeto(-100, $this->cajaB15);
        $this->assertFalse(ProduccionEmpaque::where('total_cajas', '<=', 0)->has('consumos')->exists());
        $this->assertInvariantes();
    }
}
