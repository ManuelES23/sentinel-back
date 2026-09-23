<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Aplicacion extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'aplicaciones';

    protected $fillable = [
        'temporada_id',
        'enterprise_id',
        'almacen_id',
        'inventory_movement_id',
        'folio',
        'fecha',
        'tipo_aplicacion',
        'productor_id',
        'zona_cultivo_id',
        'lote_id',
        'variedad_id',
        'superficie_aplicada',
        'metodo_aplicacion',
        'problematica',
        'observaciones',
        'created_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'superficie_aplicada' => 'decimal:2',
    ];

    // ═══════ SCOPES ═══════

    public function scopePorTemporada($query, int $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeByTemporada($query, int $temporadaId)
    {
        return $this->scopePorTemporada($query, $temporadaId);
    }

    // ═══════ RELACIONES ═══════

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class);
    }

    public function zonaCultivo(): BelongsTo
    {
        return $this->belongsTo(ZonaCultivo::class, 'zona_cultivo_id');
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'almacen_id');
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(AplicacionDetalle::class, 'aplicacion_id');
    }

    // ═══════ HELPER FOLIO ═══════

    /**
     * Genera el siguiente folio secuencial para una temporada.
     * Formato: APL-{AÑO}-{NNNN}
     */
    public static function generarFolio(int $temporadaId): string
    {
        $anio = now()->year;
        $prefix = "APL-{$anio}-";

        // lockForUpdate: sin el bloqueo, dos altas simultáneas leían el mismo
        // último folio y guardaban folios duplicados.
        $ultimo = self::withTrashed()
            ->where('temporada_id', $temporadaId)
            ->where('folio', 'like', "{$prefix}%")
            ->orderByDesc('folio')
            ->lockForUpdate()
            ->value('folio');

        $siguiente = $ultimo
            ? (int) substr($ultimo, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad($siguiente, 4, '0', STR_PAD_LEFT);
    }
}
