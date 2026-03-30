<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmbarqueEmpaqueDetalle extends Model
{
    use HasFactory;

    protected $table = 'embarque_empaque_detalles';

    protected $fillable = ['embarque_id', 'produccion_id', 'cajas', 'peso_kg'];

    protected $casts = [
        'cajas' => 'integer',
        'peso_kg' => 'decimal:2',
    ];

    public function embarque() { return $this->belongsTo(EmbarqueEmpaque::class, 'embarque_id'); }
    public function produccion() { return $this->belongsTo(ProduccionEmpaque::class, 'produccion_id'); }
}
