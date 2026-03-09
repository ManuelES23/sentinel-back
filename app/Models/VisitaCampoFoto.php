<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitaCampoFoto extends Model
{
    use HasFactory;

    protected $table = 'visita_campo_fotos';

    protected $fillable = [
        'visita_campo_detalle_id',
        'foto_path',
        'descripcion',
    ];

    // ── Relaciones ──

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(VisitaCampoDetalle::class, 'visita_campo_detalle_id');
    }

    // ── Accessors ──

    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto_path ? asset('storage/' . $this->foto_path) : null;
    }
}
