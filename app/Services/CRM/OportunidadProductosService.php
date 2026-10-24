<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadProducto;
use App\Models\CRM\CrmProducto;
use Illuminate\Support\Facades\DB;

/**
 * Líneas de producto de una oportunidad (empresas con productos_en_oportunidad,
 * p. ej. Splendid by Porvenir). Las líneas de la oportunidad viven en
 * crm_oportunidad_productos con cotizacion_id NULL; las de cotización
 * (cotizacion_id con valor) son independientes y nunca se tocan aquí.
 */
class OportunidadProductosService
{
    /**
     * Reemplaza por completo las líneas de la oportunidad y recalcula
     * monto_esperado. Transaccional (anida bien dentro de otra transacción).
     *
     * @param  array<int, array{producto_id: int|string, cantidad: int|float|string, precio_unitario: int|float|string}>  $lineas
     */
    public function reemplazar(CrmOportunidad $oportunidad, array $lineas): void
    {
        DB::transaction(function () use ($oportunidad, $lineas) {
            $oportunidad->productos()->delete();

            $nombres = CrmProducto::query()
                ->where('empresa_id', $oportunidad->empresa_id)
                ->whereIn('id', array_column($lineas, 'producto_id'))
                ->pluck('nombre', 'id');

            foreach ($lineas as $linea) {
                CrmOportunidadProducto::create([
                    'oportunidad_id' => $oportunidad->id,
                    'cotizacion_id' => null,
                    'producto_id' => $linea['producto_id'],
                    'descripcion' => $nombres[$linea['producto_id']] ?? '',
                    'cantidad' => round((float) $linea['cantidad'], 4),
                    'precio_unitario' => round((float) $linea['precio_unitario'], 2),
                ]);
            }

            $oportunidad->monto_esperado = $this->monto($lineas);
            $oportunidad->save();
        });
    }

    /**
     * round(Σ cantidad × precio_unitario, 2), con cantidad (4 decimales) y
     * precio (2) ya normalizados a la precisión de las columnas, para que el
     * monto cuadre con las líneas guardadas.
     *
     * @param  array<int, array{cantidad: int|float|string, precio_unitario: int|float|string}>  $lineas
     */
    public function monto(array $lineas): float
    {
        $total = 0.0;
        foreach ($lineas as $linea) {
            $total += round((float) $linea['cantidad'], 4) * round((float) $linea['precio_unitario'], 2);
        }

        return round($total, 2);
    }
}
