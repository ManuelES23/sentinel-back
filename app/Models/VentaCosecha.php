<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaCosecha extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'ventas_cosecha';

    protected $fillable = [
        'temporada_id',
        'cierre_cosecha_id',
        'fecha_venta',
        'cliente',
        'producto',
        'cantidad',
        'unidad_medida',
        'precio_unitario',
        'total',
        'moneda',
        'factura',
        'observaciones',
        'status',
        'created_by',
    ];

    protected $casts = [
        'cantidad' => 'decimal:2',
        'precio_unitario' => 'decimal:2',
        'total' => 'decimal:2',
        'fecha_venta' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ── Relaciones ──────────────────────────────────────

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function cierreCosecha(): BelongsTo
    {
        return $this->belongsTo(CierreCosecha::class, 'cierre_cosecha_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }
}
