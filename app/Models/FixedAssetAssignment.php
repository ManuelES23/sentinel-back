<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrega de un activo fijo a una persona. Historial inmutable: al devolverse
 * se cierra (returned_at), nunca se borra. El responsable y el activo se guardan
 * COPIADOS al entregar para que la carta se reimprima idéntica.
 */
class FixedAssetAssignment extends Model
{
    use Loggable;

    public const TIPOS = ['employee', 'sf_employee', 'user'];
    public const CONDICIONES = ['bueno', 'regular', 'malo'];
    public const MOTIVOS = ['renuncia', 'cambio_puesto', 'reemplazo', 'dano', 'otro'];

    protected $fillable = [
        'enterprise_id',
        'fixed_asset_id',
        'assignee_type',
        'assignee_id',
        'assignee_name',
        'assignee_position',
        'assignee_department',
        'area_id',
        'assigned_at',
        'assigned_by',
        'condition_out',
        'accessories',
        'notes',
        'asset_snapshot',
        'returned_at',
        'returned_by',
        'condition_in',
        'return_reason',
        'return_notes',
        'signed_document_path',
        'signed_uploaded_at',
        'signed_uploaded_by',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'fixed_asset_id' => 'integer',
        'assignee_id' => 'integer',
        'assigned_at' => 'date:Y-m-d',
        'returned_at' => 'date:Y-m-d',
        'signed_uploaded_at' => 'datetime',
        'asset_snapshot' => 'array',
    ];

    /** La ruta del archivo no sale por la API: se descarga con un endpoint autenticado. */
    protected $hidden = ['signed_document_path'];

    protected $appends = ['tiene_carta_firmada', 'activa'];

    public function getTieneCartaFirmadaAttribute(): bool
    {
        return ! empty($this->signed_document_path);
    }

    public function getActivaAttribute(): bool
    {
        return $this->returned_at === null;
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->whereNull('returned_at');
    }

    public function scopeDevueltas(Builder $query): Builder
    {
        return $query->whereNotNull('returned_at');
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** Con borrados: el historial debe seguir mostrando activos dados de baja. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function devueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
