<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consignatario extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'enterprise_id', 'nombre', 'rfc_tax_id', 'direccion',
        'ciudad', 'pais', 'agente_aduana', 'bodega', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function enterprise() { return $this->belongsTo(Enterprise::class); }

    public function scopeActive($query) { return $query->where('is_active', true); }
}
