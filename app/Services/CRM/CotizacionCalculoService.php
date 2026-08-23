<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmConfiguracionImpuesto;
use App\Models\CRM\CrmCotizacion;

/**
 * Única fuente de verdad para los totales de una Cotización. Se llama
 * después de cualquier cambio a sus líneas, descuento, o al crearla.
 * Nunca confía en un total mandado por el cliente.
 */
class CotizacionCalculoService
{
    public function recalcular(CrmCotizacion $cotizacion): CrmCotizacion
    {
        $cotizacion->load('lineas');

        $subtotal = $cotizacion->lineas->sum(fn ($linea) => $linea->importe);
        $baseGravable = round($subtotal * (1 - ((float) $cotizacion->descuento_global_pct / 100)), 2);

        $impuestosActivos = CrmConfiguracionImpuesto::where('empresa_id', $cotizacion->empresa_id)
            ->activos()
            ->get();

        $cotizacion->impuestos()->delete();

        $totalImpuestos = 0.0;
        foreach ($impuestosActivos as $impuesto) {
            $monto = round($baseGravable * ((float) $impuesto->tasa / 100), 2);
            $totalImpuestos += $monto;

            $cotizacion->impuestos()->create([
                'nombre' => $impuesto->nombre,
                'tasa' => $impuesto->tasa,
                'monto' => $monto,
            ]);
        }

        $cotizacion->subtotal = $subtotal;
        $cotizacion->total = round($baseGravable + $totalImpuestos, 2);
        $cotizacion->save();

        return $cotizacion->fresh(['lineas', 'impuestos']);
    }
}
