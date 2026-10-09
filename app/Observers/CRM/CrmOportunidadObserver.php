<?php

namespace App\Observers\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadEtapa;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Registra en crm_oportunidad_etapas la etapa inicial y cada cambio de
 * etapa, venga de donde venga (OportunidadController::cambiarEtapa,
 * CotizacionController al aprobar, o cualquier camino futuro).
 */
class CrmOportunidadObserver
{
    public function created(CrmOportunidad $oportunidad): void
    {
        $this->registrar($oportunidad, null, $oportunidad->created_at ?? now());
    }

    public function updated(CrmOportunidad $oportunidad): void
    {
        if (! $oportunidad->wasChanged('etapa')) {
            return;
        }

        $this->registrar($oportunidad, $oportunidad->getOriginal('etapa'), now());
    }

    private function registrar(CrmOportunidad $oportunidad, ?string $desde, CarbonInterface $cuando): void
    {
        CrmOportunidadEtapa::create([
            'empresa_id' => $oportunidad->empresa_id,
            'oportunidad_id' => $oportunidad->id,
            'etapa_desde' => $desde,
            'etapa_hasta' => $oportunidad->etapa,
            'cambiado_en' => $cuando,
            'user_id' => Auth::id(),
            'inferido' => false,
        ]);
    }
}
