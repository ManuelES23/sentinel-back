<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitaCampoDetalle extends Model
{
    use HasFactory;

    protected $table = 'visita_campo_detalles';

    protected $fillable = [
        'visita_campo_id',
        'etapa_id',
        'etapa_fenologica_id',
        'poblacion_plantas_ha',
        'fecha_siembra_real',
        'fecha_cosecha_proyectada',
        'observaciones',
        'recomendaciones_generales',
    ];

    protected $casts = [
        'poblacion_plantas_ha' => 'integer',
        'fecha_siembra_real' => 'date',
        'fecha_cosecha_proyectada' => 'date',
    ];

    // ── Relaciones ──

    public function visitaCampo(): BelongsTo
    {
        return $this->belongsTo(VisitaCampo::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function etapaFenologica(): BelongsTo
    {
        return $this->belongsTo(EtapaFenologica::class);
    }

    public function plagas(): HasMany
    {
        return $this->hasMany(VisitaCampoPlaga::class);
    }

    public function recomendaciones(): HasMany
    {
        return $this->hasMany(VisitaCampoRecomendacion::class);
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(VisitaCampoFoto::class);
    }
}
