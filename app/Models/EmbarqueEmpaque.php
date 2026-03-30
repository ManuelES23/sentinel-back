<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmbarqueEmpaque extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'embarques_empaque';

    protected $fillable = [
        'temporada_id', 'entity_id', 'folio_embarque', 'tipo_venta',
        'cliente', 'destino', 'fecha_embarque', 'total_pallets',
        'total_cajas', 'peso_total_kg', 'transportista', 'vehiculo',
        'chofer', 'numero_contenedor', 'sello', 'temperatura',
        'status', 'observaciones', 'created_by',
    ];

    protected $casts = [
        'total_pallets' => 'integer',
        'total_cajas' => 'integer',
        'peso_total_kg' => 'decimal:2',
        'temperatura' => 'decimal:1',
        'fecha_embarque' => 'date:Y-m-d',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function detalles() { return $this->hasMany(EmbarqueEmpaqueDetalle::class, 'embarque_id'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
}
