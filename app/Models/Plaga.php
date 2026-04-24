<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plaga extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'plagas';

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'abreviatura',
        'nombre_cientifico',
        'tipo',
        'descripcion',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    public function scopeByCultivo($query, $cultivoId)
    {
        return $query->where('cultivo_id', $cultivoId);
    }

    public function cultivo()
    {
        return $this->belongsTo(Cultivo::class);
    }
}
