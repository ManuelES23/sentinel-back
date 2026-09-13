<?php

namespace Tests\Concerns;

use App\Models\Calibre;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use App\Models\User;

/**
 * Fixtures mínimos para probar el submódulo de Recetas (BOM) de Inventario:
 * categoría + unidad de medida + un par de productos (ingredientes), más
 * cultivo/calibre para las recetas ligadas a un cultivo.
 */
trait CreatesRecipeFixtures
{
    protected User $actingUser;
    protected Enterprise $enterprise;
    protected ProductCategory $category;
    protected UnitOfMeasure $unit;
    protected Product $productA;
    protected Product $productB;
    protected Cultivo $cultivo;
    protected Calibre $calibre;

    protected function setUpRecipeFixtures(): void
    {
        $this->actingUser = User::factory()->create();

        $this->enterprise = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms',
            'description' => 'Empresa agrícola de prueba',
            'is_active' => true,
        ]);

        $this->category = ProductCategory::create([
            'code' => 'CAT-001',
            'name' => 'Consumibles producto terminado',
            'is_active' => true,
        ]);
        $this->category->enterprises()->attach($this->enterprise->id);

        $this->unit = UnitOfMeasure::create([
            'code' => 'PZA',
            'name' => 'Pieza',
            'abbreviation' => 'pza',
        ]);
        $this->unit->enterprises()->attach($this->enterprise->id);

        $this->productA = Product::create([
            'code' => 'PROD-001',
            'name' => 'Caja de cartón 20lb',
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'cost_price' => 5,
        ]);

        $this->productB = Product::create([
            'code' => 'PROD-002',
            'name' => 'Etiqueta PLU genérica',
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'cost_price' => 0.5,
        ]);

        $this->cultivo = Cultivo::create(['nombre' => 'Elote']);

        $this->calibre = Calibre::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Extra Grande',
            'valor' => 'XG',
            'is_active' => true,
        ]);
    }

    /**
     * Payload mínimo válido para crear una Receta (campos propios, sin items/calibres).
     */
    protected function validRecipePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Caja Elote Premium 20lb',
            'output_quantity' => 1,
            // Solo se usa cuando el payload se pasa directo a Recipe::create()
            // en tests que no pasan por el controller (y por lo tanto no
            // envían X-Enterprise-Slug); recipes.enterprise_id es NOT NULL.
            // Al ir por HTTP, 'enterprise_id' no está en las reglas de
            // validate() del controller y se descarta sin efecto.
            'enterprise_id' => $this->enterprise->id,
        ], $overrides);
    }
}
