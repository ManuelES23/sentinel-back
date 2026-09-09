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
}
