<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoAplicacion extends Model
{
    use HasFactory;

    protected $table = 'productos_aplicacion';

    protected $fillable = [
        'nombre',
        'ingrediente_activo',
        'marca',
        'tipo',
        'activo',
        'product_id',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // ═══════ SCOPES ═══════

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeByTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    // ═══════ RELACIONES ═══════

    public function detalles(): HasMany
    {
        return $this->hasMany(AplicacionDetalle::class, 'producto_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
