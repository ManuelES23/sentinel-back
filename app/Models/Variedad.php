<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Variedad extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'variedades';

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'descripcion',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con Cultivo
     */
    public function cultivo()
    {
        return $this->belongsTo(Cultivo::class);
    }

    /**
     * Relación con Usuario
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
