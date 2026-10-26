<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de rechazo de una empresa (spec 2026-10-23 §4.5), p. ej. «Precio».
 * Agrupa los motivos que el vendedor elige al marcar una oportunidad como
 * perdida. Sin SoftDeletes: un motivo usado impide borrar (se desactiva).
 */
class CrmCategoriaRechazo extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_categorias_rechazo';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    /** Mismos valores por defecto que la tabla, para que la respuesta de store() los incluya. */
    protected $attributes = [
        'activo' => true,
        'orden' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function motivos(): HasMany
    {
        return $this->hasMany(CrmMotivoRechazo::class, 'categoria_id')->orderBy('orden')->orderBy('id');
    }

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    /**
     * ¿La empresa tiene catálogo de rechazo utilizable? Sí cuando existe al
     * menos una categoría activa con un motivo activo; entonces al perder una
     * oportunidad el motivo del catálogo es obligatorio.
     */
    public static function empresaTieneCatalogo(int $empresaId): bool
    {
        return static::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->whereHas('motivos', fn ($q) => $q->where('activo', true))
            ->exists();
    }
}
