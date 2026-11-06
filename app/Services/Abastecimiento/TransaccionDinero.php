<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as DetectorConcurrencia;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transacción de toda escritura que mueve dinero de Abastecimiento (liquidaciones y abonos).
 *
 * Invariante (ver CLAUDE.md, plan 2):
 * - **READ COMMITTED.** MySQL corre REPEATABLE READ: una lectura sin bloqueo dentro de la transacción ve la foto
 *   tomada en su primera lectura, así que una suma de `abs_abono_aplicaciones` no vería lo que otra transacción
 *   confirmó mientras esta esperaba un bloqueo. Con READ COMMITTED cada sentencia lee lo último confirmado.
 *   `SET TRANSACTION ISOLATION LEVEL` solo vale para la SIGUIENTE transacción, por eso se repite antes de cada
 *   intento (el reintento de `DB::transaction($fn, n)` abriría el segundo intento con el nivel por omisión).
 * - **Un solo orden de bloqueo:** la fila del productor PRIMERO, luego abonos (por id) y luego liquidaciones
 *   (por id). Todo escritor de dinero bloquea antes al productor afectado (`bloquearProductor` /
 *   `bloquearLiquidacion`), así que dos escritores del mismo productor se turnan y ninguno lee un saldo viejo.
 *
 * En SQLite (pruebas) el `SET` no se ejecuta y `lockForUpdate` no hace nada: la concurrencia no se puede probar ahí.
 */
class TransaccionDinero
{
    /**
     * Ejecuta `$fn` en una transacción READ COMMITTED (en MySQL) y la reintenta ante un interbloqueo o una falla de
     * serialización hasta `$intentos` veces. Dentro de una transacción ya abierta no cambia el nivel ni reintenta
     * (MySQL no permite cambiarlo a medias y un interbloqueo revierte la transacción de afuera).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function ejecutar(callable $fn, int $intentos = 3): mixed
    {
        $anidada = DB::transactionLevel() > 0;
        $intentos = $anidada ? 1 : max(1, $intentos);

        for ($intento = 1; ; $intento++) {
            if (! $anidada && DB::getDriverName() === 'mysql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            try {
                return DB::transaction(fn () => $fn(), 1);
            } catch (Throwable $e) {
                if ($intento >= $intentos || ! self::esConcurrencia($e)) {
                    throw $e;
                }
            }
        }
    }

    /** Bloquea la fila del productor de la empresa (el primer bloqueo de toda escritura de dinero); 404 si no es suyo. */
    public static function bloquearProductor(int $empresaId, int $productorId, bool $conBorrados = false): Productor
    {
        return Productor::query()
            ->when($conBorrados, fn ($q) => $q->withTrashed())
            ->deEmpresa($empresaId)
            ->lockForUpdate()
            ->findOrFail($productorId);
    }

    /**
     * Para las acciones sobre una liquidación: lee SOLO su `productor_id` (columna que no cambia) sin bloquear,
     * bloquea al productor y después bloquea y recarga la liquidación. 404 si no es de la empresa.
     */
    public static function bloquearLiquidacion(int $empresaId, int $liquidacionId, array $otrosProductores = []): Liquidacion
    {
        $productorId = Liquidacion::deEmpresa($empresaId)->whereKey($liquidacionId)->value('productor_id');
        if ($productorId === null) {
            throw (new ModelNotFoundException)->setModel(Liquidacion::class, [$liquidacionId]);
        }
        self::bloquearProductores($empresaId, array_merge([(int) $productorId], $otrosProductores));

        return Liquidacion::deEmpresa($empresaId)->lockForUpdate()->findOrFail($liquidacionId);
    }

    /** Igual que `bloquearLiquidacion`, para un abono: productor primero y luego el abono. 404 si no es de la empresa. */
    public static function bloquearAbono(int $empresaId, int $abonoId): Abono
    {
        $productorId = Abono::deEmpresa($empresaId)->whereKey($abonoId)->value('productor_id');
        if ($productorId === null) {
            throw (new ModelNotFoundException)->setModel(Abono::class, [$abonoId]);
        }
        self::bloquearProductor($empresaId, (int) $productorId, true);

        return Abono::deEmpresa($empresaId)->lockForUpdate()->findOrFail($abonoId);
    }

    /**
     * Varios productores (p. ej. al cambiar el convenio de un borrador a otro productor), siempre en orden de id
     * para que dos escritores nunca se esperen en cruz. Incluye los borrados (una liquidación vieja los conserva).
     *
     * @param  array<int, int>  $ids
     */
    public static function bloquearProductores(int $empresaId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        foreach ($ids as $id) {
            self::bloquearProductor($empresaId, $id, true);
        }
    }

    private static function esConcurrencia(Throwable $e): bool
    {
        $detector = app()->bound(DetectorConcurrencia::class) ? app(DetectorConcurrencia::class) : new ConcurrencyErrorDetector;

        return $detector->causedByConcurrencyError($e);
    }
}
