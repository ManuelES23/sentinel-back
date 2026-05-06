<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalidaRezagaEmpaqueDetalle extends Model
{
    use HasFactory;

    protected $table = 'venta_rezaga_empaque_detalles';

    protected $fillable = ['venta_rezaga_id', 'rezaga_id', 'peso_kg', 'precio_kg', 'monto'];

    protected $casts = [
        'peso_kg' => 'decimal:2',
        'precio_kg' => 'decimal:2',
        'monto' => 'decimal:2',
    ];

    public function salidaRezaga() { return $this->belongsTo(SalidaRezagaEmpaque::class, 'venta_rezaga_id'); }
    public function rezaga() { return $this->belongsTo(RezagaEmpaque::class, 'rezaga_id'); }
}
