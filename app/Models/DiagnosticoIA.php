<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosticoIA extends Model
{
    use HasFactory, Loggable;

    protected $table = 'diagnosticos_ia';

    protected $fillable = [
        'temporada_id',
        'user_id',
        'visita_campo_id',
        'etapa_id',
        'imagen_path',
        'imagen_url',
        'contexto_agricola',
        'diagnostico',
        'plagas_detectadas',
        'enfermedades_detectadas',
        'estado_fenologico',
        'recomendaciones',
        'nivel_urgencia',
        'confianza',
        'modelo_ia',
        'tokens_usados',
        'status',
        'error_message',
    ];

    protected $casts = [
        'contexto_agricola' => 'array',
        'plagas_detectadas' => 'array',
        'enfermedades_detectadas' => 'array',
        'recomendaciones' => 'array',
        'confianza' => 'decimal:2',
        'tokens_usados' => 'integer',
    ];

    const STATUS_PROCESANDO = 'procesando';
    const STATUS_COMPLETADO = 'completado';
    const STATUS_ERROR = 'error';

    const URGENCIA_BAJO = 'bajo';
    const URGENCIA_MEDIO = 'medio';
    const URGENCIA_ALTO = 'alto';
    const URGENCIA_CRITICO = 'critico';

    const URGENCIA_LABELS = [
        'bajo' => 'Bajo',
        'medio' => 'Medio',
        'alto' => 'Alto',
        'critico' => 'Crítico',
    ];

    // ── Relaciones ──

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visitaCampo(): BelongsTo
    {
        return $this->belongsTo(VisitaCampo::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    // ── Scopes ──

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeCompletados($query)
    {
        return $query->where('status', self::STATUS_COMPLETADO);
    }
}
