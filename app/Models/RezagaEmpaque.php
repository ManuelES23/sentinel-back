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
        'tipo_rezaga', 'subtipo_rezaga', 'fecha', 'cantidad_kg',
        'modo_registro', 'tipo_carga_id', 'total_cajas', 'peso_bascula_kg',
        'cantidad_unidades_pequenas', 'cantidad_bins', 'motivo',
        'status', 'observaciones', 'created_by',
    ];

    protected $casts = [
        'cantidad_kg' => 'decimal:2',
        'peso_bascula_kg' => 'decimal:2',
        'total_cajas' => 'integer',
        'cantidad_unidades_pequenas' => 'integer',
        'cantidad_bins' => 'integer',
        'fecha' => 'date:Y-m-d',
    ];

    /**
     * `cantidad_bins` solo viaja en el JSON cuando la rezaga se capturó en bins;
     * las rezagas en kg (las de siempre) conservan exactamente el mismo cuerpo.
     */
    public function attributesToArray(): array
    {
        $atributos = parent::attributesToArray();

        if (array_key_exists('cantidad_bins', $atributos) && $atributos['cantidad_bins'] === null) {
            unset($atributos['cantidad_bins']);
        }

        return $atributos;
    }

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function proceso() { return $this->belongsTo(ProcesoEmpaque::class, 'proceso_id'); }
    public function tipoCarga() { return $this->belongsTo(TipoCarga::class, 'tipo_carga_id'); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function ventaDetalles() { return $this->hasMany(SalidaRezagaEmpaqueDetalle::class, 'rezaga_id'); }
    public function salidaDetalles() { return $this->hasMany(SalidaRezagaEmpaqueDetalle::class, 'rezaga_id'); }
    public function ajustesPeso() { return $this->hasMany(AjustePesoRezaga::class, 'rezaga_empaque_id'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
    public function scopeDisponible($query) { return $query->where('status', 'pendiente'); }
}
