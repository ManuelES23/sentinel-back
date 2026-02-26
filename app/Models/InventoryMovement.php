<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'document_number',
        'movement_type_id',
        'movement_date',
        'source_entity_id',
        'source_entity_type',
        'source_area_id',
        'destination_entity_id',
        'destination_entity_type',
        'destination_area_id',
        'reference_type',
        'reference_id',
        'reference_number',
        'description',
        'notes',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'total_quantity',
        'total_amount',
        'metadata',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_quantity' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'metadata' => 'array',
    ];

    /**
     * Tipo de movimiento
     */
    public function movementType(): BelongsTo
    {
        return $this->belongsTo(MovementType::class, 'movement_type_id');
    }

    /**
     * Entidad origen
     */
    public function sourceEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'source_entity_id');
    }

    /**
     * Entidad destino
     */
    public function destinationEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'destination_entity_id');
    }

    /**
     * Usuario que creó el movimiento
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuario que aprobó
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Alias para approvedBy
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Alias para createdBy
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuario que canceló
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Detalles del movimiento
     */
    public function details(): HasMany
    {
        return $this->hasMany(InventoryMovementDetail::class, 'movement_id');
    }

    /**
     * Registros de kardex
     */
    public function kardexEntries(): HasMany
    {
        return $this->hasMany(InventoryKardex::class, 'movement_id');
    }

    /**
     * Scope por estado
     */
    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para borradores
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope para pendientes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope para completados
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope por fecha
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    /**
     * Scope por entidad
     */
    public function scopeForEntity($query, int $entityId)
    {
        return $query->where(function ($q) use ($entityId) {
            $q->where('source_entity_id', $entityId)
              ->orWhere('destination_entity_id', $entityId);
        });
    }

    /**
     * Verificar si está en borrador
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Verificar si está pendiente
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Verificar si está completado
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Verificar si puede ser editado
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'pending']);
    }

    /**
     * Verificar si puede ser aprobado
     */
    public function canBeApproved(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Generar número de documento automático
     */
    public static function generateDocumentNumber(string $prefix = 'MOV'): string
    {
        $year = date('Y');
        $month = date('m');
        
        $lastMovement = self::where('document_number', 'like', "{$prefix}-{$year}{$month}-%")
            ->orderByRaw('CAST(SUBSTRING(document_number, -5) AS UNSIGNED) DESC')
            ->first();
        
        if ($lastMovement) {
            $lastNumber = (int) substr($lastMovement->document_number, -5);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }
        
        return "{$prefix}-{$year}{$month}-" . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Recalcular totales
     */
    public function recalculateTotals(): void
    {
        $this->total_quantity = $this->details()->sum('base_quantity');
        $this->total_amount = $this->details()->sum('total_cost');
        $this->save();
    }
}
