<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalidaRezagaEmpaque extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'venta_rezaga_empaque';

    protected $fillable = [
        'temporada_id',
        'entity_id',
        'folio_venta',
        'comprador',
        'fecha_venta',
        'tipo_salida',
        'autorizado_por',
        'solicitado_por',
        'chofer',
        'placa',
        'total_peso_kg',
        'precio_kg',
        'monto_total',
        'status',
        'observaciones',
        'created_by',
    ];

    protected $casts = [
        'total_peso_kg' => 'decimal:2',
        'precio_kg' => 'decimal:2',
        'monto_total' => 'decimal:2',
        'fecha_venta' => 'date:Y-m-d',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function detalles() { return $this->hasMany(SalidaRezagaEmpaqueDetalle::class, 'venta_rezaga_id'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
}
