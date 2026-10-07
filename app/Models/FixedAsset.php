<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FixedAsset extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    public const STATUSES = [
        'en_uso' => 'En uso',
        'en_mantenimiento' => 'En mantenimiento',
        'disponible' => 'Disponible',
        'resguardo' => 'En resguardo',
        'fuera_de_servicio' => 'Fuera de servicio',
        'baja' => 'Baja',
    ];

    protected $fillable = [
        'enterprise_id',
        'code',
        'image',
        'name',
        'slug',
        'serial_number',
        'model',
        'year',
        'brand_id',
        'category_id',
        'subcategory_id',
        'branch_id',
        'entity_id',
        'area_id',
        'status',
        'useful_life_years',
        'performance_unit_id',
        'description',
        'observations',
        'purchase_date',
        'invoice_number',
        'purchase_value',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'year' => 'integer',
        'useful_life_years' => 'integer',
        'purchase_date' => 'date',
        'purchase_value' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (FixedAsset $asset) {
            if (empty($asset->slug)) {
                $asset->slug = Str::slug($asset->name).'-'.Str::random(6);
            }
        });

        static::updating(function (FixedAsset $asset) {
            if ($asset->isDirty('name') && empty($asset->slug)) {
                $asset->slug = Str::slug($asset->name).'-'.Str::random(6);
            }
        });
    }

    // Relaciones
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'subcategory_id');
    }

    /**
     * Características capturadas para este activo (nombre/valor libres,
     * opcionalmente ligadas a una definición del catálogo de su categoría).
     */
    public function characteristics(): HasMany
    {
        return $this->hasMany(FixedAssetCharacteristic::class)->orderBy('order');
    }

    /** Historial de entregas, de la más reciente a la más antigua. */
    public function asignaciones(): HasMany
    {
        return $this->hasMany(FixedAssetAssignment::class)->orderByDesc('id');
    }

    /** Entrega vigente (sin devolución). Como máximo una, la garantiza AsignadorActivos. */
    public function asignacionActiva(): HasOne
    {
        return $this->hasOne(FixedAssetAssignment::class)->whereNull('returned_at');
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function performanceUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'performance_unit_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeInBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeInEntity($query, int $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    /**
     * Nombre legible del estado
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Obtener URL de imagen
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return asset('storage/'.$this->image);
    }
}
