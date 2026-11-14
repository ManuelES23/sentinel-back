<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Variedad;
use App\Models\Enterprise;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel del tablero de Abastecimiento (spec §5.1 (Exportar), Ruling 9): las mismas cifras de `GET tablero`
 * en cuatro hojas (Resumen, Convenios, Semanas y Avisos), de UNA temporada y UNA moneda.
 *
 * Solo valores, nunca fórmulas: los textos del usuario (productor, folio…) se escriben SIEMPRE como
 * texto explícito y, si empiezan con `=`, `+`, `-` o `@`, además con el prefijo de comilla de Excel,
 * así que nunca se interpretan como fórmula. Los números van como números y las fechas como fechas.
 *
 * El número de consultas no depende de los convenios: lo que `TableroAbastecimiento::armar` ya
 * trajo se reutiliza y lo que falta (metas y recibido por semana, cultivo, por pagar) se lee en lote.
 */
class ExcelTablero
{
    private const FMT_DINERO = '#,##0.00';

    private const FMT_CANTIDAD = '#,##0.##';

    private const FMT_PCT = '0.0%';

    private const FMT_FECHA = 'dd/mm/yyyy';

    private const RELLENO_ENCABEZADO = 'E5E7EB';

    private const RELLENO_ACTUAL = 'FEF3C7';

    private const ESTADOS = [
        'atrasado' => 'Atrasado', 'riesgo' => 'En riesgo', 'en_meta' => 'En meta', 'adelantado' => 'Adelantado',
        'por_iniciar' => 'Por iniciar', 'finalizado' => 'Finalizado', 'cancelado' => 'Cancelado',
    ];

    private const MODALIDADES = ['compra_directa' => 'Compra directa', 'consignacion' => 'Consignación'];

    private const UNIDADES = ['caja' => 'Cajas', 'kg' => 'Kg'];

    private const AVISOS = [
        'atrasado' => 'Atrasado', 'inicia' => 'Inicia la semana siguiente', 'liquidaciones_en_revision' => 'Liquidaciones en revisión',
    ];

    /**
     * @param  array  $tablero  el arreglo de `TableroAbastecimiento::armar`
     * @param  bool  $verDinero  incluye la columna «Por pagar» (permiso `liquidaciones.view`)
     * @param  string|null  $temporada  nombre de la temporada, para el resumen
     * @return string ruta de un archivo temporal `.xlsx` (quien lo entrega lo borra)
     */
    public function generar(Enterprise $empresa, array $tablero, bool $verDinero, ?string $temporada = null): string
    {
        $convenios = $tablero['convenios'] ?? [];
        $moneda = $tablero['moneda'] ?? null;
        $ids = array_map(fn (array $c) => (int) $c['id'], $convenios);

        $libro = new Spreadsheet();
        $this->resumen($libro->getActiveSheet()->setTitle('Resumen'), $empresa, $tablero, $temporada);

        $indicadores = $this->indicadoresPorSemana($empresa, $ids);
        $cultivos = $this->cultivos($convenios);
        $porPagar = $verDinero ? SaldoProductor::porPagarDeConvenios($empresa, $ids) : [];

        $this->hojaConvenios($libro->createSheet()->setTitle('Convenios'), $convenios, $cultivos, $porPagar, $verDinero, $moneda);
        $this->hojaSemanas($libro->createSheet()->setTitle('Semanas'), $convenios, $indicadores, $tablero['semana']['lunes'] ?? '');
        $this->hojaAvisos($libro->createSheet()->setTitle('Avisos'), $tablero['avisos'] ?? [], $convenios);
        $libro->setActiveSheetIndex(0);

        $ruta = tempnam(sys_get_temp_dir(), 'tablero-abastecimiento');
        unlink($ruta);
        $ruta .= '.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }

    // ---- Hojas ----

    private function resumen(Worksheet $hoja, Enterprise $empresa, array $tablero, ?string $temporada): void
    {
        $moneda = $tablero['moneda'] ?? null;
        $kpis = $tablero['kpis'] ?? [];
        $sufijo = $moneda === null ? '' : " ({$moneda})";

        $this->texto($hoja, 1, 1, 'Tablero de abastecimiento');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $this->texto($hoja, 1, 3, 'Empresa');
        $this->texto($hoja, 2, 3, $empresa->name);
        $this->texto($hoja, 1, 4, 'Temporada');
        $this->texto($hoja, 2, 4, $temporada);
        $this->texto($hoja, 1, 5, 'Moneda');
        $this->texto($hoja, 2, 5, $moneda);
        $this->texto($hoja, 1, 6, 'Semana actual');
        $this->numero($hoja, 2, 6, $tablero['semana']['numero'] ?? null, '0');
        $this->texto($hoja, 1, 7, 'Lunes de la semana actual');
        $this->fecha($hoja, 2, 7, $tablero['semana']['lunes'] ?? null);
        $hoja->getStyle('A3:A7')->getFont()->setBold(true);
        $hoja->getStyle('B3:B7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $this->encabezado($hoja, 9, ['Indicador', 'Valor']);
        $filas = [
            ['Cumplimiento', $kpis['cumplimiento'] ?? null, self::FMT_PCT],
            ["Comprometido{$sufijo}", $kpis['comprometido'] ?? null, self::FMT_DINERO],
            ["Valor recibido{$sufijo}", $kpis['valor_recibido'] ?? null, self::FMT_DINERO],
            ["Sin liquidar{$sufijo}", $kpis['sin_liquidar'] ?? null, self::FMT_DINERO],
            ["Saldo por pagar{$sufijo}", $kpis['saldo_por_pagar'] ?? null, self::FMT_DINERO],
            ['Convenios abajo de meta', $kpis['abajo_de_meta'] ?? null, '0'],
            ['Convenios con avance', $kpis['con_avance'] ?? null, '0'],
        ];
        foreach ($filas as $i => [$etiqueta, $valor, $formato]) {
            $this->texto($hoja, 1, 10 + $i, $etiqueta);
            $this->numero($hoja, 2, 10 + $i, $valor, $formato);
        }

        $this->anchos($hoja, [34, 28]);
    }

    private function hojaConvenios(Worksheet $hoja, array $convenios, array $cultivos, array $porPagar, bool $verDinero, ?string $moneda): void
    {
        $sufijo = $moneda === null ? '' : " ({$moneda})";
        $titulos = [
            'Folio', 'Productor', 'Cultivo', 'Variedad', 'Modalidad', 'Unidad', 'Inicio', 'Fin',
            'Pactado', 'Recibido', 'Avance', 'Meta a la fecha', 'Cumplimiento', 'Proyección', 'Estado',
        ];
        if ($verDinero) {
            $titulos[] = "Por pagar{$sufijo}";
        }
        $this->encabezado($hoja, 1, $titulos);

        foreach ($convenios as $i => $c) {
            $f = $i + 2;
            $ind = $c['indicadores'];
            $this->texto($hoja, 1, $f, $c['folio'] ?? null);
            $this->texto($hoja, 2, $f, $c['productor']['nombre'] ?? null);
            $this->texto($hoja, 3, $f, $cultivos[$c['variedad']['id'] ?? 0]['cultivo'] ?? null);
            $this->texto($hoja, 4, $f, $c['variedad']['nombre'] ?? null);
            $this->texto($hoja, 5, $f, self::MODALIDADES[$c['modalidad']] ?? $c['modalidad']);
            $this->texto($hoja, 6, $f, self::UNIDADES[$c['unidad']] ?? $c['unidad']);
            $this->fecha($hoja, 7, $f, $c['fecha_inicio'] ?? null);
            $this->fecha($hoja, 8, $f, $c['fecha_fin'] ?? null);
            $this->numero($hoja, 9, $f, $ind['pactado'], self::FMT_CANTIDAD);
            $this->numero($hoja, 10, $f, $ind['recibido'], self::FMT_CANTIDAD);
            $this->numero($hoja, 11, $f, $ind['avance'], self::FMT_PCT);
            $this->numero($hoja, 12, $f, $ind['meta_a_la_fecha'], self::FMT_CANTIDAD);
            $this->numero($hoja, 13, $f, $ind['cumplimiento'], self::FMT_PCT);
            $this->numero($hoja, 14, $f, $ind['proyeccion'], self::FMT_CANTIDAD);
            $this->texto($hoja, 15, $f, self::ESTADOS[$ind['estado']] ?? $ind['estado']);
            if ($verDinero) {
                $this->numero($hoja, 16, $f, $porPagar[(int) $c['id']] ?? 0.0, self::FMT_DINERO);
            }
        }

        $this->anchos($hoja, array_slice([12, 32, 14, 18, 17, 9, 12, 12, 12, 12, 10, 15, 14, 12, 14, 18], 0, count($titulos)));
        $this->congelar($hoja, count($titulos), count($convenios));
    }

    private function hojaSemanas(Worksheet $hoja, array $convenios, array $indicadores, string $lunesActual): void
    {
        $this->encabezado($hoja, 1, ['Folio', 'Productor', 'Unidad', 'Semana', 'Lunes', 'Meta', 'Recibido', '% de la meta', 'Semana actual']);

        $f = 2;
        foreach ($convenios as $c) {
            foreach ($indicadores[(int) $c['id']] ?? [] as $s) {
                $actual = $s['lunes'] === $lunesActual;
                $this->texto($hoja, 1, $f, $c['folio'] ?? null);
                $this->texto($hoja, 2, $f, $c['productor']['nombre'] ?? null);
                $this->texto($hoja, 3, $f, self::UNIDADES[$c['unidad']] ?? $c['unidad']);
                $this->numero($hoja, 4, $f, $s['numero'], '0');
                $this->fecha($hoja, 5, $f, $s['lunes']);
                $this->numero($hoja, 6, $f, $s['meta'], self::FMT_CANTIDAD);
                $this->numero($hoja, 7, $f, $s['recibido'], self::FMT_CANTIDAD);
                $this->numero($hoja, 8, $f, $s['meta'] > 0 ? round($s['recibido'] / $s['meta'], 4) : null, self::FMT_PCT);
                $this->texto($hoja, 9, $f, $actual ? 'Sí' : '');
                if ($actual) {
                    $hoja->getStyle("A{$f}:I{$f}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::RELLENO_ACTUAL);
                }
                $f++;
            }
        }

        $this->anchos($hoja, [12, 32, 9, 9, 12, 12, 12, 13, 14]);
        $this->congelar($hoja, 9, $f - 2);
    }

    private function hojaAvisos(Worksheet $hoja, array $avisos, array $convenios): void
    {
        $this->encabezado($hoja, 1, ['Tipo', 'Convenio', 'Aviso']);
        $folios = [];
        foreach ($convenios as $c) {
            $folios[(int) $c['id']] = $c['folio'] ?? null;
        }

        foreach ($avisos as $i => $a) {
            $this->texto($hoja, 1, $i + 2, self::AVISOS[$a['tipo']] ?? $a['tipo']);
            $this->texto($hoja, 2, $i + 2, $a['convenio_id'] === null ? null : ($folios[(int) $a['convenio_id']] ?? null));
            $this->texto($hoja, 3, $i + 2, $a['texto'] ?? null);
        }

        $this->anchos($hoja, [26, 12, 110]);
        $this->congelar($hoja, 3, count($avisos));
    }

    // ---- Datos en lote ----

    /**
     * Semanas (meta y recibido) de cada convenio, con los mismos indicadores del tablero: una consulta de
     * convenios con precios y metas y una de recepciones agrupadas, sin importar cuántos convenios haya.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array{lunes: string, numero: int, meta: float, recibido: float, cerrada: bool}>>
     */
    private function indicadoresPorSemana(Enterprise $empresa, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $convenios = Convenio::deEmpresa($empresa->id)->whereIn('id', $ids)->with(['precios', 'metas'])->get();
        // Calibres de los convenios por calibre en una consulta por relación (sin esto, `calibresDe` consulta por convenio).
        CalibresConvenio::cargar($convenios);
        $lineas = IndicadoresConvenio::lineas($convenios);
        $hoy = Semanas::hoy();

        $semanas = [];
        foreach ($convenios as $convenio) {
            $semanas[$convenio->id] = IndicadoresConvenio::de($convenio, $hoy, $lineas->get($convenio->id, collect()))['semanas'];
        }

        return $semanas;
    }

    /**
     * Cultivo de cada variedad (el tablero solo trae la variedad): dos consultas.
     *
     * @return array<int, array{cultivo: string|null}>
     */
    private function cultivos(array $convenios): array
    {
        $variedadIds = array_values(array_unique(array_filter(array_map(fn (array $c) => $c['variedad']['id'] ?? null, $convenios))));
        if ($variedadIds === []) {
            return [];
        }

        $salida = [];
        foreach (Variedad::withTrashed()->whereIn('id', $variedadIds)->with(['cultivo' => fn ($q) => $q->withTrashed()->select('id', 'nombre')])->get() as $v) {
            $salida[$v->id] = ['cultivo' => $v->cultivo?->nombre];
        }

        return $salida;
    }

    // ---- Celdas ----

    /** Texto del usuario: siempre como cadena (nunca fórmula), con comilla de Excel si parece una. */
    private function texto(Worksheet $hoja, int $columna, int $fila, mixed $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $valor = (string) $valor;
        $hoja->setCellValueExplicit([$columna, $fila], $valor, DataType::TYPE_STRING);
        if (preg_match('/^[=+\-@\t\r]/', $valor) === 1) {
            $hoja->getStyle([$columna, $fila])->setQuotePrefix(true);
        }
    }

    /** Número como número; sin valor, la celda queda vacía. */
    private function numero(Worksheet $hoja, int $columna, int $fila, int|float|null $valor, string $formato): void
    {
        if ($valor === null) {
            return;
        }

        $hoja->setCellValueExplicit([$columna, $fila], $valor, DataType::TYPE_NUMERIC);
        $hoja->getStyle([$columna, $fila])->getNumberFormat()->setFormatCode($formato);
        $hoja->getStyle([$columna, $fila])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /** Fecha `Y-m-d` como fecha de Excel. */
    private function fecha(Worksheet $hoja, int $columna, int $fila, ?string $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $serie = FechaExcel::PHPToExcel(new DateTimeImmutable(substr($valor, 0, 10).' 00:00:00', new DateTimeZone('UTC')));
        $hoja->setCellValueExplicit([$columna, $fila], $serie, DataType::TYPE_NUMERIC);
        $hoja->getStyle([$columna, $fila])->getNumberFormat()->setFormatCode(self::FMT_FECHA);
        $hoja->getStyle([$columna, $fila])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function encabezado(Worksheet $hoja, int $fila, array $titulos): void
    {
        foreach ($titulos as $i => $titulo) {
            $hoja->setCellValueExplicit([$i + 1, $fila], $titulo, DataType::TYPE_STRING);
        }
        $rango = $hoja->getStyle([1, $fila, count($titulos), $fila]);
        $rango->getFont()->setBold(true);
        $rango->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::RELLENO_ENCABEZADO);
        $rango->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    private function anchos(Worksheet $hoja, array $anchos): void
    {
        foreach ($anchos as $i => $ancho) {
            $hoja->getColumnDimensionByColumn($i + 1)->setWidth($ancho);
        }
    }

    /** Congela el encabezado y pone filtro si hay renglones. */
    private function congelar(Worksheet $hoja, int $columnas, int $renglones): void
    {
        $hoja->freezePane('A2');
        if ($renglones > 0) {
            $hoja->setAutoFilter('A1:'.Coordinate::stringFromColumnIndex($columnas).($renglones + 1));
        }
    }
}
