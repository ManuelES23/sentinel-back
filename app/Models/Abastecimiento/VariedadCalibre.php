<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Calibre de una variedad de Abastecimiento (p. ej. Jumbo, Grande). */
class VariedadCalibre extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abs_variedad_calibres';

    protected $fillable = [
        'variedad_id',
        'nombre',
        'orden',
        'is_active',
    ];

    protected $casts = [
        'variedad_id' => 'integer',
        'orden' => 'integer',
        'is_active' => 'boolean',
    ];

    /** El calibre no guarda la empresa: se resuelve por el cultivo de su variedad. */
    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->whereHas('variedad.cultivo', fn (Builder $c) => $c->where('enterprise_id', $empresaId));
    }

    public function variedad(): BelongsTo
    {
        return $this->belongsTo(Variedad::class, 'variedad_id');
    }
}
