<?php

namespace Tests\Feature\Empaque\Consumo;

use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecetaConsumoTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    private const BASE = '/api/splendidfarms/inventario/catalogos/recetas';

    private array $headers = ['X-Enterprise-Slug' => 'splendidfarms'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function payloadConItem(array $item = []): array
    {
        return $this->validRecipePayload([
            'items' => [array_merge([
                'product_id' => $this->productA->id,
                'quantity' => 1,
            ], $item)],
        ]);
    }

    public function test_guarda_y_devuelve_consumo_por_renglon(): void
    {
        $respuesta = $this->postJson(self::BASE, $this->payloadConItem(['consumo' => 'al_cerrar']), $this->headers);

        $respuesta->assertOk();
        $this->assertSame('al_cerrar', $respuesta->json('data.items.0.consumo'));
        $this->assertDatabaseHas('recipe_items', [
            'recipe_id' => $respuesta->json('data.id'),
            'consumo' => 'al_cerrar',
        ]);
    }

    public function test_sin_consumo_el_renglon_es_por_caja(): void
    {
        $respuesta = $this->postJson(self::BASE, $this->payloadConItem(), $this->headers);
        $respuesta->assertOk();
        $this->assertSame('por_caja', RecipeItem::first()->consumo);

        $conNulo = $this->postJson(self::BASE, $this->payloadConItem(['consumo' => null]) + ['name' => 'Otra'], $this->headers);
        $conNulo->assertOk();
        $this->assertSame('por_caja', RecipeItem::latest('id')->first()->consumo);
    }

    public function test_consumo_invalido_da_422(): void
    {
        $this->postJson(self::BASE, $this->payloadConItem(['consumo' => 'cuando_sea']), $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.consumo']);
    }

    public function test_actualizar_receta_con_items_guarda_consumo_y_invalido_da_422(): void
    {
        $id = $this->postJson(self::BASE, $this->payloadConItem(), $this->headers)->json('data.id');

        $this->putJson(self::BASE . "/{$id}", ['items' => [
            ['product_id' => $this->productA->id, 'quantity' => 1, 'consumo' => 'al_armar'],
        ]], $this->headers)->assertOk();
        $this->assertSame('al_armar', Recipe::find($id)->items()->first()->consumo);

        $this->putJson(self::BASE . "/{$id}", ['items' => [
            ['product_id' => $this->productA->id, 'quantity' => 1, 'consumo' => 'xx'],
        ]], $this->headers)->assertStatus(422)->assertJsonValidationErrors(['items.0.consumo']);
    }

    public function test_renglon_suelto_agrega_y_edita_consumo_sin_pisarlo_si_se_omite(): void
    {
        $id = $this->postJson(self::BASE, $this->validRecipePayload(), $this->headers)->json('data.id');

        $alta = $this->postJson(self::BASE . "/{$id}/items", [
            'product_id' => $this->productB->id,
            'quantity' => 2,
            'consumo' => 'al_armar',
        ], $this->headers);
        $alta->assertCreated();
        $this->assertSame('al_armar', $alta->json('data.consumo'));
        $itemId = $alta->json('data.id');

        $this->putJson(self::BASE . "/{$id}/items/{$itemId}", ['quantity' => 3], $this->headers)->assertOk();
        $this->assertSame('al_armar', RecipeItem::find($itemId)->consumo);

        $this->putJson(self::BASE . "/{$id}/items/{$itemId}", ['consumo' => 'al_cerrar'], $this->headers)->assertOk();
        $this->assertSame('al_cerrar', RecipeItem::find($itemId)->consumo);

        $this->putJson(self::BASE . "/{$id}/items/{$itemId}", ['consumo' => 'otro'], $this->headers)
            ->assertStatus(422)->assertJsonValidationErrors(['consumo']);
    }

    public function test_mostrar_receta_incluye_consumo_de_los_items(): void
    {
        $id = $this->postJson(self::BASE, $this->payloadConItem(['consumo' => 'al_armar']), $this->headers)->json('data.id');

        $this->getJson(self::BASE . "/{$id}", $this->headers)->assertOk();
        $this->assertSame('al_armar', $this->getJson(self::BASE . "/{$id}", $this->headers)->json('data.items.0.consumo'));
    }

    public function test_restaurar_version_conserva_consumo(): void
    {
        $id = $this->postJson(self::BASE, $this->payloadConItem(['consumo' => 'al_cerrar']), $this->headers)->json('data.id');

        // Este update snapshotea la v1 (con consumo al_cerrar) y lo cambia.
        $this->putJson(self::BASE . "/{$id}", ['items' => [
            ['product_id' => $this->productA->id, 'quantity' => 1, 'consumo' => 'por_caja'],
        ]], $this->headers)->assertOk();
        $this->assertSame('por_caja', Recipe::find($id)->items()->first()->consumo);

        $this->postJson(self::BASE . "/{$id}/versions/1/restore", [], $this->headers)->assertOk();

        $this->assertSame('al_cerrar', Recipe::find($id)->items()->first()->consumo);
    }
}
