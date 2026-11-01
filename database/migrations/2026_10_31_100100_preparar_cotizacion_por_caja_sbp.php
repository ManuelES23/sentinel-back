<?php

use App\Models\CRM\CrmConfiguracionComercial;
use App\Support\CRM\CotizacionPorCajaSbp;
use App\Support\CRM\EmpresasCrm;
use Illuminate\Database\Migrations\Migration;

/**
 * Prepara Splendid by Porvenir para cotizar por caja (spec 2026-10-31 §5):
 * renombra/desactiva productos de elote, liga variedades, precarga presentaciones
 * y enciende el interruptor. SBP se localiza por slug; si no existe no hace nada.
 * Re-ejecutable sin duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sbpId = EmpresasCrm::idPorSlug(EmpresasCrm::SLUG_SBP);
        if ($sbpId !== null) {
            CotizacionPorCajaSbp::aplicar($sbpId);
        }
    }

    public function down(): void
    {
        // Solo apaga el interruptor; los datos preparados se conservan.
        $sbpId = EmpresasCrm::idPorSlug(EmpresasCrm::SLUG_SBP);
        if ($sbpId !== null) {
            CrmConfiguracionComercial::paraEmpresa($sbpId)->update(['cotizacion_por_caja' => false]);
        }
    }
};
