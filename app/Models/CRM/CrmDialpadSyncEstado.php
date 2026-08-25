<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una sola fila en toda la tabla -- no hay empresa_id porque
 * CRM_DIALPAD_API_KEY es una única clave compartida por todo Sentinel; la
 * empresa de cada llamada se resuelve por vendedor, nunca aquí (ver
 * SincronizarDialpadCommand). obtenerSingleton() es el único punto de
 * entrada: crea la fila la primera vez que se necesita, la reutiliza
 * después.
 */
class CrmDialpadSyncEstado extends Model
{
    use HasFactory;

    protected $table = 'crm_dialpad_sync_estado';

    protected $fillable = [
        'ultimo_call_id_sincronizado',
        'ultimo_sync_at',
        'ultimo_error',
    ];

    protected $casts = [
        'ultimo_sync_at' => 'datetime',
    ];

    public static function obtenerSingleton(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
