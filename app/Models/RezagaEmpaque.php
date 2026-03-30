<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RezagaEmpaque extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'rezaga_empaque';

    protected $fillable = [
        'temporada_id', 'entity_id', 'proceso_id', 'folio_rezaga',
        'tipo_rezaga', 'fecha', 'cantidad_kg', 'motivo',
        'status', 'observaciones', 'created_by',
    ];

    protected $casts = [
        'cantidad_kg' => 'decimal:2',
        'fecha' => 'date:Y-m-d',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function proceso() { return $this->belongsTo(ProcesoEmpaque::class, 'proceso_id'); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function ventaDetalles() { return $this->hasMany(VentaRezagaEmpaqueDetalle::class, 'rezaga_id'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
    public function scopeDisponible($query) { return $query->where('status', 'pendiente'); }
}
