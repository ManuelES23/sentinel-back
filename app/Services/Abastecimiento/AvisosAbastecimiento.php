<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Liquidacion;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Avisos internos del flujo de liquidaciones (spec §8): al entrar a un paso, a quienes pueden decidirlo hoy
 * (nunca a quien creó o envió ni a quien ya decidió otro paso: `FlujoLiquidacion::decididoresDe` ya los excluye);
 * al rechazarla, a quien la creó. El controlador los llama DESPUÉS de confirmar la transacción y dentro de un
 * try/catch: una falla aquí no puede deshacer ni romper el flujo. Cada destinatario se aísla de los demás.
 */
class AvisosAbastecimiento
{
    public const ENLACE = '/splendidbyporvenir/administration/abastecimiento/liquidaciones';

    /** Aviso a cada persona que puede decidir el paso actual (vacío si no está en revisión). */
    public static function pasoEnEspera(Liquidacion $liquidacion): void
    {
        $liquidacion = $liquidacion->fresh(['productor:id,nombre']) ?? $liquidacion;
        $paso = CadenaLiquidacion::pasoActual($liquidacion);
        if ($paso === null) {
            return;
        }

        $productor = $liquidacion->productor?->nombre ?? 'el productor';
        $monto = self::monto($liquidacion);
        $mensaje = "La liquidación {$liquidacion->folio} de {$productor} por {$monto} espera tu decisión en el paso {$paso->step_order} ({$paso->description}).";

        foreach (FlujoLiquidacion::decididoresDe($liquidacion) as $usuario) {
            self::aislar($liquidacion, $usuario, fn () => NotificationService::toUser($usuario)
                ->high()
                ->icon('ClipboardCheck', 'amber')
                ->withAction(self::ENLACE, 'Ver liquidaciones')
                ->info('Liquidación por revisar', $mensaje));
        }
    }

    /** Aviso a quien creó la liquidación de que se rechazó y con qué motivo. */
    public static function rechazada(Liquidacion $liquidacion): void
    {
        $liquidacion = $liquidacion->fresh(['productor:id,nombre']) ?? $liquidacion;
        $creador = $liquidacion->created_by === null ? null : User::find($liquidacion->created_by);
        if ($creador === null) {
            return;
        }

        $productor = $liquidacion->productor?->nombre ?? 'el productor';
        $mensaje = "La liquidación {$liquidacion->folio} de {$productor} fue rechazada y regresó a borrador. Motivo: {$liquidacion->rechazo_motivo}";

        self::aislar($liquidacion, $creador, fn () => NotificationService::toUser($creador)
            ->high()
            ->icon('XCircle', 'red')
            ->withAction(self::ENLACE, 'Ver liquidaciones')
            ->alert('Liquidación rechazada', $mensaje));
    }

    private static function monto(Liquidacion $l): string
    {
        return '$'.number_format((float) $l->monto_final, 2).' '.$l->moneda;
    }

    /** Un destinatario que falla se registra y no impide avisar a los demás. */
    private static function aislar(Liquidacion $liquidacion, User $usuario, callable $enviar): void
    {
        try {
            $enviar();
        } catch (\Throwable $e) {
            Log::warning('Abastecimiento: no se pudo enviar el aviso de la liquidación', [
                'liquidacion_id' => $liquidacion->id,
                'user_id' => $usuario->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
