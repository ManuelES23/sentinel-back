<?php

namespace App\Models;

use App\Services\Inventory\CapasCosto;
use App\Support\Inventario\Cubeta;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStock extends Model
{
    use HasFactory;

    protected $table = 'inventory_stock';

    protected $fillable = [
        'product_id',
        'entity_id',
        'entity_type',
        'area_id',
        'quantity',
        'reserved_quantity',
        'lot_number',
        'serial_number',
        'expiry_date',
        'unit_cost',
        'total_cost',
        'last_movement_at',
        'last_movement_id',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'last_movement_at' => 'datetime',
        'quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    /**
     * available_quantity es calculado automáticamente en DB
     */
    protected $appends = [];

    /**
     * Producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Entidad (bodega)
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    /**
     * Último movimiento
     */
    public function lastMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'last_movement_id');
    }

    /**
     * Scope por producto
     */
    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope por entidad
     */
    public function scopeForEntity($query, int $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    /**
     * Scope por área
     */
    public function scopeForArea($query, ?int $areaId)
    {
        if ($areaId) {
            return $query->where('area_id', $areaId);
        }
        return $query->whereNull('area_id');
    }

    /**
     * Scope con stock disponible
     */
    public function scopeWithAvailable($query)
    {
        return $query->whereRaw('quantity > reserved_quantity');
    }

    /**
     * Scope para lotes próximos a vencer
     */
    public function scopeExpiringBefore($query, $date)
    {
        return $query->whereNotNull('expiry_date')
                    ->where('expiry_date', '<=', $date);
    }

    /**
     * Movimiento de cantidad sin renglón de movimiento (hoy: producción de
     * Empaque). Entradas crean capa; salidas toman PEPS. La regla de activos
     * fijos vigentes vive en CapasCosto::salir().
     */
    public static function updateStock(
        int $productId,
        int $entityId,
        ?int $areaId,
        float $quantityChange,
        float $unitCost = 0,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        ?int $movementId = null,
        bool $allowNegative = false,
        ?string $referencia = null,
    ): self {
        $c = new Cubeta($productId, $entityId, $areaId, $lotNumber);
        $motor = app(CapasCosto::class);

        if ($quantityChange > 0) {
            $motor->entrar($c, $quantityChange, $unitCost, now(), CapaCosto::ORIGEN_ENTRADA, $movementId);
        } elseif ($quantityChange < 0) {
            $motor->salir($c, -$quantityChange, $movementId, null, $referencia, $allowNegative);
        }

        return self::ajustarCantidad($c, $quantityChange, $expiryDate, $movementId, $allowNegative);
    }

    /** Devuelve lo que una salida sin movimiento tomó (por referencia); lo que no tenga porciones entra a $costoRespaldo. */
    public static function devolverPorReferencia(int $productId, int $entityId, float $cantidad, string $referencia, float $costoRespaldo): self
    {
        $c = new Cubeta($productId, $entityId);
        $r = app(CapasCosto::class)->devolver($c, $cantidad, null, $referencia, null, $costoRespaldo);

        return self::ajustarCantidad($c, $r->cantidad, null, null, true);
    }

    /**
     * Único escritor de cantidades. Llamar DESPUÉS de la operación del motor de
     * capas (CapasCosto); al final sincroniza unit_cost/total_cost desde las capas.
     */
    public static function ajustarCantidad(Cubeta $c, float $delta, mixed $expiry = null, ?int $movementId = null, bool $allowNegative = false): self
    {
        $stock = $c->filtrar(self::query())->lockForUpdate()->first()
            ?? new self([
                'product_id' => $c->productId,
                'entity_id' => $c->entityId,
                'area_id' => $c->areaId,
                'lot_number' => $c->lote,
                'unit_cost' => 0,
                'total_cost' => 0,
            ]);

        $nueva = round((float) ($stock->quantity ?? 0) + $delta, 4);
        $stock->quantity = $allowNegative ? $nueva : max(0, $nueva);

        if ($expiry) {
            $stock->expiry_date = $expiry;
        }
        if ($movementId) {
            $stock->last_movement_id = $movementId;
            $stock->last_movement_at = now();
        }

        $stock->save();
        app(CapasCosto::class)->sincronizarFila($c);

        return $stock->refresh();
    }
}
