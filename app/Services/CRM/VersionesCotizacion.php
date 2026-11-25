<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmCotizacionVersion;

/**
 * Versiones de una cotización enviada (solo SBP, spec 2026-11-25 §4): una foto
 * de los renglones, el descuento y los totales al enviar (versión 1) y en cada
 * edición posterior, con su motivo. Se llama dentro de la transacción del
 * controlador, con la cotización ya guardada y recalculada.
 */
class VersionesCotizacion
{
    public function registrar(CrmCotizacion $cotizacion, ?string $motivo, ?int $userId): CrmCotizacionVersion
    {
        $cotizacion->refresh();

        $renglones = $cotizacion->lineas()->orderBy('id')->get()
            ->map(fn ($linea) => [
                'producto_id' => $linea->producto_id,
                'descripcion' => $linea->descripcion,
                'calibre' => $linea->calibre,
                'presentacion' => $linea->presentacion,
                'cantidad' => (float) $linea->cantidad,
                'precio_unitario' => (float) $linea->precio_unitario,
            ])
            ->values()
            ->all();

        $numero = (int) CrmCotizacionVersion::where('cotizacion_id', $cotizacion->id)->max('numero') + 1;

        return CrmCotizacionVersion::create([
            'cotizacion_id' => $cotizacion->id,
            'numero' => $numero,
            'renglones' => $renglones,
            'descuento_global_pct' => $cotizacion->descuento_global_pct,
            'subtotal' => $cotizacion->subtotal,
            'total' => $cotizacion->total,
            'motivo' => $motivo,
            'user_id' => $userId,
        ]);
    }
}
