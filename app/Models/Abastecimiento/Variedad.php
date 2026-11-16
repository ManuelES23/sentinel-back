<?php

namespace App\Models\Abastecimiento;

use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProductoCalibre;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Variedad de un cultivo de Abastecimiento (p. ej. Biloxi). */
class Variedad extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'abs_variedades';

    protected $fillable = [
        'cultivo_id',
        'producto_id',
        'nombre',
        'is_active',
    ];

    protected $casts = [
        'cultivo_id' => 'integer',
        'producto_id' => 'integer',
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

    /**
     * Producto del CRM de la empresa del que salen los calibres (null = sin calibres). En el CRM cada
     * producto es una variedad comercial (p. ej. «Kent Mango») con sus propios calibres.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(CrmProducto::class, 'producto_id');
    }

    /** Calibres de la variedad: los del producto del CRM ligado (crm_producto_calibres). */
    public function calibres(): HasMany
    {
        return $this->hasMany(CrmProductoCalibre::class, 'producto_id', 'producto_id');
    }
}
