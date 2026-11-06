<?php

namespace App\Services\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Enterprise;
use Illuminate\Support\Facades\Log;

/**
 * Lo que sigue a una decisión de la cadena (enviar, aprobar, rechazar, cancelar) DESPUÉS de confirmar la
 * transacción: difunde el cambio de la liquidación y manda el aviso que corresponde. Cada paso va aislado en su
 * propio try/catch: una falla de Reverb o de una notificación nunca deshace ni rompe la operación ya guardada.
 * Lo comparten la pantalla de Liquidaciones y la bandeja de pendientes del perfil.
 */
class DespuesDeDecidirLiquidacion
{
    /**
     * @param  (callable(Liquidacion): mixed)|null  $aviso  p. ej. `AvisosAbastecimiento::pasoEnEspera(...)`
     */
    public static function ejecutar(Liquidacion $liquidacion, Enterprise $empresa, ?callable $aviso = null): void
    {
        try {
            $resumen = app(PresentadorLiquidacion::class);
            $data = $resumen->resumen($liquidacion->load($resumen->cargaResumen()));
            $pendiente = broadcast(new AbastecimientoUpdated('updated', $data, $empresa->slug, 'liquidacion'));
            // PendingBroadcast emite al destruirse; forzarlo aquí mantiene la excepción dentro del try.
            unset($pendiente);
        } catch (\Throwable $e) {
            Log::warning('Abastecimiento: no se pudo emitir el evento en tiempo real', [
                'entidad' => 'liquidacion',
                'accion' => 'updated',
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($aviso !== null) {
                $aviso($liquidacion);
            }
        } catch (\Throwable $e) {
            Log::warning('Abastecimiento: no se pudo avisar del cambio de la liquidación', [
                'liquidacion_id' => $liquidacion->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
