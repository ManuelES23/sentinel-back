<?php

namespace Tests\Concerns;

use App\Models\Aplicacion;
use App\Models\AplicacionDetalle;
use App\Models\Cultivo;
use App\Models\Entity;
use App\Models\InventoryKardex;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementDetail;
use App\Models\InventoryStock;
use App\Models\Lote;
use App\Models\Product;
use App\Models\Productor;
use App\Models\Temporada;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\ZonaCultivo;
use App\Services\Inventory\AplicadorStock;

/**
 * Aplicaciones de campo sobre CreatesAlmacenFixtures (el test debe usar ambos
 * traits y llamar a setUpAplicacionesFixtures(), que ya invoca
 * setUpAlmacenFixtures()). Unidades: LT y ML (volumen), GR y KG (peso).
 */
trait CreatesAplicacionesFixtures
{
    protected UnitOfMeasure $mililitro;
    protected UnitOfMeasure $gramo;
    protected UnitOfMeasure $kilo;
    protected Temporada $temporada;
    protected Productor $productor;
    protected Lote $lote;
    protected User $agronomo;

    protected function setUpAplicacionesFixtures(): void
    {
        $this->setUpAlmacenFixtures();

        // LT viene de CreatesAlmacenFixtures con type 'unit': se vuelve volumen real (base ML)
        $this->unidad->update(['type' => 'volume', 'conversion_factor' => 1000]);
        $this->mililitro = UnitOfMeasure::create(['code' => 'ML', 'name' => 'Mililitro', 'abbreviation' => 'mL', 'type' => 'volume', 'conversion_factor' => 1]);
        $this->gramo = UnitOfMeasure::create(['code' => 'GR', 'name' => 'Gramo', 'abbreviation' => 'g', 'type' => 'weight', 'conversion_factor' => 1]);
        $this->kilo = UnitOfMeasure::create(['code' => 'KG', 'name' => 'Kilogramo', 'abbreviation' => 'kg', 'type' => 'weight', 'conversion_factor' => 1000]);

        $this->agronomo = $this->crearUsuarioDeCampo([$this->almacenA]);

        $cultivo = Cultivo::create(['nombre' => 'Chile']);
        $this->temporada = Temporada::create([
            'cultivo_id' => $cultivo->id, 'nombre' => 'Chile 2026', 'locacion' => 'Sinaloa',
            'folio_temporada' => $cultivo->id . '-001', 'año_inicio' => 2026, 'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31', 'user_id' => $this->agronomo->id,
        ]);
        $this->productor = Productor::create(['nombre' => 'Juan', 'is_active' => true]);
        $zona = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);
        $this->lote = Lote::create([
            'productor_id' => $this->productor->id, 'zona_cultivo_id' => $zona->id,
            'nombre' => 'Lote 1', 'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{product: Product, dosis: float|int, unidad: UnitOfMeasure}>|null  $renglones
     */
    protected function crearAplicacion(array $atributos = [], ?array $renglones = null): Aplicacion
    {
        $aplicacion = Aplicacion::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'enterprise_id' => $this->empresa->id,
            'almacen_id' => $this->almacenA->id,
            'folio' => 'APL-2026-' . str_pad((string) (Aplicacion::withTrashed()->count() + 1), 4, '0', STR_PAD_LEFT),
            'fecha' => '2026-09-17',
            'tipo_aplicacion' => 'agroquimico',
            'productor_id' => $this->productor->id,
            'lote_id' => $this->lote->id,
            'superficie_aplicada' => 4,
            'problematica' => 'Tizón tardío',
            'created_by' => $this->agronomo->id,
        ], $atributos));

        $renglones ??= [['product' => $this->insumo, 'dosis' => 2, 'unidad' => $this->unidad]];

        foreach ($renglones as $renglon) {
            AplicacionDetalle::create([
                'aplicacion_id' => $aplicacion->id,
                'product_id' => $renglon['product']->id,
                'dosis' => $renglon['dosis'],
                'unidad_medida' => $renglon['unidad']->abbreviation . '/ha',
                'unidad_dosis_id' => $renglon['unidad']->id,
            ]);
        }

        return $aplicacion->load('detalles');
    }

    /** Stock por lote con costo propio (darStock fija el costo en 10). */
    protected function darLote(Entity $almacen, Product $producto, float $cantidad, string $lote, float $costo): InventoryStock
    {
        $stock = $this->darStock($almacen, $producto, $cantidad, $lote);
        $stock->update(['unit_cost' => $costo, 'total_cost' => $cantidad * $costo]);

        return $stock;
    }

    /** Entrada real (movimiento + stock + kardex), para probar que el kardex queda alineado con el stock. */
    protected function entrarStock(Entity $almacen, Product $producto, float $cantidad, string $lote, float $costo): void
    {
        $movimiento = InventoryMovement::create([
            'document_number' => 'in-TEST-' . str_pad((string) (InventoryMovement::withTrashed()->count() + 1), 5, '0', STR_PAD_LEFT),
            'movement_type_id' => $this->tipoEntrada->id,
            'movement_date' => '2026-09-01',
            'destination_entity_id' => $almacen->id,
            'destination_entity_type' => 'entity',
            'status' => 'approved',
            'created_by' => $this->agronomo->id,
        ]);
        $detalle = InventoryMovementDetail::create([
            'movement_id' => $movimiento->id,
            'product_id' => $producto->id,
            'quantity' => $cantidad,
            'unit_cost' => $costo,
            'total_cost' => $cantidad * $costo,
            'lot_number' => $lote,
        ]);

        app(AplicadorStock::class)->aumentar($detalle, $almacen->id, 'entity', $movimiento);
    }

    protected function stockTotal(Entity $almacen, Product $producto): float
    {
        return (float) InventoryStock::where('entity_id', $almacen->id)
            ->where('product_id', $producto->id)
            ->sum('quantity');
    }

    protected function kardexSaldo(Entity $almacen, Product $producto): float
    {
        return (float) InventoryKardex::where('product_id', $producto->id)
            ->where('entity_id', $almacen->id)
            ->orderByDesc('id')
            ->value('balance_quantity');
    }
}
