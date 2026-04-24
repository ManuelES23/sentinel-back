<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalidadEmpaqueMuestra extends Model
{
    use HasFactory;

    protected $table = 'calidad_empaque_muestras';

    protected $fillable = [
        'calidad_id', 'recepcion_id', 'empleado_id', 'empacador_nombre',
        'hora', 'muestra', 'conteo', 'cumple', 'no_cumple',
        'porcentaje_cumple', 'calificacion', 'observaciones',
    ];

    protected $casts = [
        'muestra' => 'integer',
        'conteo' => 'decimal:2',
        'cumple' => 'decimal:2',
        'no_cumple' => 'decimal:2',
        'porcentaje_cumple' => 'decimal:2',
    ];

    public function calidad()
    {
        return $this->belongsTo(CalidadEmpaque::class, 'calidad_id');
    }

    public function recepcion()
    {
        return $this->belongsTo(RecepcionEmpaque::class, 'recepcion_id');
    }

    public function empleado()
    {
        return $this->belongsTo(Employee::class, 'empleado_id');
    }

    public function plagas()
    {
        return $this->hasMany(CalidadEmpaqueMuestraPlaga::class, 'muestra_id');
    }
}
