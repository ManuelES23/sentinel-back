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
        'temporada_id', 'entity_id', 'folio_embarque', 'manifiesto', 'genera_manifiesto',
        // Datos empresa (snapshot)
        'empresa_razon_social', 'empresa_rfc', 'empresa_direccion',
        'empresa_ciudad', 'empresa_pais', 'empresa_agente_aduana_mx',
        // Factura
        'factura',
        // Consignatario
        'consignatario_id', 'consigne_nombre', 'consigne_rfc',
        'consigne_direccion', 'consigne_ciudad', 'consigne_pais',
        'consigne_agente_aduana_eua', 'consigne_bodega',
        // Base
        'tipo_venta', 'cliente', 'destino', 'destino_consignatario_id',
        'fecha_embarque', 'total_pallets', 'total_cajas', 'peso_total_kg',
        'codigo_rastreo', 'espacios_caja',
        // Transporte
        'transportista', 'vehiculo', 'chofer', 'rfc_chofer',
        'numero_contenedor', 'marca_caja', 'placa_caja',
        'placa_tracto', 'marca_tracto', 'scac',
        'sello', 'temperatura', 'capacidad_volumen',
        'status', 'observaciones', 'created_by',
    ];

    protected $casts = [
        'total_pallets' => 'integer',
        'total_cajas' => 'integer',
        'peso_total_kg' => 'decimal:2',
        'temperatura' => 'decimal:1',
        'fecha_embarque' => 'date:Y-m-d',
        'genera_manifiesto' => 'boolean',
        'espacios_caja' => 'integer',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function detalles() { return $this->hasMany(EmbarqueEmpaqueDetalle::class, 'embarque_id'); }
    public function consignatario() { return $this->belongsTo(Consignatario::class); }
    public function destinoConsignatario() { return $this->belongsTo(Consignatario::class, 'destino_consignatario_id'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }
}
