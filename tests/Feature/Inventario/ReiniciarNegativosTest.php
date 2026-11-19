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
 * inventario:reiniciar-negativos: lleva a 0 las existencias negativas de «Consumibles producto
 * terminado» con un Ajuste Positivo aprobado por almacén, al costo del faltante, sin tocar lo positivo
 * ni otras categorías. Sin --confirmar no cambia nada.
 */
class ReiniciarNegativosTest extends TestCase
{
    use RefreshDatabase, CreatesProduccionEmpaqueFixtures;

    private const PREGUNTA = 'Se creará y aprobará un Ajuste Positivo en 1 almacén. ¿Continuar?';

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

        // La tarima tiene existencia de sobra; lo demás se va a negativo al producir.
        $this->darExistencia($this->tarima, 10.0, 3.0);
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

    public function test_sin_confirmar_solo_muestra_la_lista(): void
    {
        $antes = InventoryStock::orderBy('id')->pluck('quantity', 'id')->all();

        $this->artisan('inventario:reiniciar-negativos')
            ->expectsOutputToContain('Nada se modificó')
            ->assertSuccessful();

        $this->assertSame($antes, InventoryStock::orderBy('id')->pluck('quantity', 'id')->all());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_confirmado_lleva_los_negativos_a_cero_con_un_ajuste_aprobado(): void
    {
        $this->assertLessThan(0, $this->existencia($this->etiqueta));
        $this->assertLessThan(0, $this->existencia($this->grapa));
        $etiquetaAntes = $this->existencia($this->etiqueta);

        $this->artisan('inventario:reiniciar-negativos', ['--confirmar' => true, '--nota' => 'Inicio elote 2026-2027'])
            ->expectsConfirmation(self::PREGUNTA, 'yes')
            ->assertSuccessful();

        // Los consumibles negativos quedan en 0 y sin «en negativo desde».
        foreach ([$this->esquineros, $this->fleje, $this->etiqueta, $this->cajaB15] as $insumo) {
            $fila = InventoryStock::where('product_id', $insumo->id)->where('entity_id', $this->entity->id)->first();
            $this->assertEqualsWithDelta(0.0, (float) $fila->quantity, 0.0001, $insumo->name);
            $this->assertNull($fila->negativo_desde, $insumo->name);
        }
        // Lo positivo y otra categoría no se tocan.
        $this->assertGreaterThan(0, $this->existencia($this->tarima));
        $this->assertLessThan(0, $this->existencia($this->grapa));

        // Un solo ajuste aprobado, con un renglón por insumo negativo, al costo del faltante.
        $ajuste = InventoryMovement::with('details', 'movementType')->sole();
        $this->assertSame('AJUSTE-POSITIVO', $ajuste->movementType->code);
        $this->assertSame('approved', $ajuste->status);
        $this->assertSame($this->entity->id, (int) $ajuste->destination_entity_id);
        $this->assertStringContainsString('Inicio elote 2026-2027', $ajuste->description);
        $this->assertCount(4, $ajuste->details);
        $renglonEtiqueta = $ajuste->details->firstWhere('product_id', $this->etiqueta->id);
        $this->assertEqualsWithDelta(abs($etiquetaAntes), (float) $renglonEtiqueta->quantity, 0.0001);
        $this->assertEqualsWithDelta(0.5, (float) $renglonEtiqueta->unit_cost, 0.0001);

        // Queda en el kardex y las capas cuadran.
        $this->assertSame(4, InventoryKardex::where('movement_id', $ajuste->id)->count());
        $this->assertSame(0, Artisan::call('inventario:verificar-capas'), Artisan::output());

        // Correrlo de nuevo no encuentra nada.
        $this->artisan('inventario:reiniciar-negativos', ['--confirmar' => true])
            ->expectsOutputToContain('No hay existencias negativas')
            ->assertSuccessful();
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_responder_que_no_cancela(): void
    {
        $this->artisan('inventario:reiniciar-negativos', ['--confirmar' => true])
            ->expectsConfirmation(self::PREGUNTA, 'no')
            ->assertSuccessful();

        $this->assertSame(0, InventoryMovement::count());
        $this->assertLessThan(0, $this->existencia($this->etiqueta));
    }

    public function test_todas_las_categorias_incluye_otras_y_entidad_limita(): void
    {
        $this->artisan('inventario:reiniciar-negativos', ['--entidad' => [999999]])
            ->expectsOutputToContain('No hay existencias negativas')
            ->assertSuccessful();

        $this->artisan('inventario:reiniciar-negativos', ['--todas' => true, '--confirmar' => true])
            ->expectsConfirmation(self::PREGUNTA, 'yes')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(0.0, $this->existencia($this->grapa), 0.0001);
        $this->assertCount(5, InventoryMovement::with('details')->sole()->details);
    }
}
