<?php

namespace App\Services\Planeacion;

use Carbon\Carbon;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Una o varias semanas del Programa semanal como .xlsx (una hoja por semana), con la distribución
 * de la hoja WK del Excel «PROGRAMA CORN OI 2026-2027» (ocho secciones, días de
 * lunes a domingo y total) y solo valores, sin fórmulas.
 *
 * No calcula nada: todas las cifras salen del payload que arma
 * ProgramaSemanalService::semana() para el Tablero; aquí solo se agrupan y se
 * escriben. Una instancia se usa por petición.
 */
class ExportadorProgramaExcel
{
    /** Nombres de sección del tablero, tal como se ven en pantalla y en el Excel. */
    public const SECCIONES = [
        'campo' => 'CAMPO — HECTÁREAS A COSECHAR',
        'produccion' => 'PRODUCCIÓN ESTIMADA (CAJAS)',
        'necesidades' => 'NECESIDADES VENTAS (CAJAS)',
        'empaque' => 'PROGRAMA DE EMPAQUE (se calcula de las necesidades)',
        'balance' => 'BALANCE PRODUCCIÓN vs NECESIDAD',
        'color' => 'POR COLOR',
        'embarques' => 'EMBARQUES (número de cargas)',
        'notas' => 'NOTAS DE LA SEMANA',
    ];

    public const TIPO_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const DIAS_LETRA = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    /** Columnas de los siete días (G a M) y la del total (N) de una semana con domingo. */
    private const COLUMNAS_DIA = ['G', 'H', 'I', 'J', 'K', 'L', 'M'];

    private const COLUMNA_TOTAL = 'N';

    /** Sin domingo: seis días (G a L) y el total en la M. */
    private const COLUMNAS_DIA_SIN_DOMINGO = ['G', 'H', 'I', 'J', 'K', 'L'];

    private const COLUMNA_TOTAL_SIN_DOMINGO = 'M';

    private const TIPOS_EMBARQUE = ['full' => 'Full', 'sencillo' => 'Sencillo'];

    private const FORMATO_CAJAS = '#,##0;[Red]-#,##0';

    private const FORMATO_FECHA = 'd-mmm';

    private Worksheet $hoja;

    private int $fila = 1;

    /** @var list<string> columnas de día y columna del total de la hoja en curso (según trabaje o no el domingo) */
    private array $columnasDia = self::COLUMNAS_DIA;

    private string $columnaTotal = self::COLUMNA_TOTAL;

    /** @var array<int|string, array> catálogo por id: bodegas, presentaciones, tipos de empaque */
    private array $bodegas = [];

    private array $presentaciones = [];

    private array $tiposEmpaque = [];

    /** Nombre de archivo de la descarga: `Programa_WK48_2026-11-23.xlsx`. */
    public function nombreArchivo(array $semana): string
    {
        return sprintf('Programa_WK%02d_%s.xlsx', $semana['programa']['semana_iso'], $semana['programa']['lunes']);
    }

    /** Primer cultivo de la semana (por nombre) con cortes, necesidades o empaque; si no hay, el primero que haya. */
    public function cultivoPorDefecto(array $semana): ?int
    {
        $conDatos = [];
        foreach ($semana['necesidades'] as $n) {
            $conDatos[(int) $n['cultivo_id']] = true;
        }
        $cultivoDeVariedad = [];
        foreach ($semana['variedades'] as $v) {
            $cultivoDeVariedad[$v['id']] = (int) $v['cultivo_id'];
        }
        foreach ($semana['cortes'] as $c) {
            if (isset($cultivoDeVariedad[$c['variedad_id']])) {
                $conDatos[$cultivoDeVariedad[$c['variedad_id']]] = true;
            }
        }
        // Pedidos de la semana siguiente que se empacan en esta (días de antelación): solo vienen en el empaque.
        $cultivoDePresentacion = [];
        foreach ($semana['catalogos']['presentaciones'] as $p) {
            $cultivoDePresentacion[$p['id']] = (int) $p['cultivo_id'];
        }
        foreach ($semana['empaque'] as $r) {
            if (array_sum($r['cajas']) !== 0 && isset($cultivoDePresentacion[$r['presentacion_id']])) {
                $conDatos[$cultivoDePresentacion[$r['presentacion_id']]] = true;
            }
        }

        foreach ($semana['cultivos'] as $c) {
            if (isset($conDatos[$c['id']])) {
                return (int) $c['id'];
            }
        }

        return $semana['cultivos'][0]['id'] ?? null;
    }

    /**
     * Escribe el libro de una semana en un archivo temporal y devuelve su ruta
     * (quien lo descarga lo borra después de enviarlo).
     *
     * @param  array  $semana  Payload de ProgramaSemanalService::semana().
     * @param  string|null  $cultivoNombre  Nombre del cultivo del encabezado; si no hay, el del payload.
     */
    public function generar(array $semana, ?int $cultivoId, ?string $cultivoNombre = null): string
    {
        return $this->generarVarias([$semana], $cultivoId, $cultivoNombre);
    }

    /**
     * Un libro con una hoja por semana, en el orden recibido, cada una con el
     * mismo contenido que generar() y titulada `WK{nn}` (más el año ISO cuando
     * dos semanas darían el mismo título). Devuelve la ruta del .xlsx
     * temporal; quien lo usa lo borra.
     *
     * @param  list<array>  $semanas  Payloads de ProgramaSemanalService::semana().
     */
    public function generarVarias(array $semanas, ?int $cultivoId, ?string $cultivoNombre = null): string
    {
        if ($semanas === []) {
            throw new InvalidArgumentException('Se necesita al menos una semana.');
        }
        $semanas = array_values($semanas);
        $numeros = array_map(fn ($s) => sprintf('%02d', $s['programa']['semana_iso']), $semanas);
        $vecesPorNumero = array_count_values($numeros);

        $libro = new Spreadsheet;
        $libro->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $libro->getProperties()->setCreator('Sentinel ERP')->setTitle(count($semanas) === 1
            ? 'Programa semana '.$numeros[0]
            : 'Programa semanas '.$numeros[0].'–'.end($numeros));

        foreach ($semanas as $i => $semana) {
            $hoja = $i === 0 ? $libro->getActiveSheet() : $libro->createSheet();
            $titulo = 'WK'.$numeros[$i];
            if ($vecesPorNumero[$numeros[$i]] > 1) {
                $titulo .= ' '.Carbon::createFromFormat('!Y-m-d', $semana['programa']['lunes'])->isoWeekYear;
            }
            $hoja->setTitle($titulo);
            $this->escribirHoja($hoja, $semana, $cultivoId, $cultivoNombre);
        }
        $libro->setActiveSheetIndex(0);

        $temporal = tempnam(sys_get_temp_dir(), 'programa_');
        $ruta = $temporal.'.xlsx';
        @unlink($temporal);
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }

    /** Escribe una semana en su hoja; el estado de instancia (hoja, fila, catálogos) se reinicia en cada una. */
    private function escribirHoja(Worksheet $hoja, array $semana, ?int $cultivoId, ?string $cultivoNombre): void
    {
        $programa = $semana['programa'];
        $numero = sprintf('%02d', $programa['semana_iso']);
        $cultivoNombre ??= collect($semana['cultivos'])->firstWhere('id', $cultivoId)['nombre'] ?? null;

        $this->hoja = $hoja;
        $this->fila = 1;
        // Una semana sin domingo no escribe esa columna y el total sube a la siguiente al sábado. Los arreglos
        // del payload siguen con siete posiciones (la del domingo en cero): solo se dejan de escribir.
        $trabajaDomingo = (bool) ($programa['trabaja_domingo'] ?? true);
        $this->columnasDia = $trabajaDomingo ? self::COLUMNAS_DIA : self::COLUMNAS_DIA_SIN_DOMINGO;
        $this->columnaTotal = $trabajaDomingo ? self::COLUMNA_TOTAL : self::COLUMNA_TOTAL_SIN_DOMINGO;

        $this->bodegas = array_column($semana['catalogos']['bodegas'], null, 'id');
        $this->presentaciones = array_column($semana['catalogos']['presentaciones'], null, 'id');
        $this->tiposEmpaque = array_column($semana['catalogos']['tipos_empaque'], null, 'id');

        $this->escribirEncabezado($programa, $numero, $cultivoNombre);

        $variedades = array_values(array_filter($semana['variedades'], fn ($v) => (int) $v['cultivo_id'] === $cultivoId));
        $grupos = array_values(array_filter($semana['grupos'], fn ($g) => (int) $g['cultivo_id'] === $cultivoId));

        $this->escribirCampo($semana, $variedades, $programa['dias'], $cultivoNombre);
        $this->escribirProduccion($semana, $variedades);
        $this->escribirNecesidades($semana, $variedades, $grupos, $cultivoId, $programa['dias']);
        $this->escribirEmpaque($semana, $grupos, $cultivoId, $programa['dias']);
        $this->escribirBalance($semana, $grupos, $programa['dias']);
        $this->escribirPorColor($semana, $grupos, (int) $semana['catalogos']['cajas_por_carga']);
        $this->escribirEmbarques($semana, $cultivoId, $programa['dias']);
        $this->escribirNotas($programa['notas'] ?? null);

        foreach (['A' => 2, 'B' => 14, 'C' => 26, 'D' => 14, 'E' => 18, 'F' => 12, $this->columnaTotal => 12] + array_fill_keys($this->columnasDia, 10) as $letra => $ancho) {
            $this->hoja->getColumnDimension($letra)->setWidth($ancho);
        }
        $this->hoja->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        $this->hoja->getSheetView()->setZoomScale(90);
    }

    // ── Secciones ───────────────────────────────────────

    private function escribirEncabezado(array $programa, string $numero, ?string $cultivoNombre): void
    {
        $this->texto('B1', 'Splendid by Porvenir — Programación de producción y destino'.($cultivoNombre ? ' · '.$cultivoNombre : ''));
        $this->hoja->getStyle('B1')->getFont()->setBold(true)->setSize(12);
        $this->texto('B2', 'SEMANA');
        $this->hoja->getCell('C2')->setValue((int) $numero);
        $this->hoja->getStyle('C2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->texto('D2', 'Lunes');
        $this->fecha('E2', $programa['lunes']);
        $this->hoja->getStyle('E2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->texto('F2', 'PROGRAMA SEMANA '.$numero);
        $this->hoja->getStyle('B2:F2')->getFont()->setBold(true);
        $this->fila = 4;
    }

    /** 1. CAMPO — hectáreas por color y día, con el rendimiento que usa la semana. */
    private function escribirCampo(array $semana, array $variedades, array $dias, ?string $cultivoNombre): void
    {
        $this->titulo(self::SECCIONES['campo']);
        $this->fechasDeDias($dias);
        $this->encabezado(['C' => 'COLOR', 'D' => 'REND cj/ha'] + array_combine($this->columnasDia, array_slice(self::DIAS_LETRA, 0, count($this->columnasDia))) + [$this->columnaTotal => 'HA TOTAL']);

        $porDia = [];
        $totalDia = array_fill(0, 7, 0.0);
        foreach ($variedades as $v) {
            $ha = array_fill(0, 7, 0.0);
            foreach ($semana['cortes'] as $c) {
                if ((int) $c['variedad_id'] === (int) $v['id'] && isset($ha[$c['dia']])) {
                    $ha[$c['dia']] += $c['hectareas'];
                }
            }
            $porDia[$v['id']] = $ha;

            $this->texto('C'.$this->fila, $v['nombre']);
            $rendimiento = $this->rendimientoDe($semana, $v['id']);
            if (is_string($rendimiento)) {
                $this->texto('D'.$this->fila, $rendimiento);
            } elseif ($rendimiento !== null) {
                $this->hoja->setCellValue('D'.$this->fila, $rendimiento);
            }
            $this->filaDeDias($ha, false);
            foreach ($ha as $d => $valor) {
                $totalDia[$d] += $valor;
            }
            $this->fila++;
        }

        $this->texto('C'.$this->fila, 'Total ha '.mb_strtolower($cultivoNombre ?? 'cultivo'));
        $this->filaDeDias($totalDia, false);
        $this->negritas('C'.$this->fila.':'.$this->columnaTotal.$this->fila, true);
        $this->fila += 2;
    }

    /** 2. PRODUCCIÓN ESTIMADA — cajas por color y día. */
    private function escribirProduccion(array $semana, array $variedades): void
    {
        $this->titulo(self::SECCIONES['produccion']);

        $totalDia = array_fill(0, 7, 0);
        foreach ($variedades as $v) {
            $cajas = array_fill(0, 7, 0);
            foreach ($semana['cortes'] as $c) {
                if ((int) $c['variedad_id'] === (int) $v['id'] && isset($cajas[$c['dia']])) {
                    $cajas[$c['dia']] += $c['cajas'];
                }
            }
            $this->texto('C'.$this->fila, $v['nombre']);
            $this->filaDeDias($cajas);
            foreach ($cajas as $d => $valor) {
                $totalDia[$d] += $valor;
            }
            $this->fila++;
        }

        $this->texto('C'.$this->fila, 'Total cajas');
        $this->filaDeDias($totalDia);
        $this->negritas('C'.$this->fila.':'.$this->columnaTotal.$this->fila, true);
        $this->fila += 2;
    }

    /**
     * 3. NECESIDADES VENTAS — renglones con cajas por destino, subtotal por
     * destino y total.
     */
    private function escribirNecesidades(array $semana, array $variedades, array $grupos, ?int $cultivoId, array $dias): void
    {
        $this->titulo(self::SECCIONES['necesidades']);

        $nombreVariedad = array_column($variedades, 'nombre', 'id');
        $totalDia = array_fill(0, 7, 0);
        foreach ($this->bodegasConDatos($semana['necesidades'], $cultivoId) as $bodega) {
            $this->encabezado(['B' => mb_strtoupper($bodega['nombre']), 'C' => 'CLIENTE', 'D' => 'PRESENT', 'E' => 'COLOR', 'F' => 'EMPAQUE'] + $this->fechasComoEncabezado($dias));

            $subtotal = array_fill(0, 7, 0);
            foreach ($semana['necesidades'] as $n) {
                if ((int) $n['cultivo_id'] !== $cultivoId || (int) $n['bodega_id'] !== (int) $bodega['id'] || array_sum($n['cajas']) === 0) {
                    continue;
                }
                $this->texto('C'.$this->fila, (string) $n['cliente_nombre']);
                $this->texto('D'.$this->fila, $this->presentaciones[$n['presentacion_id']]['nombre'] ?? '');
                $this->texto('E'.$this->fila, $this->colorDe($n['variedad_ids'], $n['grupo_id'], $nombreVariedad, $grupos));
                $this->texto('F'.$this->fila, $n['tipo_empaque_id'] !== null ? ($this->tiposEmpaque[$n['tipo_empaque_id']]['nombre'] ?? '') : 'Sin empaque');
                $this->filaDeDias($n['cajas']);
                foreach ($n['cajas'] as $d => $valor) {
                    $subtotal[$d] += $valor;
                }
                $this->fila++;
            }

            $this->texto('C'.$this->fila, 'Subtotal '.$bodega['nombre']);
            $this->filaDeDias($subtotal);
            $this->negritas('C'.$this->fila.':'.$this->columnaTotal.$this->fila, true);
            $this->fila += 2;
            foreach ($subtotal as $d => $valor) {
                $totalDia[$d] += $valor;
            }
        }

        $this->texto('C'.$this->fila, 'TOTAL NECESIDAD');
        $this->filaDeDias($totalDia);
        $this->resaltar('C'.$this->fila.':'.$this->columnaTotal.$this->fila);
        $this->fila += 2;
    }

    /**
     * 4. PROGRAMA DE EMPAQUE — presentación × color × empaque por destino y en
     * total. El sobrante asignado sale dentro de su destino como «Sobrante · empaque»
     * y suma al total del destino. Lo que no tiene empaque va aparte en
     * «Sin clasificar (debe ser 0)».
     */
    private function escribirEmpaque(array $semana, array $grupos, ?int $cultivoId, array $dias): void
    {
        $this->titulo(self::SECCIONES['empaque']);

        $nombreGrupo = array_column($grupos, 'nombre', 'id');
        $ordenGrupo = array_flip(array_column($grupos, 'id'));
        $ordenPresentacion = array_flip(array_keys($this->presentaciones));
        $ordenEmpaque = array_flip(array_keys($this->tiposEmpaque));

        $renglones = array_values(array_filter($semana['empaque'], fn ($r) => (int) ($this->presentaciones[$r['presentacion_id']]['cultivo_id'] ?? 0) === $cultivoId));
        $porBodega = [];
        foreach ($renglones as $r) {
            $porBodega[$r['bodega_id']][] = $r;
        }

        $bloques = [];
        foreach ($this->bodegasConDatos($semana['necesidades'], $cultivoId, array_keys($porBodega)) as $bodega) {
            $bloques[] = [mb_strtoupper($bodega['nombre']), $bodega['nombre'], $porBodega[$bodega['id']] ?? []];
        }

        // Total de todos los destinos: mismos renglones sumados por presentación, color, empaque y clase
        // (un sobrante nunca se junta con la venta de la misma llave).
        $juntos = [];
        foreach ($renglones as $r) {
            $llave = implode('|', [$r['clase'], $r['presentacion_id'], $r['grupo_id'], $r['tipo_empaque_id']]);
            $juntos[$llave] ??= ['cajas' => array_fill(0, 7, 0)] + $r;
            foreach ($r['cajas'] as $d => $valor) {
                $juntos[$llave]['cajas'][$d] += $valor;
            }
        }
        $bloques[] = ['TOTAL', '', array_values($juntos)];

        foreach ($bloques as [$titulo, $nombreBodega, $filas]) {
            $this->encabezado(['B' => $titulo, 'D' => 'PRESENT', 'E' => 'COLOR', 'F' => 'EMPAQUE'] + $this->fechasComoEncabezado($dias));

            // Ventas (ok) primero y después los sobrantes, cada grupo en el orden del catálogo.
            $clasificadas = [];
            foreach (['ok', 'sobrante'] as $clase) {
                $deClase = array_values(array_filter($filas, fn ($r) => $r['clase'] === $clase));
                usort($deClase, fn ($a, $b) => [
                    $ordenPresentacion[$a['presentacion_id']] ?? 0,
                    $ordenGrupo[$a['grupo_id']] ?? 0,
                    $ordenEmpaque[$a['tipo_empaque_id']] ?? 0,
                ] <=> [
                    $ordenPresentacion[$b['presentacion_id']] ?? 0,
                    $ordenGrupo[$b['grupo_id']] ?? 0,
                    $ordenEmpaque[$b['tipo_empaque_id']] ?? 0,
                ]);
                array_push($clasificadas, ...$deClase);
            }

            $total = array_fill(0, 7, 0);
            foreach ($clasificadas as $r) {
                $empaque = $this->tiposEmpaque[$r['tipo_empaque_id']]['nombre'] ?? '';
                $this->texto('D'.$this->fila, $this->presentaciones[$r['presentacion_id']]['nombre'] ?? '');
                $this->texto('E'.$this->fila, $nombreGrupo[$r['grupo_id']] ?? '');
                $this->texto('F'.$this->fila, $r['clase'] === 'sobrante' ? 'Sobrante'.($empaque !== '' ? ' · '.$empaque : '') : $empaque);
                $this->filaDeDias($r['cajas']);
                foreach ($r['cajas'] as $d => $valor) {
                    $total[$d] += $valor;
                }
                $this->fila++;
            }

            $sinClasificar = array_fill(0, 7, 0);
            foreach ($filas as $r) {
                if (! in_array($r['clase'], ['ok', 'sobrante'], true)) {
                    foreach ($r['cajas'] as $d => $valor) {
                        $sinClasificar[$d] += $valor;
                    }
                }
            }

            $this->texto('C'.$this->fila, 'Total empaque'.($nombreBodega !== '' ? ' '.$nombreBodega : ''));
            $this->filaDeDias($total);
            $this->negritas('C'.$this->fila.':'.$this->columnaTotal.$this->fila, true);
            $this->fila++;

            $this->texto('C'.$this->fila, 'Sin clasificar (debe ser 0)');
            $this->filaDeDias($sinClasificar);
            if (array_sum($sinClasificar) !== 0) {
                $this->hoja->getStyle('C'.$this->fila.':'.$this->columnaTotal.$this->fila)->getFont()->getColor()->setARGB('FFC00000');
                $this->negritas('C'.$this->fila.':'.$this->columnaTotal.$this->fila, true);
            }
            $this->fila += 2;
        }
    }

    /** 5. BALANCE — producción, necesidad (solo ventas), sobrante asignado, disponible del día e inventario acumulado, más el acumulado por grupo. */
    private function escribirBalance(array $semana, array $grupos, array $dias): void
    {
        $this->titulo(self::SECCIONES['balance']);
        $this->encabezado(['C' => 'CONCEPTO'] + $this->fechasComoEncabezado($dias));

        $produccion = $necesidad = $sobrante = $delDia = $acumulado = array_fill(0, 7, 0);
        $inicial = 0;
        $balances = [];
        foreach ($grupos as $g) {
            if (! isset($semana['balance'][$g['id']])) {
                continue;
            }
            $b = $semana['balance'][$g['id']];
            $balances[] = [$g['nombre'], $b];
            $inicial += $b['inicial'];
            foreach (range(0, 6) as $d) {
                $produccion[$d] += $b['produccion'][$d];
                $necesidad[$d] += $b['necesidad'][$d];
                $sobrante[$d] += $b['sobrante_asignado'][$d] ?? 0;
                $delDia[$d] += $b['del_dia'][$d];
                $acumulado[$d] += $b['acumulado'][$d];
            }
        }

        foreach (['Producción' => $produccion, 'Necesidad' => $necesidad, 'Sobrante asignado' => $sobrante, 'Disponible del día' => $delDia] as $concepto => $valores) {
            $this->texto('C'.$this->fila, $concepto);
            $this->filaDeDias($valores);
            $this->fila++;
        }

        $this->texto('C'.$this->fila, 'Inventario acumulado');
        $this->texto('D'.$this->fila, 'Inicial:');
        $this->cajas('E'.$this->fila, $inicial);
        $this->filaDeDias($acumulado, true, $acumulado[6]);
        $this->negritas('C'.$this->fila.':'.$this->columnaTotal.$this->fila, true);
        $this->fila++;

        // Una fila por grupo de balance (no está en el Excel).
        foreach ($balances as [$nombre, $b]) {
            $this->texto('C'.$this->fila, 'Acumulado '.$nombre);
            $this->texto('D'.$this->fila, 'Inicial:');
            $this->cajas('E'.$this->fila, $b['inicial']);
            $this->filaDeDias($b['acumulado'], true, $b['final']);
            $this->fila++;
        }
        $this->fila++;
    }

    /** 6. POR COLOR — producción, necesidad, disponible y cargas equivalentes de la semana. */
    private function escribirPorColor(array $semana, array $grupos, int $cajasPorCarga): void
    {
        $this->titulo(self::SECCIONES['color']);
        $this->encabezado(['C' => 'COLOR', 'D' => 'PRODUCCIÓN', 'E' => 'NECESIDAD', 'F' => 'SOBRANTE', 'G' => 'DISPONIBLE', 'H' => 'CARGAS EQ.']);
        $this->texto('I'.($this->fila - 1), 'Necesidad = solo ventas · Disponible = producción − necesidad − sobrante asignado · Cargas eq. = disponible ÷ cajas por carga ('.number_format($cajasPorCarga).')');
        $this->hoja->getStyle('I'.($this->fila - 1))->getFont()->setItalic(true);

        $total = ['produccion' => 0, 'necesidad' => 0, 'sobrante' => 0];
        foreach ($grupos as $g) {
            if (! isset($semana['balance'][$g['id']])) {
                continue;
            }
            $b = $semana['balance'][$g['id']];
            $sobrante = array_sum($b['sobrante_asignado'] ?? []);
            $this->filaDeColor($g['nombre'], array_sum($b['produccion']), array_sum($b['necesidad']), $sobrante, $cajasPorCarga);
            $total['produccion'] += array_sum($b['produccion']);
            $total['necesidad'] += array_sum($b['necesidad']);
            $total['sobrante'] += $sobrante;
        }

        $this->filaDeColor('Total', $total['produccion'], $total['necesidad'], $total['sobrante'], $cajasPorCarga);
        $this->negritas('C'.($this->fila - 1).':H'.($this->fila - 1), true);
        $this->fila++;
    }

    /** 7. EMBARQUES — número de cargas por destino, tipo (Full / Sencillo) y día. */
    private function escribirEmbarques(array $semana, ?int $cultivoId, array $dias): void
    {
        $this->titulo(self::SECCIONES['embarques']);
        $this->encabezado(['C' => 'DESTINO', 'D' => 'TIPO'] + $this->fechasComoEncabezado($dias));

        $cargas = [];
        foreach ($semana['embarques'] as $e) {
            if ((int) $e['cultivo_id'] === $cultivoId && isset(self::TIPOS_EMBARQUE[$e['tipo']])) {
                $cargas[$e['bodega_id']][$e['tipo']][$e['dia']] = ($cargas[$e['bodega_id']][$e['tipo']][$e['dia']] ?? 0) + $e['cargas'];
            }
        }

        $total = array_fill(0, 7, 0);
        foreach ($this->bodegasActivasOCon(array_keys($cargas)) as $bodega) {
            foreach (self::TIPOS_EMBARQUE as $tipo => $etiqueta) {
                $porDia = array_fill(0, 7, 0);
                foreach ($cargas[$bodega['id']][$tipo] ?? [] as $dia => $valor) {
                    if (isset($porDia[$dia])) {
                        $porDia[$dia] = $valor;
                    }
                }
                $this->texto('C'.$this->fila, mb_strtoupper($bodega['nombre']));
                $this->texto('D'.$this->fila, $etiqueta);
                $this->filaDeDias($porDia);
                foreach ($porDia as $d => $valor) {
                    $total[$d] += $valor;
                }
                $this->fila++;
            }
        }

        $this->texto('C'.$this->fila, 'Total embarques');
        $this->filaDeDias($total);
        $this->negritas('C'.$this->fila.':'.$this->columnaTotal.$this->fila, true);
        $this->fila += 2;
    }

    /** 8. NOTAS DE LA SEMANA — el texto libre del programa, en un bloque combinado. */
    private function escribirNotas(?string $notas): void
    {
        $this->titulo(self::SECCIONES['notas']);

        $rango = 'B'.$this->fila.':'.$this->columnaTotal.($this->fila + 3);
        $this->hoja->mergeCells($rango);
        $this->texto('B'.$this->fila, (string) $notas);
        $this->hoja->getStyle($rango)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $this->fila += 4;
    }

    // ── Agrupaciones sobre el payload ───────────────────

    /**
     * Rendimiento (cj/ha) que usa la semana para un color: el valor de sus
     * temporadas con cortes, o de todas si no hay cortes. Si las temporadas
     * usan valores distintos, «varios».
     *
     * @return float|string|null
     */
    private function rendimientoDe(array $semana, int $variedadId)
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
                $valores[(string) round($r['valor_usado'], 2)] = round($r['valor_usado'], 2);
            }
        }

        return match (count($valores)) {
            0 => null,
            1 => array_values($valores)[0],
            default => 'varios',
        };
    }

    /**
     * Destinos del catálogo con algún renglón de ese cultivo, más los activos
     * (un destino sin pedidos sale en cero, como en el Excel), en orden del catálogo.
     *
     * @param  list<int>  $tambien  Ids de destinos que se incluyen aunque estén inactivos.
     * @return list<array>
     */
    private function bodegasConDatos(array $necesidades, ?int $cultivoId, array $tambien = []): array
    {
        $ids = $tambien;
        foreach ($necesidades as $n) {
            if ((int) $n['cultivo_id'] === $cultivoId && array_sum($n['cajas']) !== 0) {
                $ids[] = (int) $n['bodega_id'];
            }
        }

        return $this->bodegasActivasOCon($ids);
    }

    /** @return list<array> */
    private function bodegasActivasOCon(array $ids): array
    {
        $ids = array_map('intval', $ids);

        return array_values(array_filter($this->bodegas, fn ($b) => (bool) $b['is_active'] || in_array((int) $b['id'], $ids, true)));
    }

    /** Color de un renglón de necesidad: la variedad, o el grupo de balance cuando son varias (Ama/Bi). */
    private function colorDe(array $variedadIds, int|string|null $grupoId, array $nombreVariedad, array $grupos): string
    {
        if (count($variedadIds) === 1) {
            return $nombreVariedad[$variedadIds[0]] ?? '';
        }
        foreach ($grupos as $g) {
            if ($g['id'] === $grupoId && array_diff($variedadIds, $g['variedad_ids']) === []) {
                return $g['nombre'];
            }
        }

        return implode(' / ', array_map(fn ($id) => $nombreVariedad[$id] ?? '', $variedadIds));
    }

    // ── Celdas y estilos ────────────────────────────────

    private function titulo(string $texto): void
    {
        $this->texto('B'.$this->fila, $texto);
        $rango = 'B'.$this->fila.':'.$this->columnaTotal.$this->fila;
        $estilo = $this->hoja->getStyle($rango);
        $estilo->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E3D');
        $this->fila++;
    }

    /** Fila de encabezado de tabla: letra de columna => texto (o fecha AAAA-MM-DD en las columnas de día). */
    private function encabezado(array $celdas): void
    {
        foreach ($celdas as $columna => $valor) {
            if (is_array($valor)) {
                $this->fecha($columna.$this->fila, $valor['fecha']);
            } else {
                $this->texto($columna.$this->fila, $valor);
            }
        }
        $rango = 'B'.$this->fila.':'.$this->columnaTotal.$this->fila;
        $estilo = $this->hoja->getStyle($rango);
        $estilo->getFont()->setBold(true);
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7EFEA');
        $estilo->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->hoja->getStyle('B'.$this->fila.':F'.$this->fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->fila++;
    }

    /** Encabezado de columnas de día con su fecha, más «TOTAL». */
    private function fechasComoEncabezado(array $dias): array
    {
        $celdas = [];
        foreach ($this->columnasDia as $i => $columna) {
            $celdas[$columna] = ['fecha' => $dias[$i]];
        }

        return $celdas + [$this->columnaTotal => 'TOTAL'];
    }

    /** Renglón de fechas de los días sobre el encabezado de «Campo» (en el Excel va arriba de L M X J V S D). */
    private function fechasDeDias(array $dias): void
    {
        foreach ($this->columnasDia as $i => $columna) {
            $this->fecha($columna.$this->fila, $dias[$i]);
        }
        $this->hoja->getStyle($this->columnasDia[0].$this->fila.':'.end($this->columnasDia).$this->fila)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->fila++;
    }

    /**
     * Un valor por columna de día (siete en G a M y el total en N; sin domingo, seis en G a L y el total en M). Si $enteros es false se escriben
     * tal cual (hectáreas, con decimales); `$total` fuerza el valor del total.
     *
     * @param  array<int, int|float>  $valores
     */
    private function filaDeDias(array $valores, bool $enteros = true, int|float|null $total = null): void
    {
        foreach ($this->columnasDia as $d => $columna) {
            $this->celdaNumerica($columna.$this->fila, $valores[$d] ?? 0, $enteros);
        }
        $this->celdaNumerica($this->columnaTotal.$this->fila, $total ?? array_sum($valores), $enteros);
    }

    private function filaDeColor(string $nombre, int $produccion, int $necesidad, int $sobrante, int $cajasPorCarga): void
    {
        $disponible = $produccion - $necesidad - $sobrante;
        $this->texto('C'.$this->fila, $nombre);
        $this->cajas('D'.$this->fila, $produccion);
        $this->cajas('E'.$this->fila, $necesidad);
        $this->cajas('F'.$this->fila, $sobrante);
        $this->cajas('G'.$this->fila, $disponible);
        $this->hoja->setCellValue('H'.$this->fila, round($disponible / max(1, $cajasPorCarga), 2));
        $this->hoja->getStyle('H'.$this->fila)->getNumberFormat()->setFormatCode('0.00;[Red]-0.00');
        $this->fila++;
    }

    private function celdaNumerica(string $celda, int|float $valor, bool $enteros): void
    {
        $this->hoja->setCellValue($celda, $enteros ? (int) $valor : round((float) $valor, 4));
        if ($enteros) {
            $this->hoja->getStyle($celda)->getNumberFormat()->setFormatCode(self::FORMATO_CAJAS);
        }
    }

    private function cajas(string $celda, int $valor): void
    {
        $this->celdaNumerica($celda, $valor, true);
    }

    /** Texto como texto: un cliente que empiece con «=» no se vuelve fórmula. */
    private function texto(string $celda, string $valor): void
    {
        $this->hoja->setCellValueExplicit($celda, $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }

    private function fecha(string $celda, string $fecha): void
    {
        $this->hoja->setCellValue($celda, Date::stringToExcel($fecha));
        $this->hoja->getStyle($celda)->getNumberFormat()->setFormatCode(self::FORMATO_FECHA);
    }

    private function negritas(string $rango, bool $negritas): void
    {
        $estilo = $this->hoja->getStyle($rango);
        $estilo->getFont()->setBold($negritas);
        $estilo->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
    }

    private function resaltar(string $rango): void
    {
        $estilo = $this->hoja->getStyle($rango);
        $estilo->getFont()->setBold(true);
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF6E7B4');
        $estilo->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
    }
}
