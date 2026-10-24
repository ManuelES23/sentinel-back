<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmConfiguracionComercial extends Model
{
    public const MONEDAS = ['MXN', 'USD'];

    protected $table = 'crm_configuraciones_comerciales';

    protected $fillable = ['empresa_id', 'descuento_global_habilitado', 'moneda', 'productos_en_oportunidad'];

    protected $casts = [
        'descuento_global_habilitado' => 'boolean',
        'productos_en_oportunidad' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    /** Obtiene (o crea con el default) la configuración de una empresa. */
    public static function paraEmpresa(int $empresaId): self
    {
        return self::firstOrCreate(
            ['empresa_id' => $empresaId],
            ['descuento_global_habilitado' => true, 'moneda' => 'MXN', 'productos_en_oportunidad' => false]
        );
    }

    /** Moneda de la empresa ('MXN' | 'USD'). */
    public static function monedaDe(int $empresaId): string
    {
        return self::paraEmpresa($empresaId)->moneda;
    }

    /** ¿La empresa captura productos en la oportunidad? */
    public static function productosEnOportunidad(int $empresaId): bool
    {
        return (bool) self::paraEmpresa($empresaId)->productos_en_oportunidad;
    }
}
