<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCategory extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'code',
        'name',
        'slug',
        'description',
        'parent_id',
        'icon',
        'order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'order' => 'integer',
    ];

    /**
     * Categoría padre (Tipo de Activo)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'parent_id');
    }

    /**
     * Subcategorías (Subtipos de Activo)
     */
    public function children(): HasMany
    {
        return $this->hasMany(AssetCategory::class, 'parent_id');
    }

    /**
     * Todas las subcategorías recursivas
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren')->withCount(['assetsAsCategory', 'assetsAsSubcategory']);
    }

    /**
     * Catálogo de características sugeridas para esta categoría
     * (ej. Subtipo "Laptops" -> Procesador, RAM).
     */
    public function characteristicDefinitions(): HasMany
    {
        return $this->hasMany(AssetCharacteristicDefinition::class, 'category_id')->orderBy('order');
    }

    /**
     * Activos que usan esta categoría como Tipo de Activo
     */
    public function assetsAsCategory(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }

    /**
     * Activos que usan esta categoría como Subtipo de Activo
     */
    public function assetsAsSubcategory(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'subcategory_id');
    }

    /**
     * Scope para categorías activas
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para categorías raíz (sin padre)
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Ruta "Tipo > Subtipo". El catálogo tiene dos niveles; el tope de saltos
     * evita un ciclo infinito si llegara a existir un dato corrupto.
     */
    public function getFullPathAttribute(): string
    {
        $path = [$this->name];
        $parent = $this->parent;
        $saltos = 0;

        while ($parent && $saltos++ < 2) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' > ', $path);
    }
}
