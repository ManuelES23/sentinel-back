<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una salida de un activo fijo (venta, desecho, siniestro, donación o traspaso). Historial:
 * al deshacerse se marca `reversed_at`, nunca se borra. El activo y las cifras se guardan
 * COPIADOS a la fecha de la salida para que la carta se reimprima idéntica.
 */
class FixedAssetDisposal extends Model
{
    use Loggable;

    public const TIPOS = ['venta', 'desecho', 'siniestro', 'donacion', 'traspaso'];

    protected $fillable = [
        'enterprise_id', 'fixed_asset_id', 'type', 'disposal_date', 'reason', 'authorized_by_name',
        'previous_status', 'asset_snapshot', 'purchase_value', 'accumulated_depreciation', 'book_value',
        'sale_price', 'result_amount', 'buyer_name', 'buyer_address', 'buyer_identification',
        'payment_method', 'place', 'claim_reference', 'destination_enterprise_id', 'destination_asset_id',
        'signed_document_path', 'signed_uploaded_at', 'signed_uploaded_by', 'created_by', 'created_by_name',
        'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'fixed_asset_id' => 'integer',
        'disposal_date' => 'date:Y-m-d',
        'asset_snapshot' => 'array',
        'purchase_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'book_value' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'result_amount' => 'decimal:2',
        'signed_uploaded_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    /** La ruta del archivo no sale por la API: se descarga con un endpoint autenticado. */
    protected $hidden = ['signed_document_path'];

    /** Tampoco entra a la bitácora (ActivityLog): es una ruta privada del disco. */
    protected array $loggableExcept = ['signed_document_path'];

    protected $appends = ['tiene_carta_firmada', 'vigente'];

    public function getTieneCartaFirmadaAttribute(): bool
    {
        return ! empty($this->signed_document_path);
    }

    public function getVigenteAttribute(): bool
    {
        return $this->reversed_at === null;
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** Con borrados: el historial debe seguir mostrando activos eliminados. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    public function destinationEnterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'destination_enterprise_id');
    }

    public function destinationAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'destination_asset_id')->withTrashed();
    }
}
