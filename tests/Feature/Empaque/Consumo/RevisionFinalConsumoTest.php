<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\Application;
use App\Models\InventoryKardex;
use App\Models\InventoryStock;
use App\Models\Module;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueConsumo;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\UserSubmodulePermission;
use App\Services\Empaque\ConsumoProduccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesDestinoEmbarque;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * Arreglos de la revisión final del consumo de insumos: purga de consumos al eliminar un folio
 * consumido, orden de bloqueos por producto, guardas de embarcado, folio tras cambiar de planta,
 * relectura del pallet bajo bloqueo y entradas congeladas dentro de un pallet nuevo.
 */
class RevisionFinalConsumoTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures, CreatesDestinoEmbarque;

    private const URL = '/api/splendidfarms/operacion-agricola/empaque/produccion';
    private const EMBARQUES = '/api/splendidfarms/operacion-agricola/empaque/embarques';
    private const INICIAL = 1000.0;

    private ProcesoEmpaque $proceso;
    private int $consecutivoApi = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'API')['proceso'];

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

    private function assertNeto(float $esperado, Product $insumo): void
    {
        $this->assertEqualsWithDelta($esperado, round($this->existencia($insumo) - self::INICIAL, 4), 0.0001, $insumo->name);
    }

    /** @return array<int, float> product_id => existencia */
    private function existencias(): array
    {
        return collect($this->insumos())->mapWithKeys(fn (Product $p) => [$p->id => $this->existencia($p)])->all();
    }

    private function crear(array $datos = []): TestResponse
    {
        $n = ++$this->consecutivoApi;

        return $this->postJson(self::URL, array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $this->proceso->id,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => sprintf('RF-%04d', $n),
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

    private function cola(int $cajas): ProduccionEmpaque
    {
        return $this->crearOk(['total_cajas' => $cajas, 'is_cola' => true, 'peso_neto_kg' => $cajas * 4]);
    }

    private function completar(ProduccionEmpaque $cola, int $cajas, array $datos = []): TestResponse
    {
        return $this->postJson(self::URL."/{$cola->id}/completar-cola", array_merge([
            'proceso_id' => $this->proceso->id,
            'fecha_produccion' => '2026-02-04',
            'total_cajas' => $cajas,
            'peso_neto_kg' => $cajas * 4,
        ], $datos));
    }

    /** @param array<int, int> $tomas cola_id => cajas */
    private function mixtear(array $tomas): TestResponse
    {
        return $this->postJson(self::URL.'/mixtear-colas', [
            'mix_items' => collect($tomas)->map(fn ($cajas, $id) => ['cola_id' => $id, 'cajas' => $cajas])->values()->all(),
        ]);
    }

    /**
     * Ejecuta $accion justo después de la primera carga de un pallet a partir de ahora (el enlace de
     * ruta o la consulta inicial del controlador): simula otra petición que cambia el pallet entre la
     * carga inicial y el bloqueo.
     */
    private function trasCargarPallet(callable $accion): void
    {
        $hecho = false;
        Event::listen('eloquent.retrieved: '.ProduccionEmpaque::class, function () use (&$hecho, $accion) {
            if ($hecho) {
                return;
            }
            $hecho = true;
            $accion();
        });
    }

    // ---- B1: eliminar folio consumido purga los consumos de sus pallets borrados ----

    private function permitirEliminarConsumido(): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'operacion-agricola'],
            ['name' => 'Operación agrícola', 'path' => '/sf/operacion-agricola', 'description' => 'oa'],
        );
        $modulo = Module::firstOrCreate(['application_id' => $app->id, 'slug' => 'empaque'], ['name' => 'Empaque']);
        $sub = Submodule::firstOrCreate(['module_id' => $modulo->id, 'slug' => 'proceso'], ['name' => 'Proceso']);
        $tipo = SubmodulePermissionType::firstOrCreate(
            ['submodule_id' => $sub->id, 'slug' => 'delete_procesado'],
            ['name' => 'Eliminar procesado', 'is_active' => true],
        );
        UserSubmodulePermission::updateOrCreate(
            ['user_id' => $this->actingUser->id, 'submodule_id' => $sub->id, 'permission_type_id' => $tipo->id],
            ['is_granted' => true],
        );
    }

    /** Folio procesado cuyos pallets (el del fixture y una cola con registro) están borrados. */
    private function folioConsumidoConPalletBorrado(): ProduccionEmpaque
    {
        $this->permitirEliminarConsumido();
        $cola = $this->cola(60);
        $this->deleteJson(self::URL."/{$cola->id}")->assertOk();
        ProduccionEmpaque::where('proceso_id', $this->proceso->id)->whereNull('deleted_at')->get()->each->delete();
        $this->proceso->update(['status' => 'procesado']);

        return $cola;
    }

    public function test_eliminar_folio_consumido_purga_los_consumos_de_sus_pallets_borrados(): void
    {
        $cola = $this->folioConsumidoConPalletBorrado();
        $this->assertTrue(ProduccionEmpaqueConsumo::where('produccion_id', $cola->id)->exists());

        $this->deleteJson("/api/splendidfarms/operacion-agricola/empaque/proceso/{$this->proceso->id}/eliminar-consumido")
            ->assertOk();

        $this->assertFalse(ProduccionEmpaqueConsumo::where('produccion_id', $cola->id)->exists());
        $this->assertNull(ProduccionEmpaque::withTrashed()->find($cola->id));
        $this->assertNull(ProcesoEmpaque::withTrashed()->find($this->proceso->id));
        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
    }

    public function test_eliminar_folio_consumido_no_purga_un_pallet_con_consumo_vigente(): void
    {
        $cola = $this->folioConsumidoConPalletBorrado();
        // Caso anómalo: un pallet borrado que aún tiene consumo sin devolver.
        ProduccionEmpaqueConsumo::where('produccion_id', $cola->id)->limit(1)->update(['cantidad_devuelta' => 0]);

        $this->deleteJson("/api/splendidfarms/operacion-agricola/empaque/proceso/{$this->proceso->id}/eliminar-consumido")
            ->assertStatus(422);

        $this->assertTrue(ProduccionEmpaqueConsumo::where('produccion_id', $cola->id)->exists());
        $this->assertNotNull(ProduccionEmpaque::withTrashed()->find($cola->id));
        $this->assertNotNull(ProcesoEmpaque::find($this->proceso->id));
    }

    // ---- B2: orden de bloqueo por producto al consumir y al devolver ----

    public function test_consumir_y_devolver_tocan_los_insumos_en_orden_de_producto(): void
    {
        // Renglones insertados con product_id descendente: el orden de la receta no debe mandar.
        $receta = Recipe::create([
            'enterprise_id' => $this->enterprise->id, 'code' => 'REC-ORDEN', 'name' => 'Receta en desorden',
            'recipe_type' => 'empaque', 'output_quantity' => 1, 'status' => 'active', 'is_active' => true,
        ]);
        $this->renglonReceta($this->grapa, 8, RecipeItem::CONSUMO_AL_CERRAR, [], $receta);
        $this->renglonReceta($this->fleje, 40, RecipeItem::CONSUMO_AL_CERRAR, [], $receta);
        $this->renglonReceta($this->esquineros, 4, RecipeItem::CONSUMO_AL_CERRAR, [], $receta);
        $this->renglonReceta($this->cajaB15, 240, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => true], $receta);
        $this->renglonReceta($this->etiqueta, 242, RecipeItem::CONSUMO_POR_CAJA, [], $receta);
        $receta->load('items');

        $pallet = $this->crearPallet();
        $servicio = app(ConsumoProduccion::class);
        $tocados = fn (int $desde, string $tipo) => InventoryKardex::where('id', '>', $desde)
            ->where('transaction_type', $tipo)->orderBy('id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $ascendente = function (array $ids) {
            $orden = $ids;
            sort($orden);

            return $orden;
        };

        $inicio = (int) InventoryKardex::max('id');
        DB::transaction(fn () => $servicio->consumirPorCaja($pallet, null, $receta, 10, []));
        $porCaja = $tocados($inicio, 'decrease');
        $this->assertCount(2, $porCaja);
        $this->assertSame($ascendente($porCaja), $porCaja);

        $inicio = (int) InventoryKardex::max('id');
        DB::transaction(fn () => $servicio->consumirPorPallet($pallet, $receta, RecipeItem::CONSUMO_AL_CERRAR));
        $cierre = $tocados($inicio, 'decrease');
        $this->assertCount(3, $cierre);
        $this->assertSame($ascendente($cierre), $cierre);

        $inicio = (int) InventoryKardex::max('id');
        DB::transaction(fn () => $servicio->devolverTodo($pallet));
        $devueltos = $tocados($inicio, 'increase');
        $this->assertCount(5, $devueltos);
        $this->assertSame($ascendente($devueltos), $devueltos);
    }

    // ---- B3: guardas de embarcado ----

    public function test_completar_cola_embarcada_da_422_sin_tocar_nada(): void
    {
        $cola = $this->cola(60);
        $cola->update(['status' => 'embarcado']);
        $antes = $this->existencias();

        $this->completar($cola, 180)->assertStatus(422)
            ->assertJsonPath('message', 'No se puede completar un pallet que ya está embarcado');

        $this->assertSame(0, $cola->detalles()->count());
        $this->assertSame($antes, $this->existencias());
    }

    public function test_mixtear_con_una_cola_embarcada_da_422_sin_tocar_nada(): void
    {
        $a = $this->cola(60);
        $b = $this->cola(60);
        $b->update(['status' => 'embarcado']);
        $antes = $this->existencias();

        $this->mixtear([$a->id => 60, $b->id => 60])->assertStatus(422)
            ->assertJsonPath('message', 'No se puede mixtear un pallet que ya está embarcado');

        $this->assertSame(60, (int) $a->fresh()->total_cajas);
        $this->assertSame(60, (int) $b->fresh()->total_cajas);
        $this->assertNotSoftDeleted('produccion_empaque', ['id' => $b->id]);
        $this->assertSame($antes, $this->existencias());
    }

    public function test_revertir_mixteo_embarcado_da_422_sin_tocar_nada(): void
    {
        $a = $this->cola(60);
        $b = $this->cola(60);
        $mixto = ProduccionEmpaque::findOrFail($this->mixtear([$a->id => 60, $b->id => 60])->assertOk()->json('data.id'));
        $mixto->update(['status' => 'embarcado']);
        $antes = $this->existencias();

        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo")->assertStatus(422)
            ->assertJsonPath('message', 'No se puede deshacer el mixteo de un pallet que ya está embarcado');

        $this->assertNotSoftDeleted('produccion_empaque', ['id' => $mixto->id]);
        $this->assertSame($antes, $this->existencias());
    }

    public function test_revertir_mixteo_cola_embarcado_da_422_sin_tocar_nada(): void
    {
        $a = $this->cola(60);
        $b = $this->cola(60);
        $mixto = ProduccionEmpaque::findOrFail($this->mixtear([$a->id => 60, $b->id => 60])->assertOk()->json('data.id'));
        $mixto->update(['status' => 'embarcado']);
        $antes = $this->existencias();

        $this->postJson(self::URL."/{$mixto->id}/revertir-mixteo-cola", ['source_cola_id' => $a->id])->assertStatus(422)
            ->assertJsonPath('message', 'No se puede deshacer el mixteo de un pallet que ya está embarcado');

        $this->assertSame(120, (int) $mixto->fresh()->total_cajas);
        $this->assertSame($antes, $this->existencias());
    }

    // ---- B4: folio tras mover un pallet de planta ----

    public function test_folio_no_colisiona_tras_mover_el_ultimo_pallet_a_otra_planta(): void
    {
        $otra = $this->entity->replicate();
        $otra->fill(['code' => 'EMP-002', 'name' => 'Empaque Norte', 'slug' => 'empaque-norte'])->save();

        $primero = $this->crearOk();
        $this->putJson(self::URL."/{$primero->id}", ['entity_id' => $otra->id])->assertOk();

        $segundo = $this->crear();
        $segundo->assertCreated();

        $this->assertNotSame($primero->folio_produccion, $segundo->json('data.folio_produccion'));
        $this->assertStringStartsWith('PROD-'.str_pad((string) $this->entity->id, 2, '0', STR_PAD_LEFT).'-', $segundo->json('data.folio_produccion'));
    }

    // ---- B5: relectura del pallet bajo bloqueo ----

    public function test_completar_cola_embarcada_entre_la_carga_y_el_bloqueo_da_422(): void
    {
        $cola = $this->cola(60);
        $antes = $this->existencias();

        $this->trasCargarPallet(fn () => DB::table('produccion_empaque')->where('id', $cola->id)->update(['status' => 'embarcado']));

        $this->completar($cola, 180)->assertStatus(422)
            ->assertJsonPath('message', 'No se puede completar un pallet que ya está embarcado');

        $this->assertSame(0, $cola->detalles()->count());
        $this->assertSame($antes, $this->existencias());
    }

    public function test_completar_cola_cerrada_entre_la_carga_y_el_bloqueo_no_consume_cierre_dos_veces(): void
    {
        $cola = $this->cola(60);
        $this->completar($cola, 100, ['marcar_completo' => true])->assertOk();
        $this->assertNeto(-4, $this->esquineros);

        // Doble envío: la segunda petición vio la cola abierta antes de que la primera la cerrara.
        DB::table('produccion_empaque')->where('id', $cola->id)->update(['is_cola' => true]);
        $this->trasCargarPallet(fn () => DB::table('produccion_empaque')->where('id', $cola->id)->update(['is_cola' => false]));

        $this->completar($cola, 10)->assertStatus(422);

        $this->assertNeto(-4, $this->esquineros);
        $this->assertNeto(-160, $this->cajaB15);
        $this->assertSame(2, $cola->detalles()->count());
    }

    public function test_embarque_relee_la_cola_bajo_bloqueo(): void
    {
        $cola = $this->cola(60);

        // Se cierra entre la carga del embarque y su bloqueo: ya no es cola abierta y no consume cierre.
        $this->trasCargarPallet(fn () => DB::table('produccion_empaque')->where('id', $cola->id)->update(['is_cola' => false]));

        $this->postJson(self::EMBARQUES, [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'tipo_venta' => 'nacional',
            'fecha_embarque' => '2026-02-10',
            'destino_embarque_id' => $this->destinoEmbarqueId(),
            'pallets' => [['id' => $cola->id, 'posicion_carga' => 1]],
        ])->assertCreated();

        $this->assertNeto(0, $this->esquineros);
        $this->assertSame('embarcado', $cola->fresh()->status);
    }

    // ---- m1: entrada congelada dentro de un pallet con registro ----

    public function test_entrada_congelada_en_pallet_nuevo_no_mueve_inventario(): void
    {
        $otra = $this->entity->replicate();
        $otra->fill(['code' => 'EMP-003', 'name' => 'Empaque Sur', 'slug' => 'empaque-sur'])->save();
        $enOtra = fn (Product $insumo) => (float) (InventoryStock::where('product_id', $insumo->id)->where('entity_id', $otra->id)->value('quantity') ?? 0);

        $cola = $this->cola(60);
        $this->completar($cola, 100)->assertOk();
        // Entrada que llegó de una cola congelada: sin renglones de consumo.
        $congelada = $this->crearEntrada($cola, 3, 80);
        $antes = $this->existencias();
        $renglones = ProduccionEmpaqueConsumo::count();

        $this->patchJson(self::URL."/{$cola->id}/detalle/{$congelada->id}", ['total_cajas' => 81])->assertOk();

        $this->assertSame($antes, $this->existencias());
        $this->assertSame($renglones, ProduccionEmpaqueConsumo::count());

        // Cambio de planta: solo se reconsume lo que tenía registro (60 + 100 cajas y la tarima).
        $this->putJson(self::URL."/{$cola->id}", ['entity_id' => $otra->id])->assertOk();

        foreach ($this->insumos() as $insumo) {
            $this->assertNeto(0, $insumo);
        }
        $this->assertEqualsWithDelta(-160, $enOtra($this->cajaB15), 0.0001);
        $this->assertEqualsWithDelta(-1, $enOtra($this->tarima), 0.0001);
        $this->artisan('inventario:verificar-capas')->assertExitCode(0);
    }
}
