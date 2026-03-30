<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConvenioCompraPrecio extends Model
{
    use HasFactory;

    protected $table = 'convenio_compra_precios';

    protected $fillable = [
        'convenio_compra_id',
        'tipo_carga_id',
        'precio_unitario',
        'precio_caja_empacada',
        'porcentaje_productor',
        'vigencia_inicio',
        'vigencia_fin',
        'is_active',
        'notas',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:4',
        'precio_caja_empacada' => 'decimal:4',
        'porcentaje_productor' => 'decimal:2',
        'vigencia_inicio' => 'date',
        'vigencia_fin' => 'date',
        'is_active' => 'boolean',
    ];

    // ─── Relaciones ───────────────────────────────────

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(ConvenioCompra::class, 'convenio_compra_id');
    }

    public function tipoCarga(): BelongsTo
    {
        return $this->belongsTo(TipoCarga::class);
    }

    // ─── Scopes ───────────────────────────────────────

    public function scopeVigentes($query, $fecha = null)
    {
        $fecha = $fecha ?? now()->toDateString();
        return $query->where('is_active', true)
            ->where('vigencia_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('vigencia_fin')
                  ->orWhere('vigencia_fin', '>=', $fecha);
            });
    }
}
