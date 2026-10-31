<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmActividad extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_actividades';

    protected $fillable = [
        'empresa_id',
        'tipo',
        'entidad_type',
        'entidad_id',
        'vendedor_id',
        'descripcion',
        'fecha_actividad',
        'duracion_minutos',
        'resultado',
        'archivos_adjuntos',
        'fuente',
        'dialpad_call_id',
        'etiqueta_id',
    ];

    protected $casts = [
        'fecha_actividad'   => 'datetime',
        'archivos_adjuntos' => 'array',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'vendedor_id');
    }

    public function etiqueta(): BelongsTo
    {
        return $this->belongsTo(CrmEtiquetaActividad::class, 'etiqueta_id');
    }

    public function entidad(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopePorEntidad($query, string $type, int $id)
    {
        return $query->where('entidad_type', $type)->where('entidad_id', $id);
    }

    public function scopeEnRango($query, string $desde, string $hasta)
    {
        return $query->whereBetween('fecha_actividad', [$desde, $hasta]);
    }
}
