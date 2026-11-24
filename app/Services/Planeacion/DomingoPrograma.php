<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use Illuminate\Validation\ValidationException;

/**
 * Domingo opcional por proceso (`corte`, `empaque`, `embarques`; ver
 * ProgramaSemanal::PROCESOS): la regla común de las hojas (una celda del
 * domingo con valor cuando SU proceso no trabaja el domingo es un 422) y lo
 * que impide apagar el domingo de un proceso (sus datos en el día 6).
 *
 * Qué hoja sigue a qué proceso: corte → `corte`; necesidades y sobrantes →
 * `empaque`; embarques → `embarques`.
 */
class DomingoPrograma
{
    public const DIA = CalculadoraBalance::DIAS - 1;

    public const MENSAJE = 'Esta semana no se trabaja el domingo.';

    /** Nombre de cada proceso en los mensajes. */
    public const NOMBRES = [
        'corte' => 'Corte',
        'empaque' => 'Empaque',
        'embarques' => 'Embarques',
    ];

    /**
     * Rechaza con 422 la primera celda del domingo con valor distinto de cero
     * si el proceso no trabaja el domingo. Cero o vacío siempre se aceptan.
     *
     * Solo si hay alguna celda del domingo con valor se relee la bandera del
     * proceso con la fila del programa bloqueada: así un apagado simultáneo
     * (que bloquea la misma fila antes de contar) espera a esta captura o esta
     * captura ve la bandera ya apagada. Debe llamarse dentro de la transacción
     * de la hoja, después de sus propios bloqueos.
     *
     * @param  array<string, mixed>  $celdas  clave de error de la celda => valor, solo de celdas del día 6.
     */
    public static function exigirCeldas(int $programaId, array $celdas, string $proceso): void
    {
        $columna = ProgramaSemanal::columnaDomingo($proceso);

        $conValor = array_filter($celdas, fn ($valor) => (float) ($valor ?? 0) > 0);
        if ($conValor === []) {
            return;
        }

        $trabaja = (bool) (ProgramaSemanal::whereKey($programaId)->lockForUpdate()->value($columna) ?? true);
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
     * Lo que hay en el domingo de ESE proceso, con singular y plural
     * (`['3 necesidades', '1 sobrante']`), o `[]` si su día 6 está vacío.
     * Corte: cortes con hectáreas; Empaque: necesidades y sobrantes con cajas;
     * Embarques: embarques con cargas.
     *
     * @return list<string>
     */
    public static function datosDelDomingo(int $programaId, string $proceso): array
    {
        ProgramaSemanal::columnaDomingo($proceso);

        $conteos = match ($proceso) {
            'corte' => [
                ['corte', 'cortes', ProgramaCorte::where('programa_id', $programaId)->where('dia', self::DIA)->where('hectareas', '>', 0)->count()],
            ],
            'empaque' => [
                ['necesidad', 'necesidades', ProgramaNecesidad::where('programa_id', $programaId)->where('cajas_'.self::DIA, '>', 0)->count()],
                ['sobrante', 'sobrantes', ProgramaSobrante::where('programa_id', $programaId)->where('cajas_'.self::DIA, '>', 0)->count()],
            ],
            'embarques' => [
                ['embarque', 'embarques', ProgramaEmbarque::where('programa_id', $programaId)->where('dia', self::DIA)->where('cargas', '>', 0)->count()],
            ],
        };

        $partes = [];
        foreach ($conteos as [$singular, $plural, $cantidad]) {
            if ($cantidad > 0) {
                $partes[] = $cantidad.' '.($cantidad === 1 ? $singular : $plural);
            }
        }

        return $partes;
    }

    /**
     * Mensaje del 422 al apagar el domingo de un proceso con datos:
     * «El domingo de Empaque ya tiene datos: 3 necesidades y 1 sobrante. Vacíalos antes de apagarlo.»
     *
     * @param  list<string>  $partes  lo que devolvió datosDelDomingo (no vacío).
     */
    public static function mensajeDeApagado(string $proceso, array $partes): string
    {
        $ultima = array_pop($partes);
        $lista = $partes === [] ? $ultima : implode(', ', $partes).' y '.$ultima;
        $nombre = self::NOMBRES[$proceso] ?? $proceso;

        return "El domingo de {$nombre} ya tiene datos: {$lista}. Vacíalos antes de apagarlo.";
    }
}
