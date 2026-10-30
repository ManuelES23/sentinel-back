<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;

/**
 * Reglas de monto de la oportunidad (spec 2026-10-30 §7): la cotización
 * reemplaza al estimado. El monto que toma es la base después de descuento,
 * sin impuestos (CrmCotizacion::baseConDescuento).
 *
 * Se llama dentro de la transacción de CotizacionController y con la fila de
 * la oportunidad bloqueada (lockForUpdate). Con productos_en_oportunidad
 * encendido el monto sigue saliendo de las líneas de la oportunidad, así que
 * aquí no se toca.
 */
class MontoOportunidadService
{
    private const ESTADOS_SIN_VIGENCIA = ['rechazado', 'superado'];

    /**
     * Se creó o se editó una cotización. Solo una oportunidad abierta toma su
     * monto: una ganada no cambia por un borrador nuevo (su monto cambia al
     * aprobar) y una perdida no cambia.
     */
    public function alGuardar(CrmOportunidad $oportunidad, CrmCotizacion $cotizacion): void
    {
        if (! $this->aplica($oportunidad) || $oportunidad->estaCerrada()) {
            return;
        }

        $this->fijar($oportunidad, $cotizacion->baseConDescuento());
    }

    /**
     * Se rechazó una cotización (ya guardada como rechazada). Una oportunidad
     * abierta vuelve al monto de la más reciente que no esté rechazada ni
     * superada; si no queda ninguna conserva el valor.
     */
    public function alRechazar(CrmOportunidad $oportunidad): void
    {
        if (! $this->aplica($oportunidad) || $oportunidad->estaCerrada()) {
            return;
        }

        $vigente = $oportunidad->cotizaciones()
            ->whereNotIn('estado', self::ESTADOS_SIN_VIGENCIA)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($vigente !== null) {
            $this->fijar($oportunidad, $vigente->baseConDescuento());
        }
    }

    /**
     * Se aprobó una cotización: la oportunidad toma su monto, también en el
     * flujo «superado» de una ya ganada. Las perdidas no se aprueban.
     */
    public function alAprobar(CrmOportunidad $oportunidad, CrmCotizacion $cotizacion): void
    {
        if (! $this->aplica($oportunidad) || $oportunidad->estaPerdida()) {
            return;
        }

        $this->fijar($oportunidad, $cotizacion->baseConDescuento());
    }

    private function aplica(CrmOportunidad $oportunidad): bool
    {
        return ! CrmConfiguracionComercial::productosEnOportunidad((int) $oportunidad->empresa_id);
    }

    private function fijar(CrmOportunidad $oportunidad, float $monto): void
    {
        if (abs((float) $oportunidad->monto_esperado - $monto) < 0.005) {
            return;
        }

        $oportunidad->monto_esperado = $monto;
        $oportunidad->save();
    }
}
