<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Productor o empaque que surte a la empresa. */
class Productor extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    public const TIPOS = ['productor', 'empaque'];

    protected $table = 'abs_productores';

    protected $fillable = [
        'enterprise_id',
        'tipo',
        'nombre',
        'rfc',
        'contacto',
        'telefono',
        'email',
        'ubicacion',
        'notas',
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

    public function cultivos(): BelongsToMany
    {
        return $this->belongsToMany(Cultivo::class, 'abs_productor_cultivo', 'productor_id', 'cultivo_id')
            ->withTimestamps();
    }

    public function convenios(): HasMany
    {
        return $this->hasMany(Convenio::class, 'productor_id');
    }
}
