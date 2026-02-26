<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitOfMeasure extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'code',
        'name',
        'abbreviation',
        'type',
        'conversion_factor',
        'base_unit_id',
        'precision',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'conversion_factor' => 'decimal:6',
        'precision' => 'integer',
    ];

    /**
     * Unidad base (para conversiones)
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_unit_id');
    }

    /**
     * Unidades derivadas de esta
     */
    public function derivedUnits(): HasMany
    {
        return $this->hasMany(UnitOfMeasure::class, 'base_unit_id');
    }

    /**
     * Productos que usan esta unidad
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    /**
     * Scope para unidades activas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope por tipo
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Convertir cantidad a unidad base
     */
    public function toBaseUnit(float $quantity): float
    {
        return $quantity * $this->conversion_factor;
    }

    /**
     * Convertir cantidad desde unidad base
     */
    public function fromBaseUnit(float $quantity): float
    {
        return $quantity / $this->conversion_factor;
    }
}
