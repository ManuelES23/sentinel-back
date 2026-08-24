<?php
// app/Models/CRM/CrmOutlookEventoMapeado.php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmOutlookEventoMapeado extends Model
{
    use HasFactory;

    protected $table = 'crm_outlook_eventos_mapeados';

    protected $fillable = [
        'crm_agenda_id',
        'crm_outlook_conexion_id',
        'outlook_event_id',
        'ultima_actualizacion_enviada_at',
    ];

    protected $casts = [
        'ultima_actualizacion_enviada_at' => 'datetime',
    ];

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(CrmAgenda::class, 'crm_agenda_id');
    }

    public function conexion(): BelongsTo
    {
        return $this->belongsTo(CrmOutlookConexion::class, 'crm_outlook_conexion_id');
    }
}
