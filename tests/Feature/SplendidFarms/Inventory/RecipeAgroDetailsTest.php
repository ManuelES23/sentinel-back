<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeAgroDetailsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_receta_de_splendid_farms_migrada_conserva_su_cultivo_en_agro_details(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['cultivo_id' => $this->cultivo->id, 'peso_pieza' => 4.01]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $recipeId = $response->json('data.id');

        $this->assertDatabaseHas('recipe_agro_details', [
            'recipe_id' => $recipeId,
            'cultivo_id' => $this->cultivo->id,
        ]);
        $this->assertDatabaseMissing('recipes', ['id' => $recipeId, 'cultivo_id' => $this->cultivo->id]);
    }

    public function test_receta_sin_datos_agricolas_no_crea_fila_en_agro_details(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $this->assertDatabaseMissing('recipe_agro_details', ['recipe_id' => $response->json('data.id')]);
    }

    public function test_show_devuelve_agro_details_anidado(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload(['cultivo_id' => $this->cultivo->id]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $show = $this->getJson("/api/splendidfarms/inventario/catalogos/recetas/{$response->json('data.id')}");

        $show->assertOk();
        $this->assertSame($this->cultivo->id, $show->json('data.agro_details.cultivo_id'));
    }

    public function test_acepta_agro_details_anidado_en_el_payload(): void
    {
        $response = $this->postJson('/api/splendidfarms/inventario/catalogos/recetas',
            $this->validRecipePayload([
                'agro_details' => ['cultivo_id' => $this->cultivo->id, 'peso_pieza' => 4.01],
            ]),
            ['X-Enterprise-Slug' => 'splendidfarms']);

        $response->assertOk();
        $this->assertDatabaseHas('recipe_agro_details', [
            'recipe_id' => $response->json('data.id'),
            'cultivo_id' => $this->cultivo->id,
        ]);
    }
}
