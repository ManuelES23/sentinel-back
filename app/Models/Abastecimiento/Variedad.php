<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Variedad de un cultivo de Abastecimiento (p. ej. Biloxi). */
class Variedad extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abs_variedades';

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'is_active',
    ];

    protected $casts = [
        'cultivo_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /** La variedad no guarda la empresa: se resuelve por su cultivo. */
    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->whereHas('cultivo', fn (Builder $c) => $c->where('enterprise_id', $empresaId));
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class, 'cultivo_id');
    }
}
