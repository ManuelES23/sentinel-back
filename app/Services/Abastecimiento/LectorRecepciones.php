<?php

namespace App\Services\Abastecimiento;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Lee el Excel (o CSV) de recepciones de Abastecimiento y arma la plantilla para descargar.
 * Solo lee: no valida el negocio (convenio, precio, cantidades) ni guarda nada; todos los
 * valores salen como texto (o null) para que el validador decida y reporte por renglón.
 */
class LectorRecepciones
{
    public const MAX_FILAS = 2000;

    public const ENCABEZADOS = ['Folio del convenio', 'Fecha', 'Tipo de carga', 'Cantidad', 'Kilos', 'Rezaga kg', 'Ticket'];

    private const HOJA_DATOS = 'Recepciones';

    private const HOJA_AYUDA = 'Instrucciones';

    /** Campo => [encabezado de la plantilla, alias normalizados, obligatorio]. */
    private const COLUMNAS = [
        'convenio_folio' => ['Folio del convenio', ['folio del convenio', 'folio convenio', 'convenio', 'folio'], true],
        'fecha' => ['Fecha', ['fecha', 'fecha de recepcion', 'fecha recepcion'], true],
        'tipo_carga' => ['Tipo de carga', ['tipo de carga', 'tipo carga', 'tipo'], true],
        'cantidad' => ['Cantidad', ['cantidad', 'cajas'], true],
        'kilos' => ['Kilos', ['kilos', 'kg', 'peso kg'], true],
        'rezaga_kg' => ['Rezaga kg', ['rezaga kg', 'rezaga', 'rezaga (kg)'], false],
        'ticket' => ['Ticket', ['ticket', 'no. de ticket', 'numero de ticket'], false],
    ];

    /**
     * @return list<array{fila: int, convenio_folio: ?string, fecha: ?string, tipo_carga: ?string, cantidad: ?string, kilos: ?string, rezaga_kg: ?string, ticket: ?string}>
     *         `fila` es el número real del renglón en el Excel. Una fecha ilegible queda `null`
     *         y el renglón trae además `fecha_original` con el texto que venía.
     */
    public function leer(UploadedFile $archivo): array
    {
        $hoja = $this->hojaDeDatos($this->abrir($archivo));
        [$columnas, $filaEncabezado] = $this->columnas($hoja);

        $faltan = [];
        foreach (self::COLUMNAS as $campo => [$titulo, , $obligatoria]) {
            if ($obligatoria && ! isset($columnas[$campo])) {
                $faltan[] = $titulo;
            }
        }
        if ($faltan !== []) {
            throw $this->error('Faltan columnas obligatorias en la primera hoja: '.implode(', ', $faltan).'. Descarga la plantilla y respeta sus encabezados.');
        }

        $filas = [];
        for ($numero = $filaEncabezado + 1; $numero <= $hoja->getHighestDataRow(); $numero++) {
            $crudo = [];
            foreach (array_keys(self::COLUMNAS) as $campo) {
                $crudo[$campo] = isset($columnas[$campo]) ? $this->valor($hoja->getCell($columnas[$campo].$numero)) : null;
            }
            if (collect($crudo)->every(fn ($v) => $this->texto($v) === null)) {
                continue;
            }
            if (count($filas) >= self::MAX_FILAS) {
                throw $this->error('El archivo tiene más de '.self::MAX_FILAS.' renglones con datos. Divídelo en partes.');
            }

            $fecha = $this->fecha($crudo['fecha']);
            $fila = ['fila' => $numero];
            foreach (array_keys(self::COLUMNAS) as $campo) {
                $fila[$campo] = $campo === 'fecha' ? $fecha : $this->texto($crudo[$campo]);
            }
            if ($fecha === null && ($original = $this->texto($crudo['fecha'])) !== null) {
                $fila['fecha_original'] = $original;
            }
            $filas[] = $fila;
        }

        if ($filas === []) {
            throw $this->error('El archivo no tiene renglones con datos debajo de los encabezados.');
        }

        return $filas;
    }

    /** Ruta temporal de un .xlsx con la plantilla; quien lo descarga lo borra después. */
    public function plantilla(): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle(self::HOJA_DATOS);

        foreach (self::ENCABEZADOS as $i => $titulo) {
            $hoja->setCellValue([$i + 1, 1], $titulo);
            $hoja->getColumnDimensionByColumn($i + 1)->setWidth(max(14, mb_strlen($titulo) + 4));
        }
        $encabezado = $hoja->getStyle('A1:G1');
        $encabezado->getFont()->setBold(true);
        $encabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');

        $hoja->setCellValue('A2', 'CV-0001');
        $hoja->setCellValue('B2', (int) FechaExcel::PHPToExcel(new \DateTime('2026-07-14')));
        $hoja->getStyle('B2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $hoja->getStyle('B2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $hoja->setCellValue('C2', 'Caja empacada');
        $hoja->setCellValue('D2', 120);
        $hoja->setCellValue('E2', 1450.5);
        $hoja->setCellValue('F2', 35);
        $hoja->setCellValueExplicit('G2', 'T-0001', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->getStyle('D2:F2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_GENERAL);
        $hoja->freezePane('A2');

        $ayuda = $libro->createSheet()->setTitle(self::HOJA_AYUDA);
        $lineas = [
            'Cómo llenar la plantilla de recepciones',
            '',
            'Un renglón por recepción. Borra el renglón de ejemplo antes de subir el archivo.',
            'Folio del convenio (obligatorio): el folio del convenio activo, por ejemplo CV-0001.',
            'Fecha (obligatorio): día de la recepción, como AAAA-MM-DD o DD/MM/AAAA. No puede ser futura.',
            'Tipo de carga (obligatorio): el nombre del tipo de carga, tal como está en el catálogo.',
            'Cantidad (obligatorio): número entero mayor que cero (cajas, o la unidad del convenio).',
            'Kilos (obligatorio): kilos recibidos con hasta 2 decimales; en convenios por kilo no puede ir vacío.',
            'Rezaga kg (opcional): kilos de rezaga, con hasta 2 decimales; no puede pasar de los kilos.',
            'Ticket (opcional): número del ticket de báscula u otra referencia.',
            '',
            'Máximo '.self::MAX_FILAS.' renglones por archivo. El sistema te mostrará los errores renglón por renglón antes de guardar.',
        ];
        foreach ($lineas as $i => $linea) {
            $ayuda->setCellValue([1, $i + 1], $linea);
        }
        $ayuda->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ayuda->getColumnDimension('A')->setWidth(110);
        $libro->setActiveSheetIndex(0);

        $ruta = tempnam(sys_get_temp_dir(), 'plantilla-recepciones');
        unlink($ruta);
        $ruta .= '.xlsx';
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }

    private function abrir(UploadedFile $archivo): Spreadsheet
    {
        try {
            $lector = IOFactory::createReaderForFile($archivo->getRealPath());
            if ($lector instanceof Csv) {
                $lector->setInputEncoding(Csv::GUESS_ENCODING);
            }
            $lector->setReadDataOnly(true);

            return $lector->load($archivo->getRealPath());
        } catch (Throwable) {
            throw $this->error('No se pudo leer el archivo. Sube un Excel (.xlsx, .xls) o un CSV.');
        }
    }

    private function hojaDeDatos(Spreadsheet $libro): Worksheet
    {
        foreach ($libro->getWorksheetIterator() as $hoja) {
            if (self::normalizar($hoja->getTitle()) === 'recepciones') {
                return $hoja;
            }
        }

        return $libro->getSheet(0);
    }

    /** @return array{0: array<string, string>, 1: int} letra de columna por campo y número de la fila de encabezados */
    private function columnas(Worksheet $hoja): array
    {
        // El encabezado está en una de las primeras 5 filas; gana la que reconoce más columnas.
        $mejor = [[], 1];
        for ($fila = 1; $fila <= min(5, $hoja->getHighestDataRow()); $fila++) {
            $encontradas = [];
            foreach ($hoja->getRowIterator($fila, $fila)->current()->getCellIterator() as $celda) {
                $titulo = self::normalizar((string) $this->valor($celda));
                foreach (self::COLUMNAS as $campo => [, $alias]) {
                    if (! isset($encontradas[$campo]) && in_array($titulo, $alias, true)) {
                        $encontradas[$campo] = $celda->getColumn();
                    }
                }
            }
            if (count($encontradas) > count($mejor[0])) {
                $mejor = [$encontradas, $fila];
            }
        }

        return $mejor;
    }

    /** Minúsculas, sin acentos y con los espacios colapsados. */
    private static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($texto))));
    }

    /** Valor de la celda; en fórmulas, el que Excel dejó calculado. */
    private function valor(Cell $celda): mixed
    {
        $valor = $celda->getValue();
        if (is_string($valor) && str_starts_with($valor, '=')) {
            return $celda->getOldCalculatedValue();
        }

        return $valor;
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null || is_bool($valor)) {
            return null;
        }
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }
        if (is_float($valor)) {
            $texto = (string) $valor;
            if (str_contains($texto, 'E')) {
                $texto = rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.');
            }

            return $texto;
        }
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /** Fecha de Excel (número de serie), objeto o texto (2026-07-14, 14/07/2026, 14-07-2026) → Y-m-d, o null si no se entiende. */
    private function fecha(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }
        if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric(trim($valor)))) {
            $serie = (float) $valor;

            return $serie >= 20000 && $serie < 80000 ? FechaExcel::excelToDateTimeObject($serie)->format('Y-m-d') : null;
        }

        $texto = $this->texto($valor);
        if ($texto === null) {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $formato) {
            $fecha = \DateTime::createFromFormat('!'.$formato, $texto);
            if ($fecha && $fecha->format($formato) === $texto) {
                return $fecha->format('Y-m-d');
            }
        }

        return null;
    }

    private function error(string $mensaje): ValidationException
    {
        return ValidationException::withMessages(['archivo' => $mensaje]);
    }
}
