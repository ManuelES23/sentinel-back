<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Recepcion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de selección de recepciones de una liquidación (spec 6.5, plan 2 rulings 11 y 12).
 * Una recepción queda ligada a la liquidación (`abs_recepciones.liquidacion_id`) desde que
 * es borrador; cancelar la liquidación o borrar el borrador la libera.
 */
class ReglasLiquidacion
{
    public const MENSAJE_NO_BORRADOR = 'Solo se puede cambiar o borrar una liquidación en borrador.';

    /**
     * Recepciones del convenio dentro del periodo que se pueden liquidar: las que no están en
     * ninguna liquidación, más las de la liquidación indicada (al editar su borrador).
     *
     * @return Collection<int, Recepcion>
     */
    public static function recepcionesDisponibles(Convenio $convenio, string $desde, string $hasta, ?int $liquidacionId = null): Collection
    {
        return Recepcion::where('convenio_id', $convenio->id)
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)
            ->where(function ($q) use ($liquidacionId) {
                $q->whereNull('liquidacion_id');
                if ($liquidacionId !== null) {
                    $q->orWhere('liquidacion_id', $liquidacionId);
                }
            })
            ->orderBy('fecha')->orderBy('id')
            ->get();
    }

    /**
     * Bloquea (lockForUpdate, por id) y devuelve las recepciones elegidas. Responde 422 en
     * `recepcion_ids` si hay repetidas, de otro convenio o inexistentes, fuera del periodo,
     * ya liquidadas (en otra liquidación) o, en un convenio por kilo, sin kilos.
     * Debe llamarse dentro de la transacción de la escritura.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Recepcion> en orden de fecha e id
     */
    public static function validarSeleccion(Convenio $convenio, array $ids, string $desde, string $hasta, ?int $liquidacionId = null): Collection
    {
        $ids = array_map('intval', $ids);
        if ($ids === []) {
            self::fallar('Elige al menos una recepción.');
        }
        if (count(array_unique($ids)) !== count($ids)) {
            self::fallar('Hay recepciones repetidas en la selección.');
        }

        $encontradas = Recepcion::where('convenio_id', $convenio->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($encontradas->count() !== count($ids)) {
            self::fallar('Alguna recepción no existe o no es de este convenio.');
        }

        $fuera = $encontradas->filter(fn (Recepcion $r) => $r->fecha->format('Y-m-d') < $desde || $r->fecha->format('Y-m-d') > $hasta);
        if ($fuera->isNotEmpty()) {
            self::fallar('Las recepciones '.self::folios($fuera).' están fuera del periodo.');
        }

        $liquidadas = $encontradas->filter(fn (Recepcion $r) => $r->liquidacion_id !== null && (int) $r->liquidacion_id !== (int) $liquidacionId);
        if ($liquidadas->isNotEmpty()) {
            self::fallar('Las recepciones '.self::folios($liquidadas).' ya están en otra liquidación.');
        }

        if ($convenio->unidad === 'kg') {
            $sinKilos = $encontradas->filter(fn (Recepcion $r) => (float) $r->kilos <= 0);
            if ($sinKilos->isNotEmpty()) {
                self::fallar('Las recepciones '.self::folios($sinKilos).' no tienen kilos y el convenio se paga por kilo.');
            }
        }

        return $encontradas->sortBy([['fecha', 'asc'], ['id', 'asc']])->values();
    }

    /** Quita la liga de las recepciones de la liquidación (la reutiliza la cancelación). */
    public static function liberarRecepciones(Liquidacion $liquidacion): void
    {
        Recepcion::where('liquidacion_id', $liquidacion->id)->update(['liquidacion_id' => null]);
    }

    /** Solo un borrador se edita o se borra. */
    public static function exigirBorrador(Liquidacion $liquidacion): void
    {
        if ($liquidacion->status !== 'borrador') {
            throw ValidationException::withMessages(['liquidacion' => self::MENSAJE_NO_BORRADOR]);
        }
    }

    private static function folios(Collection $recepciones): string
    {
        return $recepciones->map(fn (Recepcion $r) => $r->folio ?? '#'.$r->id)->implode(', ');
    }

    private static function fallar(string $mensaje): never
    {
        throw ValidationException::withMessages(['recepcion_ids' => $mensaje]);
    }
}
