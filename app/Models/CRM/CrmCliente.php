<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmCliente extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'crm_clientes';

    protected $fillable = [
        'empresa_id',
        'prospecto_id',
        'nombre',
        'rfc',
        'email',
        'telefono',
        'estatus',
        'vendedor_id',
        'region_id',
        'notas',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function prospecto(): BelongsTo
    {
        return $this->belongsTo(CrmProspecto::class, 'prospecto_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'vendedor_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(CrmRegion::class, 'region_id');
    }

    public function contactos(): MorphMany
    {
        return $this->morphMany(CrmContacto::class, 'entidad');
    }

    public function actividades(): MorphMany
    {
        return $this->morphMany(CrmActividad::class, 'entidad');
    }

    public function oportunidades(): HasMany
    {
        return $this->hasMany(CrmOportunidad::class, 'cliente_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('estatus', 'activo');
    }

    public function scopePorVendedor($query, int $vendedorId)
    {
        return $query->where('vendedor_id', $vendedorId);
    }
}
