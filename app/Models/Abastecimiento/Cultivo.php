<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cultivo del catálogo de Abastecimiento (p. ej. Arándano). */
class Cultivo extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abs_cultivos';

    protected $fillable = [
        'enterprise_id',
        'nombre',
        'is_active',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->where($this->getTable().'.enterprise_id', $empresaId);
    }

    public function variedades(): HasMany
    {
        return $this->hasMany(Variedad::class, 'cultivo_id');
    }

    public function productores(): BelongsToMany
    {
        return $this->belongsToMany(Productor::class, 'abs_productor_cultivo', 'cultivo_id', 'productor_id')
            ->withTimestamps();
    }
}
