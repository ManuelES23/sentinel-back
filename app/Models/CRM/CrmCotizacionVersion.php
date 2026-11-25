<?php

namespace App\Models\CRM;

use App\Models\User;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto de una cotización enviada (renglones, descuento y totales) para
 * compararla con las versiones siguientes (spec 2026-11-25 §4).
 */
class CrmCotizacionVersion extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_cotizacion_versiones';

    public const UPDATED_AT = null;

    protected $fillable = [
        'cotizacion_id', 'numero', 'renglones', 'descuento_global_pct',
        'subtotal', 'total', 'motivo', 'user_id',
    ];

    protected $casts = [
        'numero' => 'integer',
        'renglones' => 'array',
        'descuento_global_pct' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(CrmCotizacion::class, 'cotizacion_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
