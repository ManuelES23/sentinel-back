<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PreEmbarqueEmpaqueDetalle extends Model
{
    use HasFactory;

    protected $table = 'pre_embarque_empaque_detalles';

    protected $fillable = [
        'pre_embarque_id',
        'produccion_id',
        'posicion_carga',
    ];

    protected $casts = [
        'posicion_carga' => 'integer',
    ];

    public function preEmbarque()
    {
        return $this->belongsTo(PreEmbarqueEmpaque::class, 'pre_embarque_id');
    }

    public function produccion()
    {
        return $this->belongsTo(ProduccionEmpaque::class, 'produccion_id');
    }
}
