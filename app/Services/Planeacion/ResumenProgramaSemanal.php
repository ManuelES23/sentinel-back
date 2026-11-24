<?php

namespace App\Services\Planeacion;

use Carbon\Carbon;

/**
 * Hoja resumen de una semana del Programa semanal (la imagen que va en el
 * correo): campo, cajas, necesidad de empaque por presentación, disponible,
 * embarques y balance por color, con el viernes y el sábado de la semana
 * anterior al inicio para ver cómo cerró.
 *
 * No calcula nada nuevo: agrupa el payload de ProgramaSemanalService::semana()
 * (el mismo que usan el Tablero y el Excel). Devuelve un arreglo plano que
 * dibuja ImagenResumenPrograma y que el cuerpo del correo lee para sus cifras.
 */
final class ResumenProgramaSemanal
{
    public const LETRAS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    /** Días de la semana anterior que se muestran al inicio: viernes y sábado. */
    public const DIAS_PREVIOS = [4, 5];

    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const TIPOS_EMBARQUE = ['full' => 0, 'sencillo' => 1];

    /**
     * @param  array  $semana  Payload de ProgramaSemanalService::semana().
     * @param  array|null  $anterior  Payload de la semana anterior (lunes − 7), o null si no tiene programa.
     * @return array{
     *     semana: int, lunes: string, rango: string, cultivo: ?string, notas: ?string, cajas_por_carga: int,
     *     columnas: list<array{letra: string, dia: string, fecha: string, previa: bool}>,
     *     con_previas: bool, secciones: list<array>, kpis: array, por_color: list<array>, faltantes: list<array>
     * }
     */
    public function armar(array $semana, ?array $anterior, ?int $cultivoId, ?string $cultivoNombre = null): array
    {
        $programa = $semana['programa'];
        $cultivoNombre ??= collect($semana['cultivos'])->firstWhere('id', $cultivoId)['nombre'] ?? null;
        $dias = ($programa['trabaja_domingo'] ?? true) ? range(0, 6) : range(0, 5);

        // Cada columna: [payload, índice del día, ¿previa?]. Sin programa anterior las previas van vacías.
        $columnas = [];
        foreach (self::DIAS_PREVIOS as $d) {
            $columnas[] = [$anterior, $d, true];
        }
        foreach ($dias as $d) {
            $columnas[] = [$semana, $d, false];
        }

        $lunes = Carbon::createFromFormat('!Y-m-d', $programa['lunes']);
        $cabeceras = array_map(function (array $c) use ($lunes) {
            [, $d, $previa] = $c;
            $fecha = $lunes->copy()->addDays($previa ? $d - 7 : $d);

            return ['letra' => self::LETRAS[$d], 'dia' => $fecha->format('d'), 'fecha' => $fecha->format('Y-m-d'), 'previa' => $previa];
        }, $columnas);

        $secciones = array_values(array_filter([
            $this->campo($semana, $columnas, $cultivoId),
            $this->cajas($semana, $columnas, $cultivoId),
            $this->necesidad($semana, $columnas, $cultivoId),
            $this->disponible($columnas, $cultivoId),
            $this->embarques($semana, $anterior, $columnas, $cultivoId),
        ]));

        $balance = $this->balanceDe($semana, $cultivoId);
        $porColor = $this->porColor($semana, $cultivoId, (int) $semana['catalogos']['cajas_por_carga']);
        $total = fn (string $campo) => array_sum(array_map(fn ($d) => $balance[$campo][$d], $dias));
        $embarques = $this->cargas($semana, $cultivoId);

        $faltantes = [];
        foreach ($dias as $d) {
            if ($balance['del_dia'][$d] < 0) {
                $faltantes[] = ['dia' => self::LETRAS[$d].' '.$lunes->copy()->addDays($d)->format('d'), 'cajas' => $balance['del_dia'][$d]];
            }
        }

        return [
            'semana' => (int) $programa['semana_iso'],
            'lunes' => $programa['lunes'],
            'rango' => $this->rango($lunes, $lunes->copy()->addDays(end($dias))),
            'cultivo' => $cultivoNombre,
            'notas' => isset($programa['notas']) && trim((string) $programa['notas']) !== '' ? trim((string) $programa['notas']) : null,
            'cajas_por_carga' => (int) $semana['catalogos']['cajas_por_carga'],
            'columnas' => $cabeceras,
            'con_previas' => $anterior !== null,
            'secciones' => $secciones,
            'kpis' => [
                'produccion' => $total('produccion'),
                'necesidad' => $total('necesidad') + $total('sobrante'),
                'disponible' => $total('del_dia'),
                'full' => array_sum(array_map(fn ($b) => array_sum(array_map(fn ($d) => $b[$d][0], $dias)), $embarques)),
                'sencillo' => array_sum(array_map(fn ($b) => array_sum(array_map(fn ($d) => $b[$d][1], $dias)), $embarques)),
            ],
            'por_color' => $porColor,
            'faltantes' => $faltantes,
        ];
    }

    // ── Bloques ─────────────────────────────────────────

    /** Campo · hectáreas: una fila por variedad del cultivo con cortes, con su rendimiento. */
    private function campo(array $semana, array $columnas, ?int $cultivoId): array
    {
        $filas = [];
        foreach ($this->variedades($semana, $cultivoId) as $v) {
            $valores = $this->porColumna($columnas, fn (array $p, int $d) => $this->sumaCortes($p, $v['id'], $d, 'hectareas'));
            if (! $this->tieneDatos($valores)) {
                continue;
            }
            $filas[] = $this->fila($v['nombre'], $valores, $columnas, 'ha', $this->rendimiento($semana, $v['id']));
        }

        return $filas === [] ? [] : ['titulo' => 'Campo · hectáreas', 'filas' => $filas];
    }

    /** Cajas producidas: una fila por variedad con cajas y el total (siempre). */
    private function cajas(array $semana, array $columnas, ?int $cultivoId): array
    {
        $filas = [];
        $ids = [];
        foreach ($this->variedades($semana, $cultivoId) as $v) {
            $ids[] = $v['id'];
            $valores = $this->porColumna($columnas, fn (array $p, int $d) => $this->sumaCortes($p, $v['id'], $d, 'cajas'));
            if ($this->tieneDatos($valores)) {
                $filas[] = $this->fila($v['nombre'], $valores, $columnas, 'cajas');
            }
        }
        $total = $this->porColumna($columnas, fn (array $p, int $d) => array_sum(array_map(fn ($id) => $this->sumaCortes($p, $id, $d, 'cajas'), $ids)));
        $filas[] = $this->fila('Total', $total, $columnas, 'cajas') + ['total_fila' => true];

        return ['titulo' => 'Cajas producidas', 'filas' => $filas];
    }

    /**
     * Necesidad de empaque por presentación y tipo de empaque («Bulk · RPC»,
     * «Bulk · Cartón»…; ventas, por día de empaque), en el orden de los
     * catálogos; las ventas sin empaque van como «· Sin empaque». Después, el
     * sobrante asignado si hay y el total (siempre).
     */
    private function necesidad(array $semana, array $columnas, ?int $cultivoId): array
    {
        $empaques = array_merge(
            array_map(fn ($t) => [(int) $t['id'], $t['nombre']], $semana['catalogos']['tipos_empaque']),
            [[null, 'Sin empaque']],
        );
        $filas = [];
        foreach ($semana['catalogos']['presentaciones'] as $p) {
            if ((int) $p['cultivo_id'] !== $cultivoId) {
                continue;
            }
            foreach ($empaques as [$empaqueId, $empaqueNombre]) {
                $valores = $this->porColumna($columnas, fn (array $s, int $d) => $this->sumaEmpaque($s, $cultivoId, $d, ['ok', 'sin_clasificar'], (int) $p['id'], $empaqueId));
                if ($this->tieneDatos($valores)) {
                    $filas[] = $this->fila($p['nombre'].' · '.$empaqueNombre, $valores, $columnas, 'cajas');
                }
            }
        }
        $sobrante = $this->porColumna($columnas, fn (array $s, int $d) => $this->sumaEmpaque($s, $cultivoId, $d, ['sobrante']));
        if ($this->tieneDatos($sobrante)) {
            $filas[] = $this->fila('Sobrante asignado', $sobrante, $columnas, 'cajas');
        }
        $total = $this->porColumna($columnas, function (array $s, int $d) use ($cultivoId) {
            $b = $this->balanceDe($s, $cultivoId);

            return $b['necesidad'][$d] + $b['sobrante'][$d];
        });
        $filas[] = $this->fila('Total', $total, $columnas, 'cajas') + ['total_fila' => true];

        return ['titulo' => 'Necesidad de empaque', 'filas' => $filas];
    }

    /** Disponible del día del balance (producción − necesidad − sobrante asignado). */
    private function disponible(array $columnas, ?int $cultivoId): array
    {
        $valores = $this->porColumna($columnas, fn (array $s, int $d) => $this->balanceDe($s, $cultivoId)['del_dia'][$d]);

        return ['titulo' => null, 'filas' => [$this->fila('Disponible', $valores, $columnas, 'signo') + ['total_fila' => true]]];
    }

    /** Embarques · cargas: una fila por destino con cargas; cada celda «2F 1S». */
    private function embarques(array $semana, ?array $anterior, array $columnas, ?int $cultivoId): array
    {
        $deSemana = $this->cargas($semana, $cultivoId);
        $deAnterior = $anterior !== null ? $this->cargas($anterior, $cultivoId) : [];

        $filas = [];
        foreach ($semana['catalogos']['bodegas'] as $bodega) {
            $id = (int) $bodega['id'];
            if (! isset($deSemana[$id]) && ! isset($deAnterior[$id])) {
                continue;
            }
            $valores = [];
            $total = [0, 0];
            foreach ($columnas as [$p, $d, $previa]) {
                if ($p === null) {
                    $valores[] = null;

                    continue;
                }
                $par = ($previa ? $deAnterior : $deSemana)[$id][$d] ?? [0, 0];
                $valores[] = $par;
                if (! $previa) {
                    $total[0] += $par[0];
                    $total[1] += $par[1];
                }
            }
            $filas[] = ['etiqueta' => $bodega['nombre'], 'nota' => null, 'tipo' => 'cargas', 'valores' => $valores, 'total' => $total];
        }

        return $filas === [] ? [] : ['titulo' => 'Embarques · cargas', 'filas' => $filas];
    }

    /** Balance por color: un renglón por grupo de balance del cultivo más el total. */
    private function porColor(array $semana, ?int $cultivoId, int $cajasPorCarga): array
    {
        $salida = [];
        $total = ['produccion' => 0, 'necesidad' => 0, 'sobrante' => 0];
        foreach ($semana['grupos'] as $g) {
            if ((int) $g['cultivo_id'] !== $cultivoId || ! isset($semana['balance'][$g['id']])) {
                continue;
            }
            $b = $semana['balance'][$g['id']];
            $renglon = [
                'produccion' => (int) array_sum($b['produccion']),
                'necesidad' => (int) array_sum($b['necesidad']),
                'sobrante' => (int) array_sum($b['sobrante_asignado'] ?? []),
            ];
            foreach ($renglon as $k => $v) {
                $total[$k] += $v;
            }
            $salida[] = ['nombre' => $g['nombre']] + $this->conDisponible($renglon, $cajasPorCarga);
        }
        $salida[] = ['nombre' => 'Total', 'total' => true] + $this->conDisponible($total, $cajasPorCarga);

        return $salida;
    }

    // ── Ayudas ──────────────────────────────────────────

    /** @return list<array> variedades del cultivo, en el orden del payload */
    private function variedades(array $semana, ?int $cultivoId): array
    {
        return array_values(array_filter($semana['variedades'], fn ($v) => (int) $v['cultivo_id'] === $cultivoId));
    }

    /**
     * Valor de cada columna: null si la columna es previa y no hay programa anterior.
     *
     * @return list<int|float|null>
     */
    private function porColumna(array $columnas, callable $valor): array
    {
        return array_map(fn (array $c) => $c[0] === null ? null : $valor($c[0], $c[1]), $columnas);
    }

    private function tieneDatos(array $valores): bool
    {
        foreach ($valores as $v) {
            if ($v !== null && $v != 0) {
                return true;
            }
        }

        return false;
    }

    /** Una fila con su total, que solo suma las columnas de la semana (no las previas). */
    private function fila(string $etiqueta, array $valores, array $columnas, string $tipo, string|null $nota = null): array
    {
        $total = 0;
        foreach ($columnas as $i => [, , $previa]) {
            if (! $previa) {
                $total += $valores[$i] ?? 0;
            }
        }

        return ['etiqueta' => $etiqueta, 'nota' => $nota, 'tipo' => $tipo, 'valores' => $valores, 'total' => $tipo === 'ha' ? round($total, 2) : $total];
    }

    private function sumaCortes(array $semana, int $variedadId, int $dia, string $campo): int|float
    {
        $suma = 0;
        foreach ($semana['cortes'] as $c) {
            if ((int) $c['variedad_id'] === $variedadId && (int) $c['dia'] === $dia) {
                $suma += $c[$campo];
            }
        }

        return $campo === 'hectareas' ? round($suma, 2) : (int) $suma;
    }

    /**
     * Cajas del programa de empaque de un día, solo del cultivo, de las clases dadas y, si se indican,
     * de una presentación y un tipo de empaque (`$empaqueId` null = sin empaque; `false` = cualquiera).
     *
     * @param  list<string>  $clases
     */
    private function sumaEmpaque(array $semana, ?int $cultivoId, int $dia, array $clases, ?int $presentacionId = null, int|null|false $empaqueId = false): int
    {
        $cultivoDe = array_column($semana['catalogos']['presentaciones'], 'cultivo_id', 'id');
        $suma = 0;
        foreach ($semana['empaque'] as $r) {
            if (! in_array($r['clase'], $clases, true) || (int) ($cultivoDe[$r['presentacion_id']] ?? 0) !== $cultivoId) {
                continue;
            }
            if ($presentacionId !== null && (int) $r['presentacion_id'] !== $presentacionId) {
                continue;
            }
            if ($empaqueId !== false && ($r['tipo_empaque_id'] === null ? null : (int) $r['tipo_empaque_id']) !== $empaqueId) {
                continue;
            }
            $suma += (int) ($r['cajas'][$dia] ?? 0);
        }

        return $suma;
    }

    /**
     * Balance de los grupos del cultivo sumado por día.
     *
     * @return array{produccion: int[], necesidad: int[], sobrante: int[], del_dia: int[]}
     */
    private function balanceDe(array $semana, ?int $cultivoId): array
    {
        $suma = ['produccion' => array_fill(0, 7, 0), 'necesidad' => array_fill(0, 7, 0), 'sobrante' => array_fill(0, 7, 0), 'del_dia' => array_fill(0, 7, 0)];
        foreach ($semana['grupos'] as $g) {
            if ((int) $g['cultivo_id'] !== $cultivoId || ! isset($semana['balance'][$g['id']])) {
                continue;
            }
            $b = $semana['balance'][$g['id']];
            foreach (range(0, 6) as $d) {
                $suma['produccion'][$d] += (int) ($b['produccion'][$d] ?? 0);
                $suma['necesidad'][$d] += (int) ($b['necesidad'][$d] ?? 0);
                $suma['sobrante'][$d] += (int) ($b['sobrante_asignado'][$d] ?? 0);
                $suma['del_dia'][$d] += (int) ($b['del_dia'][$d] ?? 0);
            }
        }

        return $suma;
    }

    /**
     * Cargas por destino y día: [bodega_id => [día => [full, sencillo]]].
     *
     * @return array<int, array<int, array{0: int, 1: int}>>
     */
    private function cargas(array $semana, ?int $cultivoId): array
    {
        $salida = [];
        foreach ($semana['embarques'] as $e) {
            if ((int) $e['cultivo_id'] !== $cultivoId || ! isset(self::TIPOS_EMBARQUE[$e['tipo']])) {
                continue;
            }
            $bodega = (int) $e['bodega_id'];
            $salida[$bodega] ??= array_fill(0, 7, [0, 0]);
            if (isset($salida[$bodega][$e['dia']])) {
                $salida[$bodega][$e['dia']][self::TIPOS_EMBARQUE[$e['tipo']]] += (int) $e['cargas'];
            }
        }

        return $salida;
    }

    /** Rendimiento que usa la semana para una variedad («800 cj/ha», «varios» o null). */
    private function rendimiento(array $semana, int $variedadId): ?string
    {
        $conCortes = [];
        foreach ($semana['cortes'] as $c) {
            if ((int) $c['variedad_id'] === $variedadId) {
                $conCortes[(int) $c['temporada_id']] = true;
            }
        }
        $valores = [];
        foreach ($semana['rendimientos'] as $r) {
            if ((int) $r['variedad_id'] === $variedadId && $r['valor_usado'] !== null && ($conCortes === [] || isset($conCortes[(int) $r['temporada_id']]))) {
                $valores[(string) round($r['valor_usado'])] = (int) round($r['valor_usado']);
            }
        }

        return match (count($valores)) {
            0 => null,
            1 => number_format(array_values($valores)[0]).' cj/ha',
            default => 'varios',
        };
    }

    private function conDisponible(array $renglon, int $cajasPorCarga): array
    {
        $disponible = $renglon['produccion'] - $renglon['necesidad'] - $renglon['sobrante'];

        return $renglon + [
            'disponible' => $disponible,
            'cargas' => $cajasPorCarga > 0 ? round($disponible / $cajasPorCarga, 2) : null,
        ];
    }

    /** «23 – 28 nov 2026» o, si cambia de mes, «30 nov – 5 dic 2026». */
    private function rango(Carbon $desde, Carbon $hasta): string
    {
        $mes = fn (Carbon $f) => self::MESES[$f->month - 1];

        if ($desde->year !== $hasta->year) {
            return $desde->day.' '.$mes($desde).' '.$desde->year.' – '.$hasta->day.' '.$mes($hasta).' '.$hasta->year;
        }

        return $desde->month === $hasta->month
            ? $desde->day.' – '.$hasta->day.' '.$mes($hasta).' '.$hasta->year
            : $desde->day.' '.$mes($desde).' – '.$hasta->day.' '.$mes($hasta).' '.$hasta->year;
    }
}
