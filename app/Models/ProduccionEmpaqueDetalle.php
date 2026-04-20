<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProduccionEmpaqueDetalle extends Model
{
    use HasFactory, Loggable;

    protected $table = 'produccion_empaque_detalles';

    protected $fillable = [
        'produccion_id',
        'numero_entrada',
        'proceso_id',
        'recipe_id',
        'tipo_empaque',
        'marca',
        'presentacion',
        'etiqueta',
        'calibre',
        'categoria',
        'fecha_produccion',
        'total_cajas',
        'peso_neto_kg',
        'turno',
        'observaciones',
        'created_by',
    ];

    protected $casts = [
        'total_cajas' => 'integer',
        'peso_neto_kg' => 'decimal:2',
        'fecha_produccion' => 'date:Y-m-d',
        'numero_entrada' => 'integer',
    ];

    public function produccion()
    {
        return $this->belongsTo(ProduccionEmpaque::class, 'produccion_id');
    }

    public function proceso()
    {
        return $this->belongsTo(ProcesoEmpaque::class, 'proceso_id');
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
