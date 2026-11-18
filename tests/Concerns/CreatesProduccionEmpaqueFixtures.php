<?php

namespace Tests\Concerns;

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\UnitOfMeasure;
use App\Models\User;

/**
 * Producción de Empaque con insumos: sobre CreatesReporteEmpaqueFixtures (empresa, planta
 * «Empaque Principal», temporada, recepción → proceso → pallet) agrega los insumos y la
 * receta «Pallet Caja 8.85 LBS 240 B15»: Tarima 1 al armar; Esquineros 4, Fleje 40 y
 * Grapa 8 al cerrar; Etiqueta 242 por caja; grupo «Caja» con dos alternativas de 240.
 */
trait CreatesProduccionEmpaqueFixtures
{
    use CreatesReporteEmpaqueFixtures;

    protected UnitOfMeasure $pieza;
    protected Recipe $receta;
    protected Product $tarima;
    protected Product $esquineros;
    protected Product $fleje;
    protected Product $grapa;
    protected Product $etiqueta;
    protected Product $cajaB15;
    protected Product $cajaAlterna;
    protected RecipeItem $itemTarima;
    protected RecipeItem $itemEtiqueta;
    protected RecipeItem $itemCaja;
    protected RecipeItem $itemCajaAlterna;
    private int $consecutivoPallet = 0;

    protected function setUpProduccionEmpaqueFixtures(): void
    {
        $this->setUpReporteEmpaqueFixtures();

        $this->pieza = UnitOfMeasure::create(['code' => 'PZA', 'name' => 'Pieza', 'abbreviation' => 'pza']);

        $this->tarima = $this->insumoEmpaque('INS-TAR', 'Tarima');
        $this->esquineros = $this->insumoEmpaque('INS-ESQ', 'Esquineros');
        $this->fleje = $this->insumoEmpaque('INS-FLE', 'Fleje');
        $this->grapa = $this->insumoEmpaque('INS-GRA', 'Grapa');
        $this->etiqueta = $this->insumoEmpaque('INS-ETI', 'Etiqueta térmica 4x2');
        $this->cajaB15 = $this->insumoEmpaque('INS-CAJ', 'Caja de cartón 8.85 LBS B15');
        $this->cajaAlterna = $this->insumoEmpaque('INS-CAJ2', 'Caja de cartón 8.85 LBS genérica');

        $this->receta = Recipe::create([
            'enterprise_id' => $this->enterprise->id,
            'code' => 'REC-B15',
            'name' => 'Pallet Caja 8.85 LBS 240 B15',
            'recipe_type' => 'empaque',
            'output_quantity' => 1,
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->itemTarima = $this->renglonReceta($this->tarima, 1, RecipeItem::CONSUMO_AL_ARMAR);
        $this->renglonReceta($this->esquineros, 4, RecipeItem::CONSUMO_AL_CERRAR);
        $this->renglonReceta($this->fleje, 40, RecipeItem::CONSUMO_AL_CERRAR);
        $this->renglonReceta($this->grapa, 8, RecipeItem::CONSUMO_AL_CERRAR);
        $this->itemEtiqueta = $this->renglonReceta($this->etiqueta, 242, RecipeItem::CONSUMO_POR_CAJA);
        $this->itemCaja = $this->renglonReceta($this->cajaB15, 240, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => true]);
        $this->itemCajaAlterna = $this->renglonReceta($this->cajaAlterna, 240, RecipeItem::CONSUMO_POR_CAJA, ['group_key' => 'Caja', 'is_default' => false]);
    }

    protected function insumoEmpaque(string $codigo, string $nombre): Product
    {
        $producto = Product::create([
            'code' => $codigo,
            'name' => $nombre,
            'unit_id' => $this->pieza->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
        ]);
        $producto->enterprises()->attach($this->enterprise->id);

        return $producto;
    }

    protected function renglonReceta(Product $producto, float $cantidad, string $consumo, array $extra = [], ?Recipe $receta = null): RecipeItem
    {
        $receta ??= $this->receta;

        return RecipeItem::create(array_merge([
            'enterprise_id' => $this->enterprise->id,
            'recipe_id' => $receta->id,
            'product_id' => $producto->id,
            'quantity' => $cantidad,
            'waste_percentage' => 0,
            'cost_per_unit' => 1,
            'is_optional' => false,
            'consumo' => $consumo,
        ], $extra));
    }

    /** Entrada de existencia en la planta con capa PEPS al costo dado. */
    protected function darExistencia(Product $producto, float $cantidad, float $costo = 2.0): void
    {
        InventoryStock::updateStock($producto->id, $this->entity->id, null, $cantidad, $costo);
    }

    protected function existencia(Product $producto): float
    {
        return (float) (InventoryStock::where('product_id', $producto->id)
            ->where('entity_id', $this->entity->id)->value('quantity') ?? 0);
    }

    /** Pallet en la planta con número «CHA-000n» y la receta B15. */
    protected function crearPallet(array $overrides = []): ProduccionEmpaque
    {
        $n = ++$this->consecutivoPallet;
        $base = $this->crearRecepcionConProduccion($this->productorPrincipal, "P{$n}");
        $base['produccion']->update(array_merge([
            'numero_pallet' => sprintf('CHA-%04d', $n),
            'recipe_id' => $this->receta->id,
            'total_cajas' => 240,
            'is_cola' => false,
        ], $overrides));

        return $base['produccion']->fresh();
    }

    protected function crearEntrada(ProduccionEmpaque $pallet, int $numero, int $cajas, array $overrides = []): ProduccionEmpaqueDetalle
    {
        return ProduccionEmpaqueDetalle::create(array_merge([
            'produccion_id' => $pallet->id,
            'numero_entrada' => $numero,
            'proceso_id' => $pallet->proceso_id,
            'recipe_id' => $pallet->recipe_id,
            'fecha_produccion' => '2026-02-03',
            'total_cajas' => $cajas,
            'peso_neto_kg' => $cajas * 4,
        ], $overrides));
    }

    protected function asignarEncargado(?User $usuario = null): User
    {
        $usuario ??= User::factory()->create();
        $this->entity->update(['encargado_user_id' => $usuario->id]);
        $this->entity->refresh();

        return $usuario;
    }
}
