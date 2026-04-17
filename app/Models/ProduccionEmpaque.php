<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProduccionEmpaque extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'produccion_empaque';

    protected $fillable = [
        'temporada_id', 'entity_id', 'proceso_id', 'recipe_id', 'folio_produccion',
        'fecha_produccion', 'turno', 'variedad_id', 'linea_empaque',
        'numero_pallet', 'lote_producto_terminado', 'pallet_qr_id', 'total_cajas', 'cajas_objetivo', 'peso_neto_kg', 'tipo_empaque',
        'etiqueta', 'calibre', 'categoria', 'status', 'is_cola', 'is_mixto', 'en_cuarto_frio', 'observaciones', 'created_by',
    ];

    protected $casts = [
        'total_cajas' => 'integer',
        'cajas_objetivo' => 'integer',
        'peso_neto_kg' => 'decimal:2',
        'fecha_produccion' => 'date:Y-m-d',
        'is_cola' => 'boolean',
        'is_mixto' => 'boolean',
        'en_cuarto_frio' => 'boolean',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function proceso() { return $this->belongsTo(ProcesoEmpaque::class, 'proceso_id'); }
    public function recipe() { return $this->belongsTo(Recipe::class); }
    public function variedad() { return $this->belongsTo(Variedad::class); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function embarqueDetalles() { return $this->hasMany(EmbarqueEmpaqueDetalle::class, 'produccion_id'); }
    public function evaluacionesCalidad() { return $this->morphMany(CalidadEmpaque::class, 'evaluable'); }
    public function detalles() { return $this->hasMany(ProduccionEmpaqueDetalle::class, 'produccion_id')->orderBy('numero_entrada'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
    public function scopeDisponible($query) { return $query->whereIn('status', ['empacado', 'en_almacen']); }
}
