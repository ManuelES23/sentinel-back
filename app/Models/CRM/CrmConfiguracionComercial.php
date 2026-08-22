<?php

namespace App\Models\CRM;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmConfiguracionComercial extends Model
{
    protected $table = 'crm_configuraciones_comerciales';

    protected $fillable = ['empresa_id', 'descuento_global_habilitado'];

    protected $casts = ['descuento_global_habilitado' => 'boolean'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'empresa_id');
    }

    /** Obtiene (o crea con el default) la configuración de una empresa. */
    public static function paraEmpresa(int $empresaId): self
    {
        return self::firstOrCreate(
            ['empresa_id' => $empresaId],
            ['descuento_global_habilitado' => true]
        );
    }
}
