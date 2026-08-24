<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Models\User;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CrmVendedor extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_vendedores';

    protected $fillable = [
        'empresa_id',
        'user_id',
        'nombre',
        'email',
        'telefono',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prospectos(): HasMany
    {
        return $this->hasMany(CrmProspecto::class, 'vendedor_id');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(CrmCliente::class, 'vendedor_id');
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(CrmActividad::class, 'vendedor_id');
    }

    public function oportunidades(): HasMany
    {
        return $this->hasMany(CrmOportunidad::class, 'vendedor_id');
    }

    public function presupuestos(): HasMany
    {
        return $this->hasMany(CrmPresupuesto::class, 'vendedor_id');
    }

    public function agenda(): HasMany
    {
        return $this->hasMany(CrmAgenda::class, 'vendedor_id');
    }

    public function outlookConexion(): HasOne
    {
        return $this->hasOne(CrmOutlookConexion::class, 'crm_vendedor_id');
    }

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}
