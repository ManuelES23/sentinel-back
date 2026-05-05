<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmbarqueEmpaqueDetalle extends Model
{
    use HasFactory;

    protected $table = 'embarque_empaque_detalles';

    protected $fillable = [
        'embarque_id', 'produccion_id', 'numero_pallet', 'folio_produccion',
        'productor', 'variedad', 'lote', 'marca', 'lote_producto_terminado',
        'presentacion', 'tipo_empaque', 'etiqueta',
        'calibre', 'fecha_produccion', 'cajas', 'peso_kg', 'peso_bascula_kg', 'is_cola',
        'posicion_carga',
    ];

    protected $casts = [
        'cajas' => 'integer',
        'peso_kg' => 'decimal:2',
        'peso_bascula_kg' => 'decimal:2',
        'fecha_produccion' => 'date:Y-m-d',
        'is_cola' => 'boolean',
        'posicion_carga' => 'integer',
    ];

    public function embarque() { return $this->belongsTo(EmbarqueEmpaque::class, 'embarque_id'); }
    public function produccion() { return $this->belongsTo(ProduccionEmpaque::class, 'produccion_id'); }
}
