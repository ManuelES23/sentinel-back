<?php

namespace Tests\Feature\Inventario;

use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductCategory;
use Database\Seeders\MovementTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesProduccionEmpaqueFixtures;
use Tests\TestCase;

/**
 * inventario:reiniciar-existencias: lleva a 0 las existencias de «Consumibles producto terminado»
 * (negativas con un Ajuste Positivo al costo del faltante, positivas con un Ajuste Negativo por PEPS),
 * por almacén y aprobados, sin tocar otras categorías. Sin --confirmar no cambia nada.
 */
class ReiniciarExistenciasTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    private const PREGUNTA = 'Se dejarán en 0 estas existencias con ajustes aprobados en 1 almacén. ¿Continuar?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProduccionEmpaqueFixtures();
        $this->seed(MovementTypeSeeder::class);
        $this->actingUser->update(['role' => 'admin']);
        Sanctum::actingAs($this->actingUser);

        $consumibles = ProductCategory::create(['name' => 'Consumibles producto terminado', 'code' => 'CAT-CPT']);
        $otra = ProductCategory::create(['name' => 'Otros', 'code' => 'CAT-OTR']);
        foreach ([$this->tarima, $this->esquineros, $this->fleje, $this->etiqueta, $this->cajaB15, $this->cajaAlterna] as $insumo) {
            $insumo->update(['category_id' => $consumibles->id]);
        }
        $this->grapa->update(['category_id' => $otra->id]);

        // Tarima y caja alterna quedan positivas; lo demás se va a negativo al producir.
        $this->darExistencia($this->tarima, 10.0, 3.0);
        $this->darExistencia($this->cajaAlterna, 50.0, 4.0);
        $this->darExistencia($this->etiqueta, 100.0, 0.5);
        $proceso = $this->crearRecepcionConProduccion($this->productorPrincipal, 'API')['proceso'];
        $this->app->forgetScopedInstances();
        $this->postJson('/api/splendidfarms/operacion-agricola/empaque/produccion', [
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'recipe_id' => $this->receta->id,
            'numero_pallet' => 'API-0001',
            'fecha_produccion' => '2026-02-03',
            'total_cajas' => 240,
            'peso_neto_kg' => 1000,
            'clasificacion' => 'convencional',
            'is_cola' => false,
        ])->assertCreated();
    }

    private function fila($producto): InventoryStock
    {
        return InventoryStock::where('product_id', $producto->id)->where('entity_id', $this->entity->id)->firstOrFail();
    }

    public function test_sin_confirmar_solo_muestra_la_lista(): void
    {
        $antes = InventoryStock::orderBy('id')->pluck('quantity', 'id')->all();

        $this->artisan('inventario:reiniciar-existencias')
            ->expectsOutputToContain('Nada se modificó')
            ->assertSuccessful();

        $this->assertSame($antes, InventoryStock::orderBy('id')->pluck('quantity', 'id')->all());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_confirmado_deja_en_cero_negativos_y_positivos(): void
    {
        $this->assertGreaterThan(0, $this->existencia($this->tarima));
        $this->assertLessThan(0, $this->existencia($this->etiqueta));
        $etiquetaAntes = $this->existencia($this->etiqueta);
        $tarimaAntes = $this->existencia($this->tarima);

        $this->artisan('inventario:reiniciar-existencias', ['--confirmar' => true, '--nota' => 'Inicio elote 2026-2027'])
            ->expectsConfirmation(self::PREGUNTA, 'yes')
            ->assertSuccessful();

        foreach ([$this->tarima, $this->cajaAlterna, $this->esquineros, $this->fleje, $this->etiqueta, $this->cajaB15] as $insumo) {
            $fila = $this->fila($insumo);
            $this->assertEqualsWithDelta(0.0, (float) $fila->quantity, 0.0001, $insumo->name);
            $this->assertNull($fila->negativo_desde, $insumo->name);
        }
        // Otra categoría no se toca.
        $this->assertLessThan(0, $this->existencia($this->grapa));

        $movimientos = InventoryMovement::with('details', 'movementType')->get()->keyBy(fn ($m) => $m->movementType->code);
        $this->assertCount(2, $movimientos);

        $entrada = $movimientos['AJUSTE-POSITIVO'];
        $this->assertSame('approved', $entrada->status);
        $this->assertSame($this->entity->id, (int) $entrada->destination_entity_id);
        $this->assertStringContainsString('Inicio elote 2026-2027', $entrada->description);
        $this->assertCount(4, $entrada->details);
        $renglonEtiqueta = $entrada->details->firstWhere('product_id', $this->etiqueta->id);
        $this->assertEqualsWithDelta(abs($etiquetaAntes), (float) $renglonEtiqueta->quantity, 0.0001);
        $this->assertEqualsWithDelta(0.5, (float) $renglonEtiqueta->unit_cost, 0.0001);

        $salida = $movimientos['AJUSTE-NEGATIVO'];
        $this->assertSame('approved', $salida->status);
        $this->assertSame($this->entity->id, (int) $salida->source_entity_id);
        $this->assertCount(2, $salida->details);
        $renglonTarima = $salida->details->firstWhere('product_id', $this->tarima->id);
        $this->assertEqualsWithDelta($tarimaAntes, (float) $renglonTarima->quantity, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $renglonTarima->unit_cost, 0.0001, 'la salida toma el costo PEPS');
        $this->assertEqualsWithDelta(50 * 4.0 + $tarimaAntes * 3.0, (float) $salida->total_amount, 0.001);

        $this->assertSame(6, InventoryKardex::whereIn('movement_id', $movimientos->pluck('id'))->count());
        $this->assertSame(0, Artisan::call('inventario:verificar-capas'), Artisan::output());

        // Correrlo de nuevo no encuentra nada.
        $this->artisan('inventario:reiniciar-existencias', ['--confirmar' => true])
            ->expectsOutputToContain('No hay existencias que reiniciar')
            ->assertSuccessful();
        $this->assertSame(2, InventoryMovement::count());
    }

    public function test_solo_negativos_no_toca_lo_positivo(): void
    {
        $tarimaAntes = $this->existencia($this->tarima);

        $this->artisan('inventario:reiniciar-existencias', ['--solo-negativos' => true, '--confirmar' => true])
            ->expectsConfirmation(self::PREGUNTA, 'yes')
            ->assertSuccessful();

        $this->assertEqualsWithDelta($tarimaAntes, $this->existencia($this->tarima), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->existencia($this->etiqueta), 0.0001);
        $this->assertSame('AJUSTE-POSITIVO', InventoryMovement::with('movementType')->sole()->movementType->code);
    }

    public function test_responder_que_no_cancela(): void
    {
        $this->artisan('inventario:reiniciar-existencias', ['--confirmar' => true])
            ->expectsConfirmation(self::PREGUNTA, 'no')
            ->assertSuccessful();

        $this->assertSame(0, InventoryMovement::count());
        $this->assertLessThan(0, $this->existencia($this->etiqueta));
    }

    public function test_filtros_de_almacen_y_todas_las_categorias(): void
    {
        $this->artisan('inventario:reiniciar-existencias', ['--entidad' => [999999]])
            ->expectsOutputToContain('No hay existencias')
            ->assertSuccessful();
        $this->artisan('inventario:reiniciar-existencias', ['--excluir-entidad' => [$this->entity->id]])
            ->expectsOutputToContain('No hay existencias')
            ->assertSuccessful();

        $this->artisan('inventario:reiniciar-existencias', ['--todas' => true, '--confirmar' => true])
            ->expectsConfirmation(self::PREGUNTA, 'yes')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(0.0, $this->existencia($this->grapa), 0.0001);
        $this->assertSame(0, Artisan::call('inventario:verificar-capas'), Artisan::output());
    }
}
