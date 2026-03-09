<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitaCampo extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'visitas_campo';

    protected $fillable = [
        'temporada_id',
        'user_id',
        'fecha_visita',
        'observaciones_generales',
        'status',
    ];

    protected $casts = [
        'fecha_visita' => 'date',
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

    public function detalles(): HasMany
    {
        return $this->hasMany(VisitaCampoDetalle::class);
    }

    // ── Scopes ──

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeCompletadas($query)
    {
        return $query->where('status', 'completada');
    }

    public function scopeBorradores($query)
    {
        return $query->where('status', 'borrador');
    }
}
