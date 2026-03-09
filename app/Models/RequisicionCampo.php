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
        'temporada_id',
        'visita_campo_id',
        'solicitante_user_id',
        'fecha_solicitud',
        'status',
        'prioridad',
        'justificacion',
        'notas_rechazo',
        'aprobado_por_user_id',
        'fecha_aprobacion',
        'purchase_order_id',
        'observaciones',
    ];

    protected $casts = [
        'fecha_solicitud' => 'date',
        'fecha_aprobacion' => 'datetime',
    ];

    protected $appends = ['status_label', 'prioridad_label', 'is_editable', 'total_estimado'];

    // ═══════ CONSTANTES ═══════

    const STATUS_BORRADOR = 'borrador';
    const STATUS_PENDIENTE = 'pendiente';
    const STATUS_APROBADA = 'aprobada';
    const STATUS_RECHAZADA = 'rechazada';
    const STATUS_ORDEN_GENERADA = 'orden_generada';
    const STATUS_COMPLETADA = 'completada';
    const STATUS_CANCELADA = 'cancelada';

    const STATUS_LABELS = [
        'borrador' => 'Borrador',
        'pendiente' => 'Pendiente Aprobación',
        'aprobada' => 'Aprobada',
        'rechazada' => 'Rechazada',
        'orden_generada' => 'Orden Generada',
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
        return $query->where('status', self::STATUS_PENDIENTE);
    }

    public function scopeAprobadas($query)
    {
        return $query->where('status', self::STATUS_APROBADA);
    }

    // ═══════ GENERADOR DE NÚMERO ═══════

    public static function generateNumero(): string
    {
        $year = date('Y');
        $last = self::withTrashed()
            ->where('numero_requisicion', 'like', "RC-{$year}-%")
            ->orderByRaw('CAST(SUBSTRING(numero_requisicion, -5) AS UNSIGNED) DESC')
            ->first();

        $nextNum = 1;
        if ($last) {
            $lastNum = (int) substr($last->numero_requisicion, -5);
            $nextNum = $lastNum + 1;
        }

        return sprintf("RC-%s-%05d", $year, $nextNum);
    }
}
