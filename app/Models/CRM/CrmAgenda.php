<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CrmAgenda extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_agenda';

    protected $fillable = [
        'empresa_id',
        'vendedor_id',
        'entidad_type',
        'entidad_id',
        'tipo',
        'titulo',
        'descripcion',
        'fecha_inicio',
        'fecha_fin',
        'completado',
        'recordatorio_at',
        'recordatorio_enviado_at',
    ];

    protected $casts = [
        'fecha_inicio'            => 'datetime',
        'fecha_fin'               => 'datetime',
        'completado'              => 'boolean',
        'recordatorio_at'         => 'datetime',
        'recordatorio_enviado_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'vendedor_id');
    }

    public function entidad(): MorphTo
    {
        return $this->morphTo();
    }

    public function outlookMapeo(): HasOne
    {
        return $this->hasOne(CrmOutlookEventoMapeado::class, 'crm_agenda_id');
    }

    public function scopePendientes($query)
    {
        return $query->where('completado', false);
    }

    public function scopeVencidos($query)
    {
        return $query->where('completado', false)->where('fecha_fin', '<', now());
    }

    /**
     * Recordatorios que deben notificarse AHORA: no completados, con
     * recordatorio_at ya vencido, y que todavía no se marcaron como
     * enviados (recordatorio_enviado_at nulo). Sin este último filtro, el
     * comando programado (cada 5 min) re-notificaría el mismo evento en
     * cada corrida mientras el evento siga sin completarse.
     */
    public function scopeConRecordatorioPendiente($query)
    {
        return $query->where('completado', false)
            ->whereNotNull('recordatorio_at')
            ->where('recordatorio_at', '<=', now())
            ->whereNull('recordatorio_enviado_at');
    }
}
