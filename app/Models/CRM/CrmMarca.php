<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Support\CRM\NombreCatalogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmMarca extends Model
{
    protected $table = 'crm_marcas';

    protected $fillable = ['empresa_id', 'nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $modelo) {
            $modelo->nombre = NombreCatalogo::limpio($modelo->nombre);
            $modelo->nombre_clave = NombreCatalogo::clave($modelo->nombre);
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function productos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CrmProducto::class, 'marca_id');
    }
}
