<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRecipeFixtures;
use Tests\TestCase;

class RecipeControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRecipeFixtures;

    private const BASE_URL = '/api/splendidfarms/inventario/catalogos/recetas';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecipeFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    public function test_crear_una_receta_sin_items_no_falla(): void
    {
        $response = $this->postJson(self::BASE_URL, $this->validRecipePayload());

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('recipes', ['name' => 'Caja Elote Premium 20lb']);
    }

    /**
     * Bug raíz #1: store() valida 'items' pero Recipe::create($validated) los
     * descarta en silencio porque 'items' no es una columna de la tabla
     * recipes ni está en $fillable — no truena, simplemente no se guardan.
     * Esto es lo que reporta el usuario como "usar una plantilla no guarda
     * los datos" (y en realidad afecta cualquier receta nueva con items).
     */
    public function test_crear_una_receta_con_ingredientes_persiste_los_items(): void
    {
        $response = $this->postJson(self::BASE_URL, $this->validRecipePayload([
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 2],
                ['product_id' => $this->productB->id, 'quantity' => 10],
            ],
        ]));

        $response->assertOk();
        $recipeId = $response->json('data.id');

        $this->assertDatabaseCount('recipe_items', 2);
        $this->assertDatabaseHas('recipe_items', [
            'recipe_id' => $recipeId,
            'product_id' => $this->productA->id,
            'quantity' => 2,
        ]);
    }

    /**
     * Mismo bug raíz #1, pero para calibres/PLUs (recipe_calibres +
     * recipe_calibre_plus), que store() tampoco persiste hoy.
     */
    public function test_crear_una_receta_con_calibres_y_plus_los_persiste(): void
    {
        $response = $this->postJson(self::BASE_URL, $this->validRecipePayload([
            'calibres' => [
                [
                    'calibre_id' => $this->calibre->id,
                    'plus' => [
                        ['product_id' => $this->productB->id, 'is_organic' => false],
                    ],
                ],
            ],
        ]));

        $response->assertOk();
        $recipeId = $response->json('data.id');

        $this->assertDatabaseHas('recipe_calibres', [
            'recipe_id' => $recipeId,
            'calibre_id' => $this->calibre->id,
        ]);
        $this->assertDatabaseCount('recipe_calibre_plus', 1);
    }

    /**
     * Bug raíz #2: unique(recipe_id, product_id) en recipe_items no
     * contempla group_key. El mismo producto usado como alternativa en dos
     * grupos intercambiables distintos (ej. una etiqueta genérica en "Caja"
     * y en "PLU") truena con un error de constraint duplicado.
     */
    public function test_actualizar_receta_permite_el_mismo_producto_en_dos_grupos_intercambiables(): void
    {
        $recipe = Recipe::create($this->validRecipePayload(['code' => 'RCP-TEST-01']));

        $response = $this->putJson(self::BASE_URL."/{$recipe->id}", [
            'items' => [
                ['product_id' => $this->productB->id, 'quantity' => 1, 'group_key' => 'Caja', 'is_default' => true],
                ['product_id' => $this->productB->id, 'quantity' => 1, 'group_key' => 'PLU', 'is_default' => true],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('recipe_items', 2);
    }

    /**
     * Bug raíz #3: el sync de items en update() (delete + recreate) no está
     * en una transacción. Si la recreación truena a medio camino (ej. por el
     * bug #2), la receta se queda con MENOS items que antes de editar —
     * pérdida real de datos, no solo un error visible.
     */
    public function test_actualizar_receta_no_pierde_items_previos_si_el_nuevo_set_falla_a_medias(): void
    {
        $recipe = Recipe::create($this->validRecipePayload(['code' => 'RCP-TEST-02']));
        $recipe->items()->create([
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        // Set inválido: dos items con el mismo producto y mismo group_key
        // (sin distinguir), lo que sí debe seguir bloqueado por la unicidad
        // real (recipe_id + product_id + group_key) y debe fallar sin tocar
        // los items existentes.
        $response = $this->putJson(self::BASE_URL."/{$recipe->id}", [
            'items' => [
                ['product_id' => $this->productB->id, 'quantity' => 3],
                ['product_id' => $this->productB->id, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('recipe_items', 1);
        $this->assertDatabaseHas('recipe_items', [
            'recipe_id' => $recipe->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);
    }

    public function test_actualizar_receta_reemplaza_items_previos_correctamente(): void
    {
        $recipe = Recipe::create($this->validRecipePayload(['code' => 'RCP-TEST-03']));
        $recipe->items()->create([
            'product_id' => $this->productA->id,
            'quantity' => 1,
        ]);

        $response = $this->putJson(self::BASE_URL."/{$recipe->id}", [
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 3],
                ['product_id' => $this->productB->id, 'quantity' => 5],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('recipe_items', 2);
        $this->assertDatabaseHas('recipe_items', [
            'recipe_id' => $recipe->id,
            'product_id' => $this->productA->id,
            'quantity' => 3,
        ]);
    }

    /**
     * Bonus (relacionado, no reportado explícitamente): output_product_id
     * nunca se asignaba en ningún lugar del backend, así que la promesa del
     * frontend "el artículo terminado se crea automáticamente" no pasaba
     * nada en realidad.
     */
    public function test_crear_una_receta_crea_y_vincula_el_producto_terminado(): void
    {
        $response = $this->postJson(
            self::BASE_URL,
            $this->validRecipePayload([
                'output_unit_id' => $this->unit->id,
                'items' => [
                    ['product_id' => $this->productA->id, 'quantity' => 2],
                ],
            ]),
            ['X-Enterprise-Slug' => 'splendidfarms'],
        );

        $response->assertOk();
        $recipeId = $response->json('data.id');
        $productId = $response->json('data.output_product.id');

        $this->assertNotNull($productId, 'La receta debería quedar enlazada a un producto terminado');
        $this->assertDatabaseHas('recipes', [
            'id' => $recipeId,
            'output_product_id' => $productId,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Caja Elote Premium 20lb',
            'product_type' => 'finished_good',
            'unit_id' => $this->unit->id,
        ]);
        $this->assertDatabaseHas('product_categories', [
            'name' => 'Producto Terminado',
        ]);
        $this->assertDatabaseHas('enterprise_product', [
            'enterprise_id' => $this->enterprise->id,
            'product_id' => $productId,
        ]);
    }

    public function test_actualizar_una_receta_antigua_sin_producto_vinculado_se_autocura(): void
    {
        // Simula una receta creada antes de este fix: nunca tuvo output_product_id.
        $recipe = Recipe::create($this->validRecipePayload(['code' => 'RCP-TEST-04']));
        $this->assertNull($recipe->output_product_id);

        $response = $this->putJson(self::BASE_URL."/{$recipe->id}", [
            'name' => 'Caja Elote Premium 20lb (rev)',
        ]);

        $response->assertOk();
        $recipe->refresh();
        $this->assertNotNull($recipe->output_product_id);
        $this->assertDatabaseHas('products', [
            'id' => $recipe->output_product_id,
            'name' => 'Caja Elote Premium 20lb (rev)',
        ]);
    }

    public function test_actualizar_receta_sincroniza_costo_al_producto_ya_vinculado(): void
    {
        $recipe = Recipe::create($this->validRecipePayload(['code' => 'RCP-TEST-05']));
        $this->syncOutputProductForTest($recipe);

        $response = $this->putJson(self::BASE_URL."/{$recipe->id}", [
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 2, 'cost_per_unit' => 10],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', [
            'id' => $recipe->output_product_id,
            'cost_price' => 20,
        ]);
    }

    /**
     * Atajo de prueba: crea directamente el producto enlazado, sin pasar por
     * el endpoint, para probar el camino de "ya tiene producto" de forma
     * aislada del de autocuración.
     */
    private function syncOutputProductForTest(Recipe $recipe): void
    {
        $product = \App\Models\Product::create([
            'code' => 'PROD-999',
            'name' => $recipe->name,
            'unit_id' => $this->unit->id,
            'product_type' => 'finished_good',
        ]);
        $recipe->update(['output_product_id' => $product->id]);
    }
}
