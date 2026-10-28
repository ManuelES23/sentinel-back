<?php

namespace App\Models\Planeacion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inventario inicial capturado a mano para un grupo de balance en una semana.
 * grupo_clave es el id del grupo, o «v{variedadId}» si el grupo es implícito.
 */
class ProgramaInventarioInicial extends Model
{
    protected $table = 'programa_inventarios_iniciales';

    protected $fillable = ['programa_id', 'grupo_clave', 'cajas'];

    protected $casts = [
        'programa_id' => 'integer',
        'cajas' => 'integer',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }
}
