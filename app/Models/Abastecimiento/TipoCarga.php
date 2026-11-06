<?php

namespace App\Models\Abastecimiento;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tipo de carga de Abastecimiento (caja, tarima, etc.) con su peso de referencia. */
class TipoCarga extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abs_tipos_carga';

    protected $fillable = [
        'enterprise_id',
        'nombre',
        'peso_kg',
        'is_active',
    ];

    protected $casts = [
        'enterprise_id' => 'integer',
        'peso_kg' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function scopeDeEmpresa(Builder $q, int $empresaId): Builder
    {
        return $q->where($this->getTable().'.enterprise_id', $empresaId);
    }
}
