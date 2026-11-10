<?php

namespace App\Models\CRM;

use App\Support\CRM\NombreCatalogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSublinea extends Model
{
    protected $table = 'crm_sublineas';

    protected $fillable = ['linea_id', 'nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $modelo) {
            $modelo->nombre = NombreCatalogo::limpio($modelo->nombre);
            $modelo->nombre_clave = NombreCatalogo::clave($modelo->nombre);
        });
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(CrmLinea::class, 'linea_id');
    }

    public function productos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CrmProducto::class, 'sublinea_id');
    }
}
