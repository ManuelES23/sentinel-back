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

    protected $appends = [
        'ticket_transferencia_url',
        'revision_estado',
    ];

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
        'total_bins',
        'precio_bin',
        'monto_total',
        'status',
        'observaciones',
        'ticket_transferencia_path',
        'revision_kilos_ok',
        'revision_importe_ok',
        'revision_observaciones',
        'revision_revisado_por',
        'revision_revisado_en',
        'created_by',
    ];

    protected $casts = [
        'total_peso_kg' => 'decimal:2',
        'precio_kg' => 'decimal:2',
        'total_bins' => 'integer',
        'precio_bin' => 'decimal:2',
        'monto_total' => 'decimal:2',
        'fecha_venta' => 'date:Y-m-d',
        'revision_kilos_ok' => 'boolean',
        'revision_importe_ok' => 'boolean',
        'revision_revisado_en' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * `total_bins` y `precio_bin` solo viajan en el JSON cuando la venta se hizo
     * en bins; las ventas en kg (las de siempre) conservan exactamente el mismo cuerpo.
     */
    public function attributesToArray(): array
    {
        $atributos = parent::attributesToArray();

        foreach (['total_bins', 'precio_bin'] as $clave) {
            if (array_key_exists($clave, $atributos) && $atributos[$clave] === null) {
                unset($atributos[$clave]);
            }
        }

        return $atributos;
    }

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }
    public function revisadoPor() { return $this->belongsTo(User::class, 'revision_revisado_por'); }
    public function detalles() { return $this->hasMany(SalidaRezagaEmpaqueDetalle::class, 'venta_rezaga_id'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByStatus($query, $s) { return $query->where('status', $s); }

    public function getTicketTransferenciaUrlAttribute(): ?string
    {
        return $this->ticket_transferencia_path
            ? asset('storage/' . $this->ticket_transferencia_path)
            : null;
    }

    public function getRevisionEstadoAttribute(): string
    {
        if (is_null($this->revision_kilos_ok) || is_null($this->revision_importe_ok)) {
            return 'sin_revisar';
        }

        return $this->revision_kilos_ok && $this->revision_importe_ok
            ? 'ok'
            : 'con_diferencias';
    }
}
