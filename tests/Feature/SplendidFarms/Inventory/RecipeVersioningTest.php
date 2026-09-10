<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeVersioningTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_actualizar_una_receta_crea_una_version_con_el_estado_anterior(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Caja Elote v1']),
            ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        $this->putJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}",
            ['name' => 'Caja Elote v2'], ['X-Enterprise-Slug' => 'splendidfarms']);

        $this->assertDatabaseHas('recipe_versions', ['recipe_id' => $recipeId, 'version_number' => 1]);
        $version = \App\Models\RecipeVersion::where('recipe_id', $recipeId)->first();
        $this->assertSame('Caja Elote v1', $version->snapshot['name']);
    }

    public function test_restaurar_una_version_crea_una_version_nueva_sin_borrar_historia(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Caja Elote v1']),
            ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        $this->putJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}",
            ['name' => 'Caja Elote v2'], ['X-Enterprise-Slug' => 'splendidfarms']);

        $restore = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/versions/1/restore",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $restore->assertOk();
        $this->assertSame('Caja Elote v1', $restore->json('data.name'));
        $this->assertDatabaseCount('recipe_versions', 2);
    }

    /**
     * Final-review I3 (1/2): restaurar una versión snapshoteada mientras la
     * receta estaba 'active' NO debe saltarse el flujo de aprobación
     * poniendo la receta ACTUAL en 'active' de golpe — el status ACTUAL se
     * preserva tal cual, 'status' se omite deliberadamente del snapshot
     * restaurado.
     */
    public function test_restaurar_una_version_no_cambia_el_status_actual_de_la_receta(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['name' => 'Caja Elote v1']),
            ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        // Este update snapshotea la v1 con el status ANTERIOR a él (draft,
        // el default al crear) y deja la receta ACTUAL en 'active'.
        $this->putJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}",
            ['status' => 'active'], ['X-Enterprise-Slug' => 'splendidfarms']);

        $version = \App\Models\RecipeVersion::where('recipe_id', $recipeId)->where('version_number', 1)->first();
        $this->assertSame('draft', $version->snapshot['status']);

        // Movemos el status ACTUAL a 'inactive' (distinto tanto del actual
        // 'active' como del snapshoteado 'draft'), para distinguir con
        // certeza cuál de los dos "gana" tras restaurar.
        \App\Models\Recipe::where('id', $recipeId)->update(['status' => 'inactive']);

        $restore = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/versions/1/restore",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $restore->assertOk();
        // Ni 'active' (lo que tenía la receta antes de este restore) ni
        // 'draft' (lo que decía el snapshot v1) — el status ACTUAL
        // ('inactive') debe preservarse intacto.
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'status' => 'inactive']);
    }

    /**
     * Final-review I3 (2/2): el snapshot también captura agroDetails y
     * recipeCalibres.plus (ver loadMissing en snapshotBeforeUpdate) —
     * restaurar debía reflejarlos también, no solo los campos núcleo+items.
     */
    public function test_restaurar_una_version_restaura_agro_details_y_calibres(): void
    {
        $create = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'name' => 'Caja Elote v1',
                'agro_details' => ['cultivo_id' => $this->cultivo->id, 'peso_pieza' => 4.01],
                'calibres' => [
                    [
                        'calibre_id' => $this->calibre->id,
                        'plus' => [
                            ['product_id' => $this->productA->id, 'is_organic' => true, 'notes' => 'PLU orgánico'],
                        ],
                    ],
                ],
            ]),
            ['X-Enterprise-Slug' => 'splendidfarms']);
        $recipeId = $create->json('data.id');

        $this->assertDatabaseHas('recipe_agro_details', ['recipe_id' => $recipeId, 'cultivo_id' => $this->cultivo->id]);
        $this->assertDatabaseCount('recipe_calibres', 1);

        // Este update (v1 snapshot = estado de creación, CON agro_details y
        // calibres) borra explícitamente ambas extensiones.
        $this->putJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}",
            ['agro_details' => null, 'calibres' => []], ['X-Enterprise-Slug' => 'splendidfarms']);

        $this->assertDatabaseMissing('recipe_agro_details', ['recipe_id' => $recipeId]);
        $this->assertDatabaseCount('recipe_calibres', 0);

        $restore = $this->postJson("/api/splendidfarms/inventario/catalogos/recetas/{$recipeId}/versions/1/restore",
            [], ['X-Enterprise-Slug' => 'splendidfarms']);

        $restore->assertOk();
        $this->assertDatabaseHas('recipe_agro_details', ['recipe_id' => $recipeId, 'cultivo_id' => $this->cultivo->id]);
        $this->assertDatabaseCount('recipe_calibres', 1);

        $recipeCalibre = \App\Models\RecipeCalibre::where('recipe_id', $recipeId)->first();
        $this->assertDatabaseHas('recipe_calibre_plus', [
            'recipe_calibre_id' => $recipeCalibre->id,
            'product_id' => $this->productA->id,
            'is_organic' => true,
        ]);
    }
}
