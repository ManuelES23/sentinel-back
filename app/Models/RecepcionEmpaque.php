<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecepcionEmpaque extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'recepciones_empaque';

    protected $fillable = [
        'temporada_id', 'entity_id', 'salida_campo_id', 'folio_recepcion',
        'fecha_recepcion', 'hora_recepcion', 'productor_id', 'lote_id',
        'etapa_id', 'zona_cultivo_id', 'tipo_carga_id', 'cantidad_recibida',
        'peso_recibido_kg', 'peso_bascula', 'folio_ticket_bascula', 'clave_we', 'lote_origen',
        'temperatura', 'transportista', 'vehiculo',
        'chofer', 'es_batanga', 'status', 'observaciones', 'recibido_por', 'created_by',
    ];

    protected $casts = [
        'cantidad_recibida' => 'integer',
        'peso_recibido_kg' => 'decimal:2',
        'peso_bascula' => 'decimal:2',
        'temperatura' => 'decimal:2',
        'fecha_recepcion' => 'date:Y-m-d',
        'es_batanga' => 'boolean',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function salidaCampo() { return $this->belongsTo(SalidaCampoCosecha::class, 'salida_campo_id'); }
    public function productor() { return $this->belongsTo(Productor::class); }
    public function lote() { return $this->belongsTo(Lote::class); }
    public function etapa() { return $this->belongsTo(Etapa::class); }
    public function zonaCultivo() { return $this->belongsTo(ZonaCultivo::class); }
    public function tipoCarga() { return $this->belongsTo(TipoCarga::class); }
    public function recibidoPor() { return $this->belongsTo(User::class, 'recibido_por'); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function procesos() { return $this->hasMany(ProcesoEmpaque::class, 'recepcion_id'); }
    public function evaluacionesCalidad() { return $this->morphMany(CalidadEmpaque::class, 'evaluable'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
    public function scopeByEntity($query, $id) { return $query->where('entity_id', $id); }
}
