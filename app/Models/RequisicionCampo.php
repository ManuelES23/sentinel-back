<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RequisicionCampo extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'requisiciones_campo';

    protected $fillable = [
        'numero_requisicion',
        'enterprise_id',
        'almacen_id',
        'temporada_id',
        'visita_campo_id',
        'solicitante_user_id',
        'fecha_solicitud',
        'enviada_at',
        'status',
        'prioridad',
        'justificacion',
        'notas_rechazo',
        'aprobado_por_user_id',
        'fecha_aprobacion',
        'rechazada_por_user_id',
        'rechazada_at',
        'purchase_order_id',
        'observaciones',
    ];

    protected $casts = [
        'fecha_solicitud' => 'date',
        'fecha_aprobacion' => 'datetime',
        'enviada_at' => 'datetime',
        'rechazada_at' => 'datetime',
    ];

    protected $appends = ['status_label', 'prioridad_label', 'is_editable', 'total_estimado'];

    // ═══════ CONSTANTES ═══════

    const STATUS_BORRADOR = 'borrador';
    const STATUS_ENVIADA = 'enviada';
    const STATUS_EN_COTIZACION = 'en_cotizacion';
    const STATUS_COTIZADA = 'cotizada';
    const STATUS_RECHAZADA = 'rechazada';
    const STATUS_ORDEN_GENERADA = 'orden_generada';
    const STATUS_COMPLETADA = 'completada';
    const STATUS_CANCELADA = 'cancelada';

    /** Estados en los que Compras trabaja la requisición. */
    const STATUS_EN_COMPRAS = ['enviada', 'en_cotizacion', 'cotizada'];

    const STATUS_LABELS = [
        'borrador' => 'Borrador',
        'enviada' => 'Enviada a Compras',
        'en_cotizacion' => 'En cotización',
        'cotizada' => 'Cotizada',
        'rechazada' => 'Regresada por Compras',
        'orden_generada' => 'Orden generada',
        'completada' => 'Completada',
        'cancelada' => 'Cancelada',
    ];

    const PRIORIDAD_LABELS = [
        'baja' => 'Baja',
        'media' => 'Media',
        'alta' => 'Alta',
        'urgente' => 'Urgente',
    ];

    // ═══════ ACCESSORS ═══════

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getPrioridadLabelAttribute(): string
    {
        return self::PRIORIDAD_LABELS[$this->prioridad] ?? $this->prioridad;
    }

    public function getIsEditableAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_BORRADOR, self::STATUS_RECHAZADA]);
    }

    public function getTotalEstimadoAttribute(): float
    {
        return $this->detalles->sum('subtotal_estimado') ?: 0;
    }

    // ═══════ RELACIONES ═══════

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function visitaCampo(): BelongsTo
    {
        return $this->belongsTo(VisitaCampo::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_user_id');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por_user_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'almacen_id');
    }

    public function rechazadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rechazada_por_user_id');
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(RequisicionCotizacion::class, 'requisicion_campo_id');
    }

    public function cotizacionGanadora(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RequisicionCotizacion::class, 'requisicion_campo_id')->where('es_ganadora', true);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(RequisicionCampoDetalle::class);
    }

    public function costeos(): HasMany
    {
        return $this->hasMany(CosteoAgricola::class, 'fuente_id')
            ->where('tipo_fuente', 'requisicion');
    }

    // ═══════ SCOPES ═══════

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePendientes($query)
    {
        return $query->where('status', self::STATUS_ENVIADA);
    }

    public function scopeAprobadas($query)
    {
        return $query->where('status', self::STATUS_ENVIADA);
    }

    // ═══════ GENERADOR DE NÚMERO ═══════

    public static function generateNumero(): string
    {
        $year = date('Y');
        // lockForUpdate: sin el bloqueo, dos altas simultáneas leían el mismo
        // último número y la segunda tronaba por el índice único.
        $last = self::withTrashed()
            ->where('numero_requisicion', 'like', "RC-{$year}-%")
            ->orderByRaw('CAST(SUBSTRING(numero_requisicion, -5) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->first();

        $nextNum = 1;
        if ($last) {
            $lastNum = (int) substr($last->numero_requisicion, -5);
            $nextNum = $lastNum + 1;
        }

        return sprintf("RC-%s-%05d", $year, $nextNum);
    }
}
