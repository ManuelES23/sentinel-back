<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MovementType extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'code',
        'name',
        'description',
        'direction',
        'effect',
        'requires_source_entity',
        'requires_destination_entity',
        'is_system',
        'color',
        'icon',
        'order',
        'is_active',
    ];

    protected $casts = [
        'requires_source_entity' => 'boolean',
        'requires_destination_entity' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Movimientos de este tipo
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'movement_type_id');
    }

    /**
     * Scope para tipos activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope por dirección
     */
    public function scopeOfDirection($query, string $direction)
    {
        return $query->where('direction', $direction);
    }

    /**
     * Scope para entradas
     */
    public function scopeEntries($query)
    {
        return $query->where('direction', 'in');
    }

    /**
     * Scope para salidas
     */
    public function scopeExits($query)
    {
        return $query->where('direction', 'out');
    }

    /**
     * Scope para transferencias
     */
    public function scopeTransfers($query)
    {
        return $query->where('direction', 'transfer');
    }

    /**
     * Scope para ajustes
     */
    public function scopeAdjustments($query)
    {
        return $query->where('direction', 'adjustment');
    }

    /**
     * Verificar si incrementa inventario
     */
    public function increasesInventory(): bool
    {
        return $this->effect === 'increase';
    }

    /**
     * Verificar si decrementa inventario
     */
    public function decreasesInventory(): bool
    {
        return $this->effect === 'decrease';
    }
}
