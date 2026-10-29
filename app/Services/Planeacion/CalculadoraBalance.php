<?php

namespace App\Services\Planeacion;

/**
 * Calculador del balance semanal por grupo de variedades.
 *
 * Es una clase pura: solo recibe arreglos y no toca la base de datos.
 * La semana va de lunes (día 0) a sábado (día 5).
 */
class CalculadoraBalance
{
    public const DIAS = 6;

    /**
     * Calcula producción, necesidad y acumulado por grupo y por día.
     *
     * Las claves de grupo se tratan como opacas (enteros o texto como "v12")
     * y se conservan tal cual en la salida.
     *
     * Todo grupo de $grupos aparece en la salida aunque no tenga renglones.
     * Un grupo referenciado por algún renglón de producción o necesidad pero
     * ausente de $grupos TAMBIÉN se incluye (después de los de $grupos, en
     * orden de aparición) para no perder cajas en silencio; no se lanza error.
     *
     * @param array<int|string, array{cultivo_id:int}>                                        $grupos
     * @param array<int, array{grupo:int|string, temporada_id:?int, dia:int, cajas:int}>      $produccion
     * @param array<int, array{grupo:int|string, dia:int, cajas:int}>                         $necesidad
     * @param array<int|string, int>                                                          $inicial  Faltante = 0.
     * @return array<int|string, array{
     *   produccion:int[], necesidad:int[], del_dia:int[], acumulado:int[],
     *   inicial:int, final:int, estado:string,
     *   primer_dia_falta:?int, peor_dia:?int, peor_valor:?int,
     *   produccion_por_temporada:array<int|string, int[]>
     * }>
     */
    public static function calcular(array $grupos, array $produccion, array $necesidad, array $inicial): array
    {
        $claves = array_keys($grupos);
        $conocidas = array_flip($claves);

        foreach ([$produccion, $necesidad] as $renglones) {
            foreach ($renglones as $r) {
                if (! array_key_exists($r['grupo'], $conocidas)) {
                    $conocidas[$r['grupo']] = true;
                    $claves[] = $r['grupo'];
                }
            }
        }

        $ceros = array_fill(0, self::DIAS, 0);
        $prod = [];
        $nec = [];
        $porTemporada = [];
        foreach ($claves as $clave) {
            $prod[$clave] = $ceros;
            $nec[$clave] = $ceros;
            $porTemporada[$clave] = [];
        }

        foreach ($produccion as $r) {
            $g = $r['grupo'];
            $dia = (int) $r['dia'];
            $cajas = (int) $r['cajas'];
            $prod[$g][$dia] += $cajas;

            $temporada = $r['temporada_id'] ?? null;
            $llave = $temporada === null ? 'sin_temporada' : $temporada;
            $porTemporada[$g][$llave] ??= $ceros;
            $porTemporada[$g][$llave][$dia] += $cajas;
        }

        foreach ($necesidad as $r) {
            $nec[$r['grupo']][(int) $r['dia']] += (int) $r['cajas'];
        }

        $salida = [];
        foreach ($claves as $clave) {
            $inicio = (int) ($inicial[$clave] ?? 0);

            $delDia = [];
            $acumulado = [];
            $corrido = $inicio;
            for ($d = 0; $d < self::DIAS; $d++) {
                $delDia[$d] = $prod[$clave][$d] - $nec[$clave][$d];
                $corrido += $delDia[$d];
                $acumulado[$d] = $corrido;
            }

            $primerDiaFalta = null;
            $peorDia = null;
            $peorValor = null;
            foreach ($acumulado as $d => $valor) {
                if ($valor < 0 && $primerDiaFalta === null) {
                    $primerDiaFalta = $d;
                }
                // Estricto: en empate gana el día más temprano.
                if ($valor < 0 && ($peorValor === null || $valor < $peorValor)) {
                    $peorDia = $d;
                    $peorValor = $valor;
                }
            }

            if ($primerDiaFalta !== null) {
                $estado = 'falta';
            } elseif (min($delDia) < 0) {
                $estado = 'cubre_inventario';
            } else {
                $estado = 'cubierto';
            }

            $salida[$clave] = [
                'produccion' => $prod[$clave],
                'necesidad' => $nec[$clave],
                'del_dia' => $delDia,
                'acumulado' => $acumulado,
                'inicial' => $inicio,
                'final' => $acumulado[self::DIAS - 1],
                'estado' => $estado,
                'primer_dia_falta' => $primerDiaFalta,
                'peor_dia' => $peorDia,
                'peor_valor' => $peorValor,
                'produccion_por_temporada' => $porTemporada[$clave],
            ];
        }

        return $salida;
    }
}
