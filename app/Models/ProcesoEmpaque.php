<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcesoEmpaque extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'proceso_empaque';

    protected $fillable = [
        'temporada_id', 'entity_id', 'recepcion_id', 'folio_proceso',
        'tipo_carga_id', 'productor_id', 'lote_id', 'etapa_id',
        'cantidad_entrada', 'peso_entrada_kg', 'cantidad_disponible',
        'peso_disponible_kg', 'cantidad_cuarto_frio', 'cantidad_fresco',
        'fecha_entrada', 'fecha_proceso',
        'linea_proceso', 'status', 'observaciones', 'created_by',
    ];

    protected $casts = [
        'cantidad_entrada' => 'integer',
        'peso_entrada_kg' => 'decimal:2',
        'cantidad_disponible' => 'integer',
        'peso_disponible_kg' => 'decimal:2',
        'cantidad_cuarto_frio' => 'integer',
        'cantidad_fresco' => 'integer',
        'fecha_entrada' => 'date:Y-m-d',
        'fecha_proceso' => 'date:Y-m-d',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function recepcion() { return $this->belongsTo(RecepcionEmpaque::class, 'recepcion_id'); }
    public function tipoCarga() { return $this->belongsTo(TipoCarga::class); }
    public function productor() { return $this->belongsTo(Productor::class); }
    public function lote() { return $this->belongsTo(Lote::class); }
    public function etapa() { return $this->belongsTo(Etapa::class); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function producciones() { return $this->hasMany(ProduccionEmpaque::class, 'proceso_id'); }
    public function rezagas() { return $this->hasMany(RezagaEmpaque::class, 'proceso_id'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
    public function scopeEnPiso($query) { return $query->where('status', 'en_piso'); }
    public function scopeDisponible($query) { return $query->whereIn('status', ['en_piso', 'en_proceso']); }
}
