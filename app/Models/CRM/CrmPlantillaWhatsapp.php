<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla de mensaje de WhatsApp de una empresa (CRM · WhatsApp Fase 0).
 * Sin SoftDeletes, como las regiones. El cuerpo solo admite las variables de
 * App\Support\CRM\PlantillaWhatsappVariables (se valida en el controlador).
 */
class CrmPlantillaWhatsapp extends Model
{
    use HasFactory, Loggable;

    protected $table = 'crm_plantillas_whatsapp';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'cuerpo',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    /** Mismos valores por defecto que la tabla, para que la respuesta de store() los incluya. */
    protected $attributes = [
        'activo' => true,
        'orden' => 0,
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }
}
