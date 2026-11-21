<?php

namespace App\Services\Abastecimiento;

use Illuminate\Validation\ValidationException;

/**
 * Reglas de un convenio: precios (modalidad, vigencias repetidas y tipo
 * de carga principal) y volumen pactado. Trabaja sobre las filas ya validadas en tipo por el
 * controlador; aquí solo viven las reglas de negocio.
 */
class ReglasConvenio
{
    /**
     * Lanza ValidationException con la clave `precios.N.campo` del primer renglón culpable.
     *
     * @param  array<int, array<string, mixed>>  $precios
     */
    public static function validarPrecios(array $precios, string $modalidad, bool $usaCalibres = false): void
    {
        $errores = [];
        $vistos = [];
        $principales = []; // tipo_carga_id => primer índice marcado

        foreach (array_values($precios) as $i => $precio) {
            if ($modalidad === 'consignacion') {
                $pct = $precio['porcentaje_productor'] ?? null;
                if ($pct === null || $pct === '') {
                    $errores["precios.{$i}.porcentaje_productor"] = 'Indica el porcentaje que corresponde al productor.';
                } elseif (! is_numeric($pct) || $pct < 0 || $pct > 100) {
                    $errores["precios.{$i}.porcentaje_productor"] = 'El porcentaje del productor debe estar entre 0 y 100.';
                }
            }

            $calibre = $precio['calibre_id'] ?? null;
            $calibre = ($calibre === null || $calibre === '') ? null : $calibre;
            // En un convenio por calibre, un precio sin calibre es el GENERAL (vale para todos los
            // calibres que no tengan un precio especial).
            if (! $usaCalibres && $calibre !== null) {
                $errores["precios.{$i}.calibre_id"] = 'Este convenio no es por calibre.';
            }

            $clave = ($precio['tipo_carga_id'] ?? '').'|'.($calibre ?? '').'|'.($precio['vigencia_inicio'] ?? '');
            if (isset($vistos[$clave])) {
                $errores["precios.{$i}.vigencia_inicio"] = 'Ya hay un precio de este tipo de carga con esa fecha de vigencia.';
            }
            $vistos[$clave] = true;

            if (self::marcado($precio)) {
                $principales[$precio['tipo_carga_id']] ??= $i;
            }
        }

        if (count($principales) > 1) {
            $indices = array_values($principales);
            sort($indices);
            foreach (array_slice($indices, 1) as $i) {
                $errores["precios.{$i}.es_principal"] = 'Solo un tipo de carga puede ser el principal.';
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Filas listas para guardar: el porcentaje del productor solo existe en
     * consignación y `es_principal` identifica un TIPO de carga (todas sus
     * vigencias comparten el valor; el primer tipo de la lista si ninguno se marcó).
     * `calibre_id` es null en convenios sin calibres.
     *
     * @param  array<int, array<string, mixed>>  $precios
     * @return array<int, array<string, mixed>>
     */
    public static function normalizarPrecios(array $precios, string $modalidad): array
    {
        $precios = array_values($precios);
        $principal = null;
        foreach ($precios as $precio) {
            if (self::marcado($precio)) {
                $principal = (int) $precio['tipo_carga_id'];
                break;
            }
        }
        $principal ??= isset($precios[0]) ? (int) $precios[0]['tipo_carga_id'] : null;

        return array_map(fn (array $p) => [
            'tipo_carga_id' => (int) $p['tipo_carga_id'],
            'precio' => $p['precio'],
            'porcentaje_productor' => $modalidad === 'consignacion' ? ($p['porcentaje_productor'] ?? null) : null,
            'calibre_id' => isset($p['calibre_id']) && $p['calibre_id'] !== '' ? (int) $p['calibre_id'] : null,
            'vigencia_inicio' => $p['vigencia_inicio'],
            'es_principal' => (int) $p['tipo_carga_id'] === $principal,
            'notas' => $p['notas'] ?? null,
        ], $precios);
    }

    /**
     * Calibres del convenio: al menos uno, todos de los `$permitidos` (activos y de la
     * variedad), sin precios de calibres no elegidos y con precio del tipo de carga
     * principal en CADA calibre: el general (sin calibre) o uno especial del calibre.
     * `$precios` son las filas ya normalizadas.
     *
     * @param  array<int, array<string, mixed>>  $calibres  [['calibre_id', 'volumen_total'], ...]
     * @param  array<int, int|string>  $permitidos
     * @param  array<int, array<string, mixed>>  $precios
     */
    public static function validarCalibres(array $calibres, array $permitidos, array $precios): void
    {
        $calibres = array_values($calibres);
        if ($calibres === []) {
            throw ValidationException::withMessages(['calibres' => 'Elige al menos un calibre.']);
        }

        $errores = [];
        $permitidos = array_map('intval', $permitidos);
        $elegidos = array_map(fn ($c) => (int) ($c['calibre_id'] ?? 0), $calibres);

        // Calibres con precio del tipo principal; un general del tipo principal los cubre a todos.
        $conPrincipal = [];
        $generalPrincipal = false;
        foreach ($precios as $p) {
            if (! self::marcado($p)) {
                continue;
            }
            if (($p['calibre_id'] ?? null) === null) {
                $generalPrincipal = true;
            } else {
                $conPrincipal[(int) $p['calibre_id']] = true;
            }
        }

        $vistos = [];
        foreach ($elegidos as $i => $id) {
            if (isset($vistos[$id])) {
                $errores["calibres.{$i}.calibre_id"] = 'El calibre está repetido.';
                continue;
            }
            $vistos[$id] = true;

            if (! in_array($id, $permitidos, true)) {
                $errores["calibres.{$i}.calibre_id"] = 'El calibre no es de la variedad del convenio o está inactivo.';
            } elseif (! $generalPrincipal && ! isset($conPrincipal[$id])) {
                $errores["calibres.{$i}.calibre_id"] = 'Falta el precio del tipo de carga principal: captura el precio general o uno especial para este calibre.';
            }
        }

        foreach (array_values($precios) as $i => $p) {
            $id = $p['calibre_id'] ?? null;
            if ($id !== null && ! in_array((int) $id, $elegidos, true)) {
                $errores["precios.{$i}.calibre_id"] = 'El calibre no está en el convenio.';
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Reglas del volumen pactado sobre el estado FINAL del convenio (lo enviado
     * sobre lo guardado). Lanza ValidationException sin tocar nada.
     *
     * `$datos`: modo_volumen, volumen_total, unidad, fecha_inicio, fecha_fin (Y-m-d)
     * y metas (lista de ['semana', 'cantidad'], o null). `$metasGuardadas` indica que
     * las metas no vienen en la petición sino de la base: entonces no hay un renglón
     * que señalar y un choque con las fechas se reporta en `fecha_inicio`/`fecha_fin`.
     * Las semanas repetidas ya las rechaza la validación `distinct` del controlador.
     * Con `usa_calibres` el volumen total vive en `calibres` ([calibre_id, volumen_total])
     * y cada meta lleva su `calibre_id`; el par (semana, calibre) no se repite.
     * Total propio (solo con `usa_calibres`): en modo `total`, un `volumen_total` no vacío (el
     * cero es un valor inválido, no «sin total») y los volúmenes de los calibres son apartados
     * opcionales que suman a lo más el total; en modo `semanal`, las metas sin calibre son el
     * «total de la semana» (uno por semana) y los apartados de cada semana caben en él.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function validarVolumen(array $datos, bool $metasGuardadas = false): void
    {
        $unidad = $datos['unidad'];
        $metas = is_array($datos['metas'] ?? null) ? array_values($datos['metas']) : [];
        $errores = [];
        $usaCalibres = (bool) ($datos['usa_calibres'] ?? false);
        $calibres = $usaCalibres && is_array($datos['calibres'] ?? null) ? array_values($datos['calibres']) : [];
        $elegidos = array_map(fn ($c) => (int) ($c['calibre_id'] ?? 0), $calibres);

        if ($datos['modo_volumen'] === 'total') {
            if ($usaCalibres && self::hayValor($datos['volumen_total'] ?? null)) {
                // Total propio declarado (cualquier valor no vacío, el cero incluido): los
                // apartados de los calibres son opcionales y suman a lo más el total.
                $errores = self::erroresTotalPropio($datos['volumen_total'], $calibres, $unidad);
            } elseif ($usaCalibres) {
                foreach ($calibres as $i => $calibre) {
                    $total = $calibre['volumen_total'] ?? null;
                    if (! is_numeric($total) || (float) $total <= 0) {
                        $errores["calibres.{$i}.volumen_total"] = 'Indica el volumen total pactado (mayor a cero).';
                    } elseif (! self::cantidadValida($total, $unidad)) {
                        $errores["calibres.{$i}.volumen_total"] = self::mensajeCantidad($unidad);
                    }
                }
            } else {
                $total = $datos['volumen_total'] ?? null;
                if (! is_numeric($total) || (float) $total <= 0) {
                    $errores['volumen_total'] = 'Indica el volumen total pactado (mayor a cero).';
                } elseif (! self::cantidadValida($total, $unidad)) {
                    $errores['volumen_total'] = self::mensajeCantidad($unidad);
                }
            }
            if (! $metasGuardadas && $metas !== []) {
                $errores['metas'] = 'En volumen total no se capturan metas semanales.';
            }
        } elseif ($metas === []) {
            $errores['metas'] = 'Captura al menos una meta semanal.';
            foreach ($elegidos as $i => $id) {
                $errores["calibres.{$i}.calibre_id"] = 'Captura al menos una meta semanal para este calibre.';
            }
        } else {
            // Con calibres, una meta sin calibre es el «total de la semana»: total propio.
            $totalPropio = $usaCalibres && collect($metas)->contains(fn ($m) => ! self::hayValor($m['calibre_id'] ?? null));
            $totales = []; // semana => índice del total (uno por semana)
            $apartados = []; // semana => [índice => cantidad]
            $totalesSemana = []; // semana => cantidad del total

            if ($usaCalibres && ! $totalPropio) {
                $conMeta = [];
                foreach ($metas as $meta) {
                    if (isset($meta['calibre_id']) && $meta['calibre_id'] !== '') {
                        $conMeta[(int) $meta['calibre_id']] = true;
                    }
                }
                foreach ($elegidos as $i => $id) {
                    if (! isset($conMeta[$id])) {
                        $errores["calibres.{$i}.calibre_id"] = 'Captura al menos una meta semanal para este calibre.';
                    }
                }
            }
            $pares = [];

            $semanas = Semanas::entre($datos['fecha_inicio'], $datos['fecha_fin']);
            $primera = $semanas[0] ?? null;
            $ultima = $semanas[count($semanas) - 1] ?? null;

            foreach ($metas as $i => $meta) {
                $semana = (string) $meta['semana'];

                if (! $metasGuardadas) {
                    if (Semanas::lunesDe($semana) !== $semana) {
                        $errores["metas.{$i}.semana"] = 'La semana debe indicarse con la fecha de su lunes.';
                    } elseif (! in_array($semana, $semanas, true)) {
                        $errores["metas.{$i}.semana"] = 'La semana queda fuera de las fechas del convenio.';
                    }
                    if (! self::cantidadValida($meta['cantidad'], $unidad)) {
                        $errores["metas.{$i}.cantidad"] = self::mensajeCantidad($unidad);
                    }

                    if ($usaCalibres) {
                        $calibreMeta = $meta['calibre_id'] ?? null;
                        if (! self::hayValor($calibreMeta)) {
                            // Total de la semana (total propio): uno por semana.
                            if (isset($totales[$semana])) {
                                $errores["metas.{$i}.semana"] = 'Ya hay un total en esa semana.';
                            } else {
                                $totales[$semana] = $i;
                                $totalesSemana[$semana] = (float) $meta['cantidad'];
                            }
                        } elseif (! in_array((int) $calibreMeta, $elegidos, true)) {
                            $errores["metas.{$i}.calibre_id"] = 'El calibre no está en el convenio.';
                        } else {
                            $par = $semana.'|'.(int) $calibreMeta;
                            if (isset($pares[$par])) {
                                $errores["metas.{$i}.semana"] = 'Ya hay una meta de ese calibre en esa semana.';
                            }
                            $pares[$par] = true;
                            $apartados[$semana][$i] = (float) $meta['cantidad'];
                        }
                    }

                    continue;
                }

                if ($primera !== null && $semana < $primera) {
                    $errores['fecha_inicio'] = 'Hay metas fuera de las nuevas fechas: ajústalas primero.';
                }
                if ($ultima !== null && $semana > $ultima) {
                    $errores['fecha_fin'] = 'Hay metas fuera de las nuevas fechas: ajústalas primero.';
                }
                if (! self::cantidadValida($meta['cantidad'], $unidad)) {
                    $errores['unidad'] = 'Hay metas con cantidades que no caben en esta unidad: ajústalas primero.';
                }
            }

            // Los apartados de cada semana suman a lo más el total de esa semana (sin total cuenta 0);
            // se señala el primer apartado que la rebasa. Las guardadas ya se validaron al capturarlas.
            if ($totalPropio && ! $metasGuardadas) {
                foreach ($apartados as $semana => $filas) {
                    $total = round($totalesSemana[$semana] ?? 0.0, 2);
                    $suma = 0.0;
                    foreach ($filas as $i => $cantidad) {
                        $suma = round($suma + $cantidad, 2);
                        if ($suma > $total + 1e-9) {
                            $errores["metas.{$i}.cantidad"] = isset($totalesSemana[$semana])
                                ? 'Los apartados de la semana suman '.self::formatear($suma).' y el total de la semana es '.self::formatear($total).'.'
                                : 'Esta semana tiene apartados pero no tiene total: captura el total de la semana.';
                            break;
                        }
                    }
                }
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Errores del total propio en modo `total` (ya declarado): el total mayor a cero y con la
     * unidad correcta (clave `volumen_total`); cada apartado vacío o con valor mayor o igual a cero;
     * y Σ apartados ≤ total (clave `volumen_total`).
     *
     * @param  array<int, array<string, mixed>>  $calibres
     * @return array<string, string>
     */
    private static function erroresTotalPropio(mixed $total, array $calibres, string $unidad): array
    {
        $errores = [];
        $totalValido = true;
        if (! is_numeric($total) || (float) $total <= 0) {
            $errores['volumen_total'] = 'Indica el volumen total pactado (mayor a cero).';
            $totalValido = false;
        } elseif (! self::cantidadValida($total, $unidad)) {
            $errores['volumen_total'] = self::mensajeCantidad($unidad);
            $totalValido = false;
        }

        $suma = 0.0;
        foreach ($calibres as $i => $calibre) {
            $apartado = $calibre['volumen_total'] ?? null;
            if (! self::hayValor($apartado)) {
                continue;
            }
            if (! is_numeric($apartado) || (float) $apartado < 0) {
                $errores["calibres.{$i}.volumen_total"] = 'El apartado debe ser un número mayor o igual a cero.';
            } elseif (! self::cantidadValida($apartado, $unidad)) {
                $errores["calibres.{$i}.volumen_total"] = self::mensajeCantidad($unidad);
            } else {
                $suma = round($suma + (float) $apartado, 2);
            }
        }

        if ($totalValido && $suma > round((float) $total, 2) + 1e-9) {
            $errores['volumen_total'] = 'Los apartados suman '.self::formatear($suma).' y el total es '.self::formatear((float) $total).'.';
        }

        return $errores;
    }

    /** Null y cadena vacía son «sin valor»; el cero sí es un valor. */
    private static function hayValor(mixed $valor): bool
    {
        return $valor !== null && $valor !== '';
    }

    /** Cantidad para un mensaje: sin ceros sobrantes ni separador de miles (8000, 10.5). */
    private static function formatear(float $cantidad): string
    {
        return rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.');
    }

    /** Cajas: enteras. Kilos: hasta dos decimales. */
    private static function cantidadValida(mixed $cantidad, string $unidad): bool
    {
        $f = (float) $cantidad;

        return $unidad === 'caja'
            ? abs($f - round($f)) < 1e-9
            : abs($f * 100 - round($f * 100)) < 1e-6;
    }

    private static function mensajeCantidad(string $unidad): string
    {
        return $unidad === 'caja'
            ? 'En cajas la cantidad debe ser un número entero.'
            : 'En kilos la cantidad admite hasta dos decimales.';
    }

    private static function marcado(array $precio): bool
    {
        return filter_var($precio['es_principal'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
