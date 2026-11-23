<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use Illuminate\Validation\ValidationException;

/**
 * Domingo opcional por semana: la regla común de las cuatro hojas (una celda
 * del domingo con valor en una semana sin domingo es un 422) y lo que impide
 * apagarlo (datos en el día 6).
 */
class DomingoPrograma
{
    public const DIA = CalculadoraBalance::DIAS - 1;

    public const MENSAJE = 'Esta semana no se trabaja el domingo.';

    /**
     * Rechaza con 422 la primera celda del domingo con valor distinto de cero
     * si la semana no trabaja el domingo. Cero o vacío siempre se aceptan.
     *
     * Solo si hay alguna celda del domingo con valor se relee el interruptor
     * con la fila del programa bloqueada: así un apagado simultáneo (que
     * bloquea la misma fila antes de contar) espera a esta captura o esta
     * captura ve el interruptor ya apagado. Debe llamarse dentro de la
     * transacción de la hoja, después de sus propios bloqueos.
     *
     * @param  array<string, mixed>  $valores  clave de error de la celda => valor, solo de celdas del día 6.
     */
    public static function exigirCeldas(ProgramaSemanal $programa, array $valores): void
    {
        $conValor = array_filter($valores, fn ($valor) => (float) ($valor ?? 0) > 0);
        if ($conValor === []) {
            return;
        }

        $trabaja = (bool) (ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->value('trabaja_domingo') ?? true);
        if (! $trabaja) {
            throw ValidationException::withMessages([array_key_first($conValor) => self::MENSAJE]);
        }
    }

    /**
     * Las celdas del domingo de los renglones de Necesidades o Sobrantes, con
     * la clave de error de cada una (`nuevas.N.cajas.6`, `cambios.N.cajas.6`).
     * Un renglón nuevo trae las siete cajas en orden; un cambio, un mapa día → cajas.
     *
     * @param  list<array<string, mixed>>  $nuevas
     * @param  list<array<string, mixed>>  $cambios
     * @return array<string, mixed>
     */
    public static function celdasDeRenglones(array $nuevas, array $cambios): array
    {
        $celdas = [];
        foreach ($nuevas as $i => $fila) {
            $cajas = array_values((array) ($fila['cajas'] ?? []));
            if (array_key_exists(self::DIA, $cajas)) {
                $celdas["nuevas.$i.cajas.".self::DIA] = $cajas[self::DIA];
            }
        }
        foreach ($cambios as $i => $fila) {
            $cajas = (array) ($fila['cajas'] ?? []);
            if (array_key_exists(self::DIA, $cajas)) {
                $celdas["cambios.$i.cajas.".self::DIA] = $cajas[self::DIA];
            }
        }

        return $celdas;
    }

    /**
     * Mensaje del 422 al apagar el domingo con datos, o null si el día 6 está
     * vacío. Solo nombra lo que hay, con singular y plural.
     */
    public static function datosDelDomingo(ProgramaSemanal $programa): ?string
    {
        $conteos = [
            ['corte', 'cortes', ProgramaCorte::where('programa_id', $programa->id)->where('dia', self::DIA)->where('hectareas', '>', 0)->count()],
            ['necesidad', 'necesidades', ProgramaNecesidad::where('programa_id', $programa->id)->where('cajas_'.self::DIA, '>', 0)->count()],
            ['sobrante', 'sobrantes', ProgramaSobrante::where('programa_id', $programa->id)->where('cajas_'.self::DIA, '>', 0)->count()],
            ['embarque', 'embarques', ProgramaEmbarque::where('programa_id', $programa->id)->where('dia', self::DIA)->where('cargas', '>', 0)->count()],
        ];

        $partes = [];
        foreach ($conteos as [$singular, $plural, $cantidad]) {
            if ($cantidad > 0) {
                $partes[] = $cantidad.' '.($cantidad === 1 ? $singular : $plural);
            }
        }
        if ($partes === []) {
            return null;
        }

        $ultima = array_pop($partes);
        $lista = $partes === [] ? $ultima : implode(', ', $partes).' y '.$ultima;

        return "El domingo ya tiene datos: {$lista}. Vacíalos antes de apagarlo.";
    }
}
