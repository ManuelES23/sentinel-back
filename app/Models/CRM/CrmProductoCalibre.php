<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Calibre propio de un producto del catálogo (cuando no usa los de la variedad). */
class CrmProductoCalibre extends Model
{
    protected $table = 'crm_producto_calibres';

    protected $fillable = ['producto_id', 'nombre', 'orden', 'activo'];

    protected $casts = [
        'orden'  => 'integer',
        'activo' => 'boolean',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(CrmProducto::class, 'producto_id');
    }
}
