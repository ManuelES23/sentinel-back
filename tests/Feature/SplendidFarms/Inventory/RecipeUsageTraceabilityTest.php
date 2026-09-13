<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Branch;
use App\Models\Cultivo;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Enterprise;
use App\Models\Temporada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeUsageTraceabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_uso_de_receta_devuelve_las_producciones_que_la_referencian(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(), ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        // produccion_empaque exige temporada_id/entity_id/folio_produccion/
        // fecha_produccion (no tiene enterprise_id) — misma cadena mínima que
        // usa CreatesReporteProductoresFixtures.
        $branch = Branch::create([
            'enterprise_id' => $this->enterprise->id,
            'code' => 'SF-MAIN',
            'name' => 'Casa Matriz',
            'slug' => 'casa-matriz',
            'is_active' => true,
            'is_main' => true,
        ]);

        $entityType = EntityType::create([
            'code' => 'PLANTA',
            'name' => 'Planta Empacadora',
            'slug' => 'planta-empacadora',
            'is_active' => true,
        ]);

        $entity = Entity::create([
            'branch_id' => $branch->id,
            'entity_type_id' => $entityType->id,
            'code' => 'EMP-001',
            'name' => 'Empaque Principal',
            'slug' => 'empaque-principal',
            'is_active' => true,
        ]);

        $cultivo = Cultivo::create(['nombre' => 'Mango']);

        $temporada = Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => 'Mango 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'user_id' => $this->actingUser->id,
        ]);

        DB::table('produccion_empaque')->insert([
            'recipe_id' => $recipeId,
            'temporada_id' => $temporada->id,
            'entity_id' => $entity->id,
            'folio_produccion' => 'PDN-USO-001',
            'fecha_produccion' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/uso",
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.producciones'));
    }

    public function test_uso_de_receta_de_otra_empresa_devuelve_404(): void
    {
        $otraEmpresa = Enterprise::create([
            'name' => 'Otra Empresa',
            'slug' => 'otra-empresa',
            'description' => 'Empresa ajena de prueba',
            'is_active' => true,
        ]);

        $recipe = \App\Models\Recipe::create([
            'name' => 'Receta Ajena',
            'code' => 'RCP-AJENA-001',
            'output_quantity' => 1,
            'enterprise_id' => $otraEmpresa->id,
        ]);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipe->id}/uso",
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertNotFound();
    }

    public function test_articulo_usado_en_recetas_devuelve_las_recetas_que_lo_usan(): void
    {
        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'items' => [['product_id' => $this->productA->id, 'quantity' => 2]],
            ]), ['X-Enterprise-Slug' => 'splendidfarms']);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/articulos/{$this->productA->id}/usado-en-recetas",
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.recetas'));
    }

    public function test_articulo_usado_en_recetas_no_filtra_por_defecto_cuando_falta_el_header_de_empresa(): void
    {
        // Un artículo compartido (Product es many-to-many con Enterprise, ver
        // importProducts()) usado por recetas de dos empresas distintas:
        // sin header, se mantiene el comportamiento previo sin filtrar.
        $otraEmpresa = Enterprise::create([
            'name' => 'Otra Empresa',
            'slug' => 'otra-empresa',
            'description' => 'Empresa ajena de prueba',
            'is_active' => true,
        ]);

        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'items' => [['product_id' => $this->productA->id, 'quantity' => 2]],
            ]), ['X-Enterprise-Slug' => 'splendidfarms']);

        $recetaAjena = \App\Models\Recipe::create([
            'name' => 'Receta Ajena Con Mismo Artículo',
            'code' => 'RCP-AJENA-002',
            'output_quantity' => 1,
            'enterprise_id' => $otraEmpresa->id,
        ]);
        $recetaAjena->items()->create([
            'product_id' => $this->productA->id,
            'quantity' => 1,
            'enterprise_id' => $otraEmpresa->id,
        ]);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/articulos/{$this->productA->id}/usado-en-recetas");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.recetas'));
    }

    public function test_articulo_usado_en_recetas_no_filtra_la_receta_de_la_otra_empresa(): void
    {
        // Regresión: mismo artículo compartido entre dos empresas, pero cada
        // llamada con X-Enterprise-Slug solo debe ver la receta de SU
        // empresa — Recipe es estrictamente mono-empresa, a diferencia de
        // Product.
        $otraEmpresa = Enterprise::create([
            'name' => 'Otra Empresa',
            'slug' => 'otra-empresa',
            'description' => 'Empresa ajena de prueba',
            'is_active' => true,
        ]);

        $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'items' => [['product_id' => $this->productA->id, 'quantity' => 2]],
            ]), ['X-Enterprise-Slug' => 'splendidfarms']);

        $recetaAjena = \App\Models\Recipe::create([
            'name' => 'Receta Ajena Con Mismo Artículo',
            'code' => 'RCP-AJENA-003',
            'output_quantity' => 1,
            'enterprise_id' => $otraEmpresa->id,
        ]);
        $recetaAjena->items()->create([
            'product_id' => $this->productA->id,
            'quantity' => 1,
            'enterprise_id' => $otraEmpresa->id,
        ]);

        $response = $this->getJson("/api/splendidfarms/inventario/catalogos/articulos/{$this->productA->id}/usado-en-recetas",
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.recetas'));
        $this->assertNotEquals($recetaAjena->id, $response->json('data.recetas.0.id'));
    }
}
