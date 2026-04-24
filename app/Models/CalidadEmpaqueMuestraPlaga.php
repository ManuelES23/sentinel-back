<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalidadEmpaqueMuestraPlaga extends Model
{
    use HasFactory;

    protected $table = 'calidad_empaque_muestra_plagas';

    protected $fillable = ['muestra_id', 'plaga_id', 'cantidad'];

    protected $casts = [
        'cantidad' => 'decimal:2',
    ];

    public function muestra()
    {
        return $this->belongsTo(CalidadEmpaqueMuestra::class, 'muestra_id');
    }

    public function plaga()
    {
        return $this->belongsTo(Plaga::class, 'plaga_id');
    }
}
