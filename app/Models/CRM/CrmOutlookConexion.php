<?php
// app/Models/CRM/CrmOutlookConexion.php

namespace App\Models\CRM;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una fila por vendedor con su cuenta de Outlook conectada. access_token y
 * refresh_token nunca deben llegar al frontend -- van en $hidden además del
 * cast encrypted (defensa en profundidad si algún día alguien serializa el
 * modelo completo por error).
 */
class CrmOutlookConexion extends Model
{
    use HasFactory;

    protected $table = 'crm_outlook_conexiones';

    protected $fillable = [
        'empresa_id',
        'crm_vendedor_id',
        'email_outlook',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'ultimo_sync_at',
        'ultimo_error',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'token_expires_at' => 'datetime',
        'ultimo_sync_at'   => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(CrmVendedor::class, 'crm_vendedor_id');
    }

    public function eventosMapeados(): HasMany
    {
        return $this->hasMany(CrmOutlookEventoMapeado::class, 'crm_outlook_conexion_id');
    }
}
