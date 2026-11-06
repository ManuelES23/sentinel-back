<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aplicación de abonos y anticipos a liquidaciones aprobadas (spec 6.7, plan 2 rulings 7 y 8).
 *
 * Las filas de `abs_abono_aplicaciones` son la única fuente de lo aplicado: cancelar un abono las
 * copia a `abs_abonos.aplicaciones_canceladas` y las borra.
 *
 * Todos los métodos corren DENTRO de la transacción de quien llama (`TransaccionDinero::ejecutar`, READ
 * COMMITTED en MySQL; no abren la suya) y bloquean con `lockForUpdate`. Orden de bloqueo único para evitar
 * interbloqueos: primero el productor, luego los abonos (por id) y luego las liquidaciones (por id). Con el
 * productor bloqueado, las sumas sin bloqueo de `abs_abono_aplicaciones` leen lo último confirmado: ningún
 * otro escritor de dinero de ese productor puede estar a medias. `aplicar` valida todo ANTES de escribir; si algo falla lanza
 * ValidationException (422) sin haber escrito nada. Los montos se comparan en centavos enteros.
 */
class AplicadorAbonos
{
    /**
     * Aplica el abono a las liquidaciones indicadas; lo que no se aplique queda como anticipo.
     * `aplicaciones` vacías = anticipo completo.
     *
     * @param  array<int, array{liquidacion_id: int|string, monto: int|float|string}>  $aplicaciones
     *
     * @throws ValidationException `aplicaciones` (formato, repetidas, suma mayor al abono o al saldo de una liquidación),
     *                             `liquidacion_id` (inexistente, de otro productor o convenio, no aprobada), `moneda`, `abono`
     */
    public static function aplicar(Abono $abono, array $aplicaciones): void
    {
        $abono = self::bloquearAbono($abono->id);
        if ($abono->status !== 'aplicado') {
            self::fallar('abono', 'El abono está cancelado y no se puede aplicar.');
        }

        // Formato: liquidación entera, monto > 0, sin repetir.
        $pedidos = [];
        foreach (array_values($aplicaciones) as $i => $a) {
            $n = $i + 1;
            $id = is_array($a) ? ($a['liquidacion_id'] ?? null) : null;
            $monto = is_array($a) ? ($a['monto'] ?? null) : null;
            if (! is_numeric($id) || (int) $id != $id || (int) $id <= 0) {
                self::fallar('aplicaciones', "La aplicación {$n} necesita una liquidación.");
            }
            if (! is_numeric($monto) || self::centavos($monto) <= 0) {
                self::fallar('aplicaciones', "La aplicación {$n} necesita un monto mayor a cero.");
            }
            if (isset($pedidos[(int) $id])) {
                self::fallar('aplicaciones', 'Una liquidación no puede repetirse en las aplicaciones.');
            }
            $pedidos[(int) $id] = self::centavos($monto);
        }
        if ($pedidos === []) {
            return;
        }

        // Liquidaciones bloqueadas en orden de id.
        $liquidaciones = Liquidacion::where('enterprise_id', $abono->enterprise_id)
            ->whereIn('id', array_keys($pedidos))
            ->orderBy('id')->lockForUpdate()
            ->get()->keyBy('id');

        foreach ($pedidos as $id => $centavos) {
            /** @var Liquidacion|null $liq */
            $liq = $liquidaciones->get($id);
            if ($liq === null) {
                self::fallar('liquidacion_id', 'La liquidación elegida no existe.');
            }
            if ($liq->productor_id !== $abono->productor_id) {
                self::fallar('liquidacion_id', "La liquidación {$liq->folio} es de otro productor.");
            }
            if ($liq->moneda !== $abono->moneda) {
                self::fallar('moneda', "La liquidación {$liq->folio} es en {$liq->moneda} y el abono en {$abono->moneda}.");
            }
            if ($liq->status !== 'aprobada') {
                self::fallar('liquidacion_id', "La liquidación {$liq->folio} no está aprobada o ya está pagada.");
            }
            if ($abono->convenio_id !== null && $abono->convenio_id !== $liq->convenio_id) {
                self::fallar('liquidacion_id', "El abono está ligado a otro convenio y no aplica a la liquidación {$liq->folio}.");
            }
        }

        // Lo que ya se aplicó del abono y el saldo de cada liquidación (lecturas con las filas bloqueadas).
        $disponibleAbono = self::centavos($abono->monto) - self::aplicadoDelAbono($abono->id);
        if (array_sum($pedidos) > $disponibleAbono) {
            self::fallar('aplicaciones', 'Las aplicaciones suman más que lo disponible del abono ('.self::dinero($disponibleAbono).' '.$abono->moneda.').');
        }

        $aplicado = self::aplicadoPorLiquidacion(array_keys($pedidos));
        foreach ($pedidos as $id => $centavos) {
            $liq = $liquidaciones->get($id);
            $saldo = self::centavos($liq->monto_final) - ($aplicado[$id] ?? 0);
            if ($centavos > $saldo) {
                self::fallar('aplicaciones', "La aplicación a {$liq->folio} es mayor que su saldo (".self::dinero(max($saldo, 0)).' '.$liq->moneda.').');
            }
        }

        foreach ($pedidos as $id => $centavos) {
            AbonoAplicacion::create(['abono_id' => $abono->id, 'liquidacion_id' => $id, 'monto' => $centavos / 100]);
        }
        foreach ($liquidaciones as $liq) {
            self::refrescarPagada($liq);
        }
    }

    /**
     * Aplica a una liquidación recién aprobada los anticipos del mismo productor y moneda, del más
     * antiguo al más nuevo (fecha, id). Uno con `convenio_id` solo aplica a liquidaciones de ese
     * convenio. No hace nada si la liquidación no está `aprobada`.
     */
    public static function aplicarAnticiposA(Liquidacion $liquidacion): void
    {
        $base = Liquidacion::find($liquidacion->id);
        if ($base === null) {
            return;
        }

        // Productor, abonos (por id) y luego la liquidación: el mismo orden que `aplicar` y `cancelar`.
        TransaccionDinero::bloquearProductor((int) $base->enterprise_id, (int) $base->productor_id, true);
        $abonos = Abono::where('enterprise_id', $base->enterprise_id)
            ->where('productor_id', $base->productor_id)
            ->where('moneda', $base->moneda)
            ->where('status', 'aplicado')
            ->where(fn ($q) => $q->whereNull('convenio_id')->orWhere('convenio_id', $base->convenio_id))
            ->orderBy('id')->lockForUpdate()
            ->get();

        $liq = Liquidacion::whereKey($base->id)->lockForUpdate()->first();
        if ($liq === null || $liq->status !== 'aprobada') {
            return;
        }

        $saldo = self::centavos($liq->monto_final) - (self::aplicadoPorLiquidacion([$liq->id])[$liq->id] ?? 0);
        if ($saldo > 0 && $abonos->isNotEmpty()) {
            $yaAplicado = DB::table('abs_abono_aplicaciones')
                ->whereIn('abono_id', $abonos->pluck('id')->all())
                ->groupBy('abono_id')->selectRaw('abono_id, SUM(monto) as total')
                ->pluck('total', 'abono_id');

            foreach ($abonos->sortBy([['fecha', 'asc'], ['id', 'asc']]) as $abono) {
                if ($saldo <= 0) {
                    break;
                }
                $resto = self::centavos($abono->monto) - self::centavos($yaAplicado->get($abono->id, 0));
                $monto = min($resto, $saldo);
                if ($monto <= 0) {
                    continue;
                }
                AbonoAplicacion::create(['abono_id' => $abono->id, 'liquidacion_id' => $liq->id, 'monto' => $monto / 100]);
                $saldo -= $monto;
            }
        }

        self::refrescarPagada($liq);
        self::sincronizar($liquidacion, $liq);
    }

    /**
     * Cancela el abono: copia sus aplicaciones a `aplicaciones_canceladas`, las borra y regresa a
     * `aprobada` las liquidaciones que había dejado `pagada` (Ruling 8, Review Focus 5).
     *
     * @throws ValidationException `motivo` vacío, `abono` ya cancelado
     */
    public static function cancelar(Abono $abono, User $usuario, string $motivo): void
    {
        $abono = self::bloquearAbono($abono->id);
        if ($abono->status === 'cancelado') {
            self::fallar('abono', 'El abono ya está cancelado.');
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            self::fallar('motivo', 'Escribe el motivo de la cancelación.');
        }

        $filas = AbonoAplicacion::where('abono_id', $abono->id)->orderBy('id')->get();
        $liquidacionIds = $filas->pluck('liquidacion_id')->unique()->sort()->values()->all();
        $liquidaciones = $liquidacionIds === []
            ? collect()
            : Liquidacion::whereIn('id', $liquidacionIds)->orderBy('id')->lockForUpdate()->get();

        $abono->aplicaciones_canceladas = $filas
            ->map(fn (AbonoAplicacion $f) => ['liquidacion_id' => $f->liquidacion_id, 'monto' => round((float) $f->monto, 2)])
            ->values()->all();
        $abono->status = 'cancelado';
        $abono->cancelado_motivo = $motivo;
        $abono->cancelado_by = $usuario->id;
        $abono->cancelado_at = now();
        $abono->save();

        AbonoAplicacion::where('abono_id', $abono->id)->delete();

        foreach ($liquidaciones as $liq) {
            self::refrescarPagada($liq);
        }
    }

    /**
     * Deja la liquidación `pagada` si lo aplicado cubre su monto final y `aprobada` si no. No toca
     * las que están en otro estado. Una liquidación en cero no se considera pagada.
     */
    public static function refrescarPagada(Liquidacion $liquidacion): void
    {
        $liq = Liquidacion::whereKey($liquidacion->id)->lockForUpdate()->first();
        if ($liq === null || ! in_array($liq->status, ['aprobada', 'pagada'], true)) {
            return;
        }

        $monto = self::centavos($liq->monto_final);
        $cubierta = $monto > 0 && (self::aplicadoPorLiquidacion([$liq->id])[$liq->id] ?? 0) >= $monto;
        $nuevo = $cubierta ? 'pagada' : 'aprobada';
        if ($liq->status !== $nuevo) {
            $liq->status = $nuevo;
            $liq->save();
        }

        self::sincronizar($liquidacion, $liq);
    }

    /** Productor del abono primero y luego el abono (orden único de bloqueo). */
    private static function bloquearAbono(int $id): Abono
    {
        $base = Abono::whereKey($id)->firstOrFail(['id', 'enterprise_id']);

        return TransaccionDinero::bloquearAbono((int) $base->enterprise_id, $id);
    }

    /** Pasa al modelo que recibió quien llama el estado ya guardado. */
    private static function sincronizar(Liquidacion $destino, Liquidacion $origen): void
    {
        if ($destino !== $origen) {
            $destino->forceFill(['status' => $origen->status])->syncOriginalAttribute('status');
        }
    }

    /** @return int centavos aplicados del abono */
    private static function aplicadoDelAbono(int $abonoId): int
    {
        return self::centavos(DB::table('abs_abono_aplicaciones')->where('abono_id', $abonoId)->sum('monto'));
    }

    /**
     * @param  array<int, int>  $liquidacionIds
     * @return array<int, int> liquidacion_id => centavos aplicados
     */
    private static function aplicadoPorLiquidacion(array $liquidacionIds): array
    {
        return DB::table('abs_abono_aplicaciones')
            ->whereIn('liquidacion_id', $liquidacionIds)
            ->groupBy('liquidacion_id')->selectRaw('liquidacion_id, SUM(monto) as total')
            ->get()
            ->mapWithKeys(fn ($f) => [(int) $f->liquidacion_id => self::centavos($f->total)])
            ->all();
    }

    private static function centavos(mixed $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }

    private static function dinero(int $centavos): string
    {
        return number_format($centavos / 100, 2);
    }

    private static function fallar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
