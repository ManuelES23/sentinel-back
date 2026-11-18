<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\InventoryStock;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueConsumo;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * Producción de Empaque por API: cada operación descuenta o devuelve con el registro de consumos
 * (por caja, al armar y al cerrar) y las correcciones regresan exactamente lo registrado.
 */
class ProduccionConsumoApiTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    private const URL = '/api/splendidfarms/operacion-agricola/empaque/produccion';
    private const INICIAL = 1000.0;

    private int $procesoId;
    private int $consecutivoApi = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->procesoId = $this->crearRecepcionConProduccion($this->productorPrincipal, 'API')['proceso']->id;
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

    private function surtirTodo(): void
    {
        foreach ($this->insumos() as $insumo) {
            $this->darExistencia($insumo, self::INICIAL);
        }
    }

    /** Consumo neto del insumo respecto a la existencia inicial (negativo = salió). */
    private function neto(Product $insumo): float
    {
        return round($this->existencia($insumo) - self::INICIAL, 4);
    }

    private function assertNeto(float $esperado, Product $insumo): void
    {
        $this->assertEqualsWithDelta($esperado, $this->neto($insumo), 0.0001, $insumo->name);
    }

    private function crear(array $datos = []): TestResponse
    {
        $n = ++$this->consecutivoApi;

        return $this->postJson(self::URL, array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->procesoId,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => sprintf('API-%04d', $n),
            'fecha_produccion' => '2026-02-03',
            'total_cajas' => 240,
            'peso_neto_kg' => 1000,
            'clasificacion' => 'convencional',
            'is_cola' => false,
        ], $datos));
    }

    private function crearOk(array $datos = []): ProduccionEmpaque
    {
        $response = $this->crear($datos);
        $response->assertCreated();

        return ProduccionEmpaque::findOrFail($response->json('data.id'));
    }

    private function completar(ProduccionEmpaque $cola, int $cajas, array $datos = []): TestResponse
    {
        return $this->postJson(self::URL."/{$cola->id}/completar-cola", array_merge([
            'proceso_id' => $this->procesoId,
            'fecha_produccion' => '2026-02-04',
            'total_cajas' => $cajas,
            'peso_neto_kg' => $cajas * 4,
        ], $datos));
    }

    private function otraReceta(array $atributos = []): Recipe
    {
        $receta = Recipe::create(array_merge([
            'enterprise_id' => $this->enterprise->id,
            'code' => 'REC-OTRA-'.uniqid(),
            'name' => 'Pallet otra caja',
            'recipe_type' => 'empaque',
            'output_quantity' => 1,
            'status' => 'active',
            'is_active' => true,
        ], $atributos));

        return $receta;
    }

    // ---- store ----

    public function test_store_pallet_completo_consume_por_caja_armar_y_cerrar(): void
    {
        $this->surtirTodo();

        $this->crear(['total_cajas' => 240])->assertCreated();

        $this->assertNeto(-240, $this->cajaB15);
        $this->assertNeto(-242, $this->etiqueta);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);
        $this->assertNeto(0, $this->cajaAlterna);
        $this->assertFalse(ProduccionEmpaqueConsumo::where('cantidad', '<=', 0)->exists());
    }

    public function test_store_cola_consume_por_caja_y_tarima_sin_cierre(): void
    {
        $this->surtirTodo();

        $this->crear(['total_cajas' => 60, 'is_cola' => true])->assertCreated();

        $this->assertNeto(-60, $this->cajaB15);
        $this->assertNeto(-60.5, $this->etiqueta);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(0, $this->esquineros);
        $this->assertNeto(0, $this->fleje);
        $this->assertNeto(0, $this->grapa);
    }

    // ---- completarCola ----

    public function test_completar_cola_hasta_cerrar_consume_cierre_una_vez(): void
    {
        $this->surtirTodo();
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true]);

        $this->completar($cola, 180)->assertOk();

        $cola->refresh();
        $this->assertFalse((bool) $cola->is_cola);
        $this->assertSame(240, (int) $cola->total_cajas);
        $this->assertNeto(-240, $this->cajaB15);
        $this->assertNeto(-242, $this->etiqueta);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);

        // La entrada base quedó con su consumo por caja (60) y la nueva con el suyo (180).
        $base = $cola->detalles()->where('numero_entrada', 1)->sole();
        $nueva = $cola->detalles()->where('numero_entrada', 2)->sole();
        $this->assertEqualsWithDelta(60, (float) ProduccionEmpaqueConsumo::where('detalle_id', $base->id)->where('product_id', $this->cajaB15->id)->sum('cantidad'), 0.0001);
        $this->assertEqualsWithDelta(180, (float) ProduccionEmpaqueConsumo::where('detalle_id', $nueva->id)->where('product_id', $this->cajaB15->id)->sum('cantidad'), 0.0001);
        $this->assertFalse(ProduccionEmpaqueConsumo::where('tipo', RecipeItem::CONSUMO_POR_CAJA)->whereNull('detalle_id')->exists());

        // Ya cerrado: otra entrada no cabe ni vuelve a consumir el cierre.
        $this->completar($cola, 1)->assertStatus(422);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-240, $this->cajaB15);
    }

    public function test_marcar_completo_con_menos_cajas_consume_cierre_completo(): void
    {
        $this->surtirTodo();
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true]);

        $this->completar($cola, 140, ['marcar_completo' => true])->assertOk();

        $this->assertFalse((bool) $cola->fresh()->is_cola);
        $this->assertNeto(-200, $this->cajaB15);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);
    }

    // ---- alternativas ----

    public function test_alternativa_elegida_se_descuenta(): void
    {
        $this->surtirTodo();

        $this->crear(['alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]]])->assertCreated();

        $this->assertNeto(-240, $this->cajaAlterna);
        $this->assertNeto(0, $this->cajaB15);
        $this->assertNeto(-242, $this->etiqueta);
    }

    public function test_sin_alternativas_usa_predeterminada(): void
    {
        $this->surtirTodo();

        // Front viejo: no manda `alternativas`.
        $response = $this->crear();
        $response->assertCreated();

        $this->assertNeto(-240, $this->cajaB15);
        $this->assertNeto(0, $this->cajaAlterna);
        $this->assertSame([['grupo' => 'Caja', 'product_id' => $this->cajaB15->id]], $response->json('data.alternativas'));
    }

    public function test_alternativa_de_otra_receta_da_422(): void
    {
        $this->surtirTodo();
        $otra = $this->otraReceta();
        $ajena = $this->renglonReceta($this->cajaAlterna, 240, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => true], $otra);

        $this->crear(['alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $ajena->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alternativas.Caja']);

        $this->assertSame(0, ProduccionEmpaque::where('numero_pallet', 'API-0001')->count());
        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
    }

    // ---- receta ----

    public function test_receta_inactiva_o_borrador_da_422(): void
    {
        $this->surtirTodo();
        $borrador = $this->otraReceta(['status' => 'draft']);
        $inactiva = $this->otraReceta(['is_active' => false]);
        $agricola = $this->otraReceta(['recipe_type' => 'agricola']);

        foreach ([$borrador, $inactiva, $agricola] as $receta) {
            $this->crear(['recipe_id' => $receta->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['recipe_id' => 'La receta no está activa o no es de empaque.']);
        }

        // En update solo se valida si cambia: una receta ya guardada que se desactiva no estorba.
        $pallet = $this->crearOk();
        $this->receta->update(['is_active' => false]);
        $this->putJson(self::URL."/{$pallet->id}", ['recipe_id' => $this->receta->id, 'observaciones' => 'ok'])->assertOk();
        $this->putJson(self::URL."/{$pallet->id}", ['recipe_id' => $borrador->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipe_id' => 'La receta no está activa o no es de empaque.']);
    }

    public function test_pallet_sin_receta_da_422(): void
    {
        $this->surtirTodo();

        $this->crear(['recipe_id' => null])->assertStatus(422)->assertJsonValidationErrors(['recipe_id']);

        // Cola sin receta propia: la entrada debe traerla.
        $cola = $this->crearPallet(['recipe_id' => null, 'is_cola' => true, 'total_cajas' => 60]);
        $this->completar($cola, 60)->assertStatus(422)->assertJsonValidationErrors(['recipe_id']);

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
    }

    // ---- update ----

    public function test_update_cajas_devuelve_y_reconsume(): void
    {
        $this->surtirTodo();
        $pallet = $this->crearOk(['total_cajas' => 240]);

        $this->putJson(self::URL."/{$pallet->id}", ['total_cajas' => 200])->assertOk();

        $this->assertNeto(-200, $this->cajaB15);
        $this->assertNeto(-201.6667, $this->etiqueta);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);

        // Cambiar la alternativa también devuelve y reconsume.
        $this->putJson(self::URL."/{$pallet->id}", ['alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]]])->assertOk();
        $this->assertNeto(0, $this->cajaB15);
        $this->assertNeto(-200, $this->cajaAlterna);
        $this->assertNeto(-1, $this->tarima);

        // Sin cambios de inventario: nada se mueve y la alternativa registrada se conserva.
        $this->putJson(self::URL."/{$pallet->id}", ['observaciones' => 'sin cambios'])->assertOk()
            ->assertJsonPath('data.alternativas', [['grupo' => 'Caja', 'product_id' => $this->cajaAlterna->id]]);
        $this->assertNeto(-200, $this->cajaAlterna);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_update_is_cola_a_false_consume_cierre_y_a_true_lo_devuelve(): void
    {
        $this->surtirTodo();
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true]);

        $this->putJson(self::URL."/{$cola->id}", ['is_cola' => false])->assertOk();
        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-40, $this->fleje);
        $this->assertNeto(-8, $this->grapa);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-60, $this->cajaB15);

        $this->putJson(self::URL."/{$cola->id}", ['is_cola' => true])->assertOk();
        $this->assertNeto(0, $this->esquineros);
        $this->assertNeto(0, $this->fleje);
        $this->assertNeto(0, $this->grapa);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-60, $this->cajaB15);
    }

    public function test_update_con_entradas_no_cambia_receta_ni_alternativas(): void
    {
        $this->surtirTodo();
        $otra = $this->otraReceta();
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true]);
        $this->completar($cola, 100)->assertOk();

        $this->putJson(self::URL."/{$cola->id}", ['recipe_id' => $otra->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipe_id' => 'Cambia la receta en la entrada']);
        $this->putJson(self::URL."/{$cola->id}", ['alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipe_id' => 'Cambia la receta en la entrada']);

        // La misma receta y la alternativa ya registrada no son cambio; is_cola sí aplica.
        $this->putJson(self::URL."/{$cola->id}", [
            'recipe_id' => $this->receta->id,
            'alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCaja->id]],
            'is_cola' => false,
        ])->assertOk();

        $this->assertNeto(-160, $this->cajaB15);
        $this->assertNeto(-1, $this->tarima);
        $this->assertNeto(-4, $this->esquineros);
    }

    public function test_cambiar_planta_devuelve_en_la_vieja_y_consume_en_la_nueva(): void
    {
        $this->surtirTodo();
        $otra = $this->entity->replicate();
        $otra->fill(['code' => 'EMP-002', 'name' => 'Empaque Norte', 'slug' => 'empaque-norte'])->save();
        $enOtra = fn (Product $insumo) => (float) (InventoryStock::where('product_id', $insumo->id)->where('entity_id', $otra->id)->value('quantity') ?? 0);

        // Ambos se crean antes de mover (el folio consecutivo es por planta).
        $pallet = $this->crearOk(['alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]]]);
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true, 'alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]]]);
        $this->completar($cola, 100)->assertOk();
        $this->assertNeto(-300, $this->cajaAlterna);
        $this->assertNeto(-100, $this->cajaB15);
        $this->assertNeto(-2, $this->tarima);

        // Sin entradas.
        $this->putJson(self::URL."/{$pallet->id}", ['entity_id' => $otra->id])->assertOk();
        $this->assertNeto(-60, $this->cajaAlterna);
        $this->assertNeto(0, $this->esquineros);
        $this->assertEqualsWithDelta(-240, $enOtra($this->cajaAlterna), 0.0001);
        $this->assertEqualsWithDelta(0, $enOtra($this->cajaB15), 0.0001);
        $this->assertEqualsWithDelta(-4, $enOtra($this->esquineros), 0.0001);

        // Con entradas: cada entrada con sus alternativas, más la tarima de la cola.
        $this->putJson(self::URL."/{$cola->id}", ['entity_id' => $otra->id])->assertOk();

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertEqualsWithDelta(-300, $enOtra($this->cajaAlterna), 0.0001);
        $this->assertEqualsWithDelta(-100, $enOtra($this->cajaB15), 0.0001);
        $this->assertEqualsWithDelta(-2, $enOtra($this->tarima), 0.0001);
        $this->assertEqualsWithDelta(-4, $enOtra($this->esquineros), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    /** El editor de recetas borra y recrea los renglones: mismos productos, ids nuevos. Devuelve la caja alterna nueva. */
    private function recrearRenglones(): RecipeItem
    {
        $this->receta->items()->delete();
        $this->renglonReceta($this->tarima, 1, RecipeItem::CONSUMO_AL_ARMAR);
        $this->renglonReceta($this->esquineros, 4, RecipeItem::CONSUMO_AL_CERRAR);
        $this->renglonReceta($this->fleje, 40, RecipeItem::CONSUMO_AL_CERRAR);
        $this->renglonReceta($this->grapa, 8, RecipeItem::CONSUMO_AL_CERRAR);
        $this->renglonReceta($this->etiqueta, 242, RecipeItem::CONSUMO_POR_CAJA);
        $this->renglonReceta($this->cajaB15, 240, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => true]);
        $alterna = $this->renglonReceta($this->cajaAlterna, 240, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => false]);
        $this->assertNotSame($this->itemCajaAlterna->id, $alterna->id);

        return $alterna;
    }

    public function test_alternativa_equivalente_tras_recrear_receta_no_es_cambio(): void
    {
        $this->surtirTodo();
        $elegida = [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]];
        $sinEntradas = $this->crearOk(['total_cajas' => 60, 'is_cola' => true, 'alternativas' => $elegida]);
        $conEntradas = $this->crearOk(['total_cajas' => 60, 'is_cola' => true, 'alternativas' => $elegida]);
        $this->completar($conEntradas, 100, ['alternativas' => $elegida])->assertOk();
        $alterna = $this->recrearRenglones();
        $equivalente = [['grupo' => 'Caja', 'recipe_item_id' => $alterna->id]];

        // Con entradas: la misma elección con el id nuevo no es cambio de alternativa (no 422).
        $this->putJson(self::URL."/{$conEntradas->id}", ['alternativas' => $equivalente, 'is_cola' => false])->assertOk();
        // Sin entradas: tampoco devuelve ni reconsume las cajas; solo se mueve el cierre.
        $this->putJson(self::URL."/{$sinEntradas->id}", ['alternativas' => $equivalente, 'is_cola' => false])->assertOk();

        $this->assertNeto(-220, $this->cajaAlterna);
        $this->assertNeto(0, $this->cajaB15);
        $this->assertNeto(-2, $this->tarima);
        $this->assertNeto(-8, $this->esquineros);
        $this->assertNeto(-80, $this->fleje);
        $this->assertSame(0, ProduccionEmpaqueConsumo::where('cantidad_devuelta', '>', 0)->count());
    }

    public function test_cambiar_planta_tras_recrear_receta_conserva_alternativa(): void
    {
        $this->surtirTodo();
        $otra = $this->entity->replicate();
        $otra->fill(['code' => 'EMP-002', 'name' => 'Empaque Norte', 'slug' => 'empaque-norte'])->save();
        $enOtra = fn (Product $insumo) => (float) (InventoryStock::where('product_id', $insumo->id)->where('entity_id', $otra->id)->value('quantity') ?? 0);
        $elegida = [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]];

        $pallet = $this->crearOk(['alternativas' => $elegida]);
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true, 'alternativas' => $elegida]);
        $this->completar($cola, 100, ['alternativas' => $elegida])->assertOk();
        $this->recrearRenglones();

        // El front viejo no manda alternativas: lo registrado se traduce al renglón actual.
        $this->putJson(self::URL."/{$pallet->id}", ['entity_id' => $otra->id])->assertOk();
        $this->putJson(self::URL."/{$cola->id}", ['entity_id' => $otra->id])->assertOk();

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertEqualsWithDelta(-400, $enOtra($this->cajaAlterna), 0.0001);
        $this->assertEqualsWithDelta(0, $enOtra($this->cajaB15), 0.0001);
        $this->assertEqualsWithDelta(-2, $enOtra($this->tarima), 0.0001);
    }

    // ---- entradas ----

    public function test_update_detalle_cambia_cajas_y_receta(): void
    {
        $this->surtirTodo();
        $otra = $this->otraReceta();
        $cajaOtra = $this->insumoEmpaque('INS-CAJ3', 'Caja de cartón 4 KG');
        $this->darExistencia($cajaOtra, self::INICIAL);
        $this->renglonReceta($cajaOtra, 120, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => true], $otra);

        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true]);
        $this->completar($cola, 100)->assertOk();
        $entrada = $cola->detalles()->where('numero_entrada', 2)->sole();

        $this->patchJson(self::URL."/{$cola->id}/detalle/{$entrada->id}", ['total_cajas' => 80])->assertOk();
        $this->assertNeto(-140, $this->cajaB15);
        $this->assertNeto(-141.1667, $this->etiqueta);

        $this->patchJson(self::URL."/{$cola->id}/detalle/{$entrada->id}", ['recipe_id' => $otra->id])->assertOk();
        $this->assertNeto(-60, $this->cajaB15);
        $this->assertNeto(-60.5, $this->etiqueta);
        $this->assertNeto(-80, $cajaOtra);
        $this->assertNeto(-1, $this->tarima);

        // Receta no válida en la entrada.
        $borrador = $this->otraReceta(['status' => 'draft']);
        $this->patchJson(self::URL."/{$cola->id}/detalle/{$entrada->id}", ['recipe_id' => $borrador->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipe_id' => 'La receta no está activa o no es de empaque.']);
        $this->assertNeto(-80, $cajaOtra);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_destroy_detalle_devuelve_su_entrada(): void
    {
        $this->surtirTodo();
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true]);
        $this->completar($cola, 100)->assertOk();
        $entrada = $cola->detalles()->where('numero_entrada', 2)->sole();

        $this->deleteJson(self::URL."/{$cola->id}/detalle/{$entrada->id}")->assertOk();

        $this->assertNeto(-60, $this->cajaB15);
        $this->assertNeto(-60.5, $this->etiqueta);
        $this->assertNeto(-1, $this->tarima);
        $this->assertSame(60, (int) $cola->fresh()->total_cajas);
    }

    // ---- destroy ----

    public function test_destroy_devuelve_todo_exacto_tras_editar_receta(): void
    {
        $this->surtirTodo();
        $pallet = $this->crearOk(['alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]]]);

        // El editor de recetas borra y recrea los renglones con otras cantidades.
        $this->receta->items()->delete();
        $this->renglonReceta($this->tarima, 3, RecipeItem::CONSUMO_AL_ARMAR);
        $this->renglonReceta($this->fleje, 99, RecipeItem::CONSUMO_AL_CERRAR);
        $this->renglonReceta($this->cajaB15, 120, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => true]);

        $this->deleteJson(self::URL."/{$pallet->id}")->assertOk();

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertFalse(ProduccionEmpaqueConsumo::vigentes()->exists());
        $this->assertSoftDeleted('produccion_empaque', ['id' => $pallet->id]);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }

    public function test_embarcado_no_se_borra_ni_su_entrada(): void
    {
        $this->surtirTodo();
        $cola = $this->crearOk(['total_cajas' => 60, 'is_cola' => true]);
        $this->completar($cola, 100)->assertOk();
        $entrada = $cola->detalles()->where('numero_entrada', 2)->sole();
        $cola->update(['status' => 'embarcado']);

        $this->deleteJson(self::URL."/{$cola->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar un pallet que ya está embarcado');
        $this->deleteJson(self::URL."/{$cola->id}/detalle/{$entrada->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar un pallet que ya está embarcado');

        $this->assertNotSoftDeleted('produccion_empaque', ['id' => $cola->id]);
        $this->assertNotNull($entrada->fresh());
        $this->assertNeto(-160, $this->cajaB15);
    }

    // ---- congelados ----

    public function test_pallet_congelado_no_mueve_inventario(): void
    {
        $this->surtirTodo();
        $pallet = $this->crearPallet(['total_cajas' => 240, 'is_cola' => true]);
        $this->assertTrue($pallet->congelado());

        $this->putJson(self::URL."/{$pallet->id}", ['total_cajas' => 200, 'is_cola' => false])->assertOk();
        $this->assertSame(200, (int) $pallet->fresh()->total_cajas);

        $cola = $this->crearPallet(['total_cajas' => 60, 'is_cola' => true]);
        $this->completar($cola, 100)->assertOk();
        $entrada = $cola->detalles()->where('numero_entrada', 2)->sole();
        $this->patchJson(self::URL."/{$cola->id}/detalle/{$entrada->id}", ['total_cajas' => 80])->assertOk();
        $this->deleteJson(self::URL."/{$cola->id}/detalle/{$entrada->id}")->assertOk();

        $this->deleteJson(self::URL."/{$pallet->id}")->assertOk();
        $this->deleteJson(self::URL."/{$cola->id}")->assertOk();

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertSame(0, ProduccionEmpaqueConsumo::count());
    }

    // ---- respuesta ----

    public function test_respuesta_trae_faltantes_y_alternativas(): void
    {
        foreach ([$this->tarima, $this->esquineros, $this->fleje, $this->grapa, $this->etiqueta, $this->cajaB15] as $insumo) {
            $this->darExistencia($insumo, self::INICIAL);
        }

        $response = $this->crear([
            'total_cajas' => 60,
            'is_cola' => true,
            'alternativas' => [['grupo' => 'Caja', 'recipe_item_id' => $this->itemCajaAlterna->id]],
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.alternativas', [['grupo' => 'Caja', 'product_id' => $this->cajaAlterna->id]])
            ->assertJsonCount(1, 'faltantes')
            ->assertJsonPath('faltantes.0.producto_id', $this->cajaAlterna->id)
            ->assertJsonPath('faltantes.0.almacen', 'Empaque Principal');
        $this->assertEqualsWithDelta(-60, (float) $response->json('faltantes.0.existencia'), 0.0001);

        $cola = ProduccionEmpaque::findOrFail($response->json('data.id'));
        $completar = $this->completar($cola, 100);
        $completar->assertOk()->assertJsonPath('faltantes', []);
        $detalles = collect($completar->json('data.detalles'))->keyBy('numero_entrada');
        $this->assertSame([['grupo' => 'Caja', 'product_id' => $this->cajaAlterna->id]], $detalles[1]['alternativas']);
        $this->assertSame([['grupo' => 'Caja', 'product_id' => $this->cajaB15->id]], $detalles[2]['alternativas']);

        $entrada = $cola->detalles()->where('numero_entrada', 2)->sole();
        $this->patchJson(self::URL."/{$cola->id}/detalle/{$entrada->id}", ['total_cajas' => 90])
            ->assertOk()->assertJsonPath('faltantes', []);
        $this->putJson(self::URL."/{$cola->id}", ['observaciones' => 'x'])
            ->assertOk()->assertJsonPath('faltantes', []);
        $this->deleteJson(self::URL."/{$cola->id}/detalle/{$entrada->id}")
            ->assertOk()->assertJsonPath('faltantes', []);

        // El listado también expone las alternativas registradas.
        $listado = collect($this->getJson(self::URL)->assertOk()->json('data'))->firstWhere('id', $cola->id);
        $this->assertSame([['grupo' => 'Caja', 'product_id' => $this->cajaAlterna->id]], $listado['detalles'][0]['alternativas']);
    }
}
