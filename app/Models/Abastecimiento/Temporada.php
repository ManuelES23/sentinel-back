<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Temporada de abastecimiento de un cultivo. */
class Temporada extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    public const ESTADOS = ['planeada', 'activa', 'cerrada'];

    protected $table = 'abs_temporadas';

    protected $fillable = [
        'enterprise_id',
        'cultivo_id',
        'nombre',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'cultivo_id' => 'integer',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
    ];

    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->where($this->getTable().'.enterprise_id', $empresaId);
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class, 'cultivo_id');
    }

    public function convenios(): HasMany
    {
        return $this->hasMany(Convenio::class, 'temporada_id');
    }
}
