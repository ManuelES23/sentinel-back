<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RequisicionCotizacion extends Model
{
    use SoftDeletes;

    protected $table = 'requisicion_cotizaciones';

    protected $fillable = [
        'requisicion_campo_id', 'supplier_id', 'folio_proveedor', 'fecha', 'vigencia',
        'dias_entrega', 'condiciones_pago', 'archivo_path', 'notas',
        'subtotal', 'iva', 'total', 'es_ganadora', 'created_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'vigencia' => 'date',
        'dias_entrega' => 'integer',
        'subtotal' => 'decimal:4',
        'iva' => 'decimal:4',
        'total' => 'decimal:4',
        'es_ganadora' => 'boolean',
    ];

    protected $appends = ['archivo_url'];

    public function getArchivoUrlAttribute(): ?string
    {
        return $this->archivo_path ? asset('storage/' . $this->archivo_path) : null;
    }

    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(RequisicionCampo::class, 'requisicion_campo_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RequisicionCotizacionDetalle::class, 'cotizacion_id');
    }
}
