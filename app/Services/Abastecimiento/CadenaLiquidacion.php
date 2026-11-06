<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use Illuminate\Support\Collection;

/**
 * La cadena de cada envío a revisión se congela al enviar (plan 2, rulings 4 y 6): un paso por cada
 * `step_order` configurado en el proceso `supply_settlements` para la empresa (o global), numerados
 * 1..n; reglas con el mismo número = alternativas. Sin pasos configurados: un solo paso sin reglas
 * («Quien tenga el permiso de aprobar»). A diferencia de vacaciones, nunca se salta un paso: quien
 * envía comprueba antes que cada uno tenga a alguien que lo decida (`FlujoLiquidacion::enviar`).
 * Los pasos de cada ronda se conservan al rechazar; el siguiente envío crea la ronda siguiente.
 */
class CadenaLiquidacion
{
    /**
     * Pasos (sin guardar) del envío de la ronda `$liquidacion->ronda`, numerados 1..n.
     *
     * @return Collection<int, LiquidacionAprobacion>
     */
    public static function definir(Liquidacion $liquidacion): Collection
    {
        $grupos = self::reglasConfiguradas((int) $liquidacion->enterprise_id);

        if ($grupos->isEmpty()) {
            return collect([self::nuevoPaso($liquidacion, 1, AprobadorLiquidaciones::DESCRIPCION_PERMISO, [])]);
        }

        $orden = 0;

        return $grupos->map(function (Collection $reglas) use ($liquidacion, &$orden) {
            $orden++;

            return self::nuevoPaso(
                $liquidacion,
                $orden,
                // description es string(255): muchas reglas en el mismo paso pueden exceder la columna.
                mb_substr($reglas->map(fn (ApprovalFlowStep $r) => $r->approver_description)->unique()->implode(' o '), 0, 255),
                $reglas->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            );
        })->values();
    }

    /**
     * Guarda los pasos de la ronda actual de la liquidación y deja `paso_actual` en el primero.
     * Corre dentro de la transacción de quien llama.
     *
     * @param  Collection<int, LiquidacionAprobacion>|null  $pasos  los de `definir`, si ya se calcularon
     */
    public static function construir(Liquidacion $liquidacion, ?Collection $pasos = null): void
    {
        $pasos ??= self::definir($liquidacion);
        $pasos->each(fn (LiquidacionAprobacion $paso) => $paso->save());

        $liquidacion->paso_actual = $pasos->min('step_order');
        $liquidacion->save();
    }

    /** El paso en espera de la ronda actual (`paso_actual`); null si la liquidación no está en revisión. */
    public static function pasoActual(Liquidacion $liquidacion): ?LiquidacionAprobacion
    {
        if ($liquidacion->paso_actual === null) {
            return null;
        }

        return LiquidacionAprobacion::where('liquidacion_id', $liquidacion->id)
            ->where('ronda', $liquidacion->ronda)
            ->where('step_order', $liquidacion->paso_actual)
            ->where('decision', 'waiting')
            ->first();
    }

    /** @return Collection<int, Collection<int, ApprovalFlowStep>> reglas activas por `step_order` ascendente */
    private static function reglasConfiguradas(int $empresaId): Collection
    {
        $proceso = ApprovalProcess::findByCode(ApprovalProcess::SUPPLY_SETTLEMENTS);
        if (! $proceso || ! $proceso->is_active || ! $proceso->requires_approval) {
            return collect();
        }

        return $proceso->activeSteps()
            ->with(['position:id,name', 'user:id,name'])
            ->where(fn ($q) => $q->whereNull('enterprise_id')->orWhere('enterprise_id', $empresaId))
            ->orderBy('step_order')->orderBy('id')
            ->get()
            ->groupBy('step_order')
            ->sortKeys();
    }

    private static function nuevoPaso(Liquidacion $liquidacion, int $orden, string $descripcion, array $reglas): LiquidacionAprobacion
    {
        return new LiquidacionAprobacion([
            'liquidacion_id' => $liquidacion->id,
            'ronda' => $liquidacion->ronda,
            'step_order' => $orden,
            'description' => $descripcion,
            'rules' => $reglas,
            'decision' => 'waiting',
        ]);
    }
}
