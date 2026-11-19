<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryKardex extends Model
{
    use HasFactory;

    protected $table = 'inventory_kardex';

    protected $fillable = [
        'product_id',
        'productor_id',
        'entity_id',
        'entity_type',
        'area_id',
        'movement_id',
        'movement_date',
        'document_number',
        'transaction_type',
        'description',
        'quantity',
        'balance_quantity',
        'unit_cost',
        'total_cost',
        'balance_value',
        'lot_number',
        'serial_number',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'quantity' => 'decimal:4',
        'balance_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'balance_value' => 'decimal:4',
    ];

    /**
     * Producto
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Productor asociado (cuando aplica).
     */
    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class, 'productor_id');
    }

    /**
     * Entidad
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    /**
     * Movimiento
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
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
     * Scope por fecha
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Registrar entrada en kardex
     */
    public static function recordEntry(
        int $productId,
        int $entityId,
        ?string $entityType,
        int $movementId,
        string $transactionType, // 'increase' o 'decrease'
        float $quantity,
        float $unitCost,
        ?string $lotNumber = null,
        ?string $serialNumber = null,
        ?int $areaId = null,
        ?int $productorId = null,
        ?string $descripcion = null
    ): self {
        // Obtener el movimiento para extraer datos
        $movement = InventoryMovement::find($movementId);
        
        // Obtener último balance
        $lastEntry = self::where('product_id', $productId)
            ->where('entity_id', $entityId)
            ->when($areaId, fn($q) => $q->where('area_id', $areaId))
            ->orderBy('id', 'desc')
            ->first();

        $previousBalance = $lastEntry ? $lastEntry->balance_quantity : 0;
        $previousValueBalance = $lastEntry ? $lastEntry->balance_value : 0;

        $quantityChange = $transactionType === 'increase' ? $quantity : -$quantity;
        $newBalance = $previousBalance + $quantityChange;
        $totalCost = $quantity * $unitCost;
        $valueChange = $transactionType === 'increase' ? $totalCost : -$totalCost;
        $newValueBalance = $previousValueBalance + $valueChange;

        return self::create([
            'product_id' => $productId,
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'area_id' => $areaId,
            'movement_id' => $movementId,
            'movement_date' => $movement ? $movement->movement_date : now(),
            'document_number' => $movement ? $movement->document_number : '',
            'transaction_type' => $transactionType,
            'description' => $descripcion ?? ($movement ? $movement->description : null),
            'quantity' => $quantity,
            'balance_quantity' => $newBalance,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'balance_value' => $newValueBalance,
            'lot_number' => $lotNumber,
            'serial_number' => $serialNumber,
            'productor_id' => $productorId,
        ]);
    }

    /**
     * Fila de kardex de producción de Empaque: no hay movimiento de inventario, así que la
     * fecha, el documento (número de pallet) y la descripción vienen del llamador. El saldo
     * se arrastra igual que en recordEntry().
     */
    public static function registrarProduccion(
        int $productId,
        int $entityId,
        string $tipo, // 'increase' o 'decrease'
        float $cantidad,
        float $costoUnitario,
        string $fecha,
        string $documento,
        string $descripcion
    ): self {
        $ultima = self::where('product_id', $productId)
            ->where('entity_id', $entityId)
            ->orderBy('id', 'desc')
            ->first();

        $signo = $tipo === 'increase' ? 1 : -1;
        $total = $cantidad * $costoUnitario;

        return self::create([
            'product_id' => $productId,
            'entity_id' => $entityId,
            'movement_id' => null,
            'movement_date' => $fecha,
            'document_number' => $documento,
            'transaction_type' => $tipo,
            'description' => $descripcion,
            'quantity' => $cantidad,
            'balance_quantity' => (float) ($ultima->balance_quantity ?? 0) + $signo * $cantidad,
            'unit_cost' => $costoUnitario,
            'total_cost' => $total,
            'balance_value' => (float) ($ultima->balance_value ?? 0) + $signo * $total,
        ]);
    }
}
