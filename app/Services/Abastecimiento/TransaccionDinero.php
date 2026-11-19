<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Recepcion;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as DetectorConcurrencia;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Transacción de toda escritura que mueve dinero de Abastecimiento (pagos y liquidación de la cuenta por convenio).
 *
 * Invariante (ver CLAUDE.md):
 * - **READ COMMITTED.** MySQL corre REPEATABLE READ: una lectura sin bloqueo dentro de la transacción ve la foto
 *   tomada en su primera lectura, así que una suma de pagos no vería lo que otra transacción confirmó mientras esta
 *   esperaba un bloqueo. Con READ COMMITTED cada sentencia lee lo último confirmado.
 *   `SET TRANSACTION ISOLATION LEVEL` solo vale para la SIGUIENTE transacción, por eso se repite antes de cada
 *   intento (el reintento de `DB::transaction($fn, n)` abriría el segundo intento con el nivel por omisión).
 * - **Un solo orden de bloqueo:** productor → convenio → recepciones (por id) → pagos (por id) → liquidación.
 *   Todo escritor de dinero entra por `bloquearConvenio` (directo o vía `bloquearPago` / `bloquearLiquidacion`),
 *   así que dos escritores del mismo convenio se turnan en la fila del convenio y ninguno lee un saldo viejo.
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
            if (! $anidada && self::usaAislamiento(DB::getDriverName())) {
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

    /** ¿El driver admite `SET TRANSACTION ISOLATION LEVEL`? MySQL y MariaDB (producción puede usar `DB_CONNECTION=mariadb`). */
    public static function usaAislamiento(string $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
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
     * Productor y luego convenio: lee SOLO el `productor_id` del convenio sin bloquear, bloquea al productor
     * (también si está borrado) y después bloquea y recarga el convenio. Si entre las dos lecturas otra transacción
     * cambió el productor del convenio, el bloqueo tomado ya no es el correcto: 422 para que se reintente.
     * 404 si el convenio no es de la empresa (o está borrado).
     */
    public static function bloquearConvenio(int $empresaId, int $convenioId): Convenio
    {
        $productorId = Convenio::deEmpresa($empresaId)->whereKey($convenioId)->value('productor_id');
        if ($productorId === null) {
            throw (new ModelNotFoundException)->setModel(Convenio::class, [$convenioId]);
        }
        self::bloquearProductor($empresaId, (int) $productorId, true);

        $convenio = Convenio::deEmpresa($empresaId)->lockForUpdate()->findOrFail($convenioId);
        if ((int) $convenio->productor_id !== (int) $productorId) {
            throw ValidationException::withMessages([
                'convenio' => 'El convenio cambió mientras se guardaba; vuelve a intentarlo.',
            ]);
        }

        return $convenio;
    }

    /**
     * Recepciones (no borradas) del convenio YA bloqueado entre `$ids`, bloqueadas en una sola sentencia por id
     * ascendente. Los ids que no son del convenio (o están borrados) simplemente no vienen.
     *
     * @param  array<int, int>  $ids
     * @return EloquentCollection<int, Recepcion>
     */
    public static function bloquearRecepciones(Convenio $convenio, array $ids): EloquentCollection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return new EloquentCollection;
        }

        return Recepcion::where('convenio_id', $convenio->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Para cancelar un pago: lee SOLO su `convenio_id` sin bloquear, bloquea productor y convenio
     * (`bloquearConvenio`) y al final el pago. El `convenio_id` de un pago no cambia (no hay ruta que lo edite).
     * 404 si el pago no es de la empresa.
     *
     * @return array{0: Convenio, 1: Abono}
     */
    public static function bloquearPago(int $empresaId, int $pagoId): array
    {
        $convenioId = Abono::deEmpresa($empresaId)->whereKey($pagoId)->value('convenio_id');
        if ($convenioId === null) {
            throw (new ModelNotFoundException)->setModel(Abono::class, [$pagoId]);
        }
        $convenio = self::bloquearConvenio($empresaId, (int) $convenioId);
        $pago = Abono::deEmpresa($empresaId)->lockForUpdate()->findOrFail($pagoId);

        return [$convenio, $pago];
    }

    /**
     * Para las acciones sobre una liquidación: productor y convenio (`bloquearConvenio`), sus recepciones ligadas
     * (por id, también las borradas), sus pagos `liquidacion` (por id) y al final la liquidación. 404 si no es de
     * la empresa.
     *
     * @return array{0: Convenio, 1: Liquidacion}
     */
    public static function bloquearLiquidacion(int $empresaId, int $liquidacionId): array
    {
        $convenioId = Liquidacion::deEmpresa($empresaId)->whereKey($liquidacionId)->value('convenio_id');
        if ($convenioId === null) {
            throw (new ModelNotFoundException)->setModel(Liquidacion::class, [$liquidacionId]);
        }
        $convenio = self::bloquearConvenio($empresaId, (int) $convenioId);

        Recepcion::withTrashed()->where('liquidacion_id', $liquidacionId)->orderBy('id')->lockForUpdate()->get();
        Abono::where('liquidacion_id', $liquidacionId)->where('tipo', 'liquidacion')->orderBy('id')->lockForUpdate()->get();
        $liquidacion = Liquidacion::deEmpresa($empresaId)->lockForUpdate()->findOrFail($liquidacionId);

        return [$convenio, $liquidacion];
    }

    private static function esConcurrencia(Throwable $e): bool
    {
        $detector = app()->bound(DetectorConcurrencia::class) ? app(DetectorConcurrencia::class) : new ConcurrencyErrorDetector;

        return $detector->causedByConcurrencyError($e);
    }
}
