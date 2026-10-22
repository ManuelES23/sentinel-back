<?php

namespace App\Services\Reportes;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reporte «Recepciones vs. Embarques — Rezaga»: une cada folio de recepción
 * de empaque con los manifiestos que embarcaron su fruta y genera un Excel
 * con fórmulas (rezaga prorrateada por manifiesto en el detalle y rezaga por
 * folio en las pestañas «Rezaga {Empaque}»).
 *
 * Atribución pallet → folio: igual que el export «EmbarquesDetalle» de
 * Embarques y que ReporteEmpaqueController::produccionPorRecepcion(). Un
 * pallet sin desglose va al folio de su proceso; uno con desglose
 * (produccion_empaque_detalles) se reparte por detalle, cada uno con su
 * propio folio, y la báscula del pallet se prorratea por cajas.
 */
class RezagaRecepcionesService
{
    public const ROJO = 'C00000';
    public const GRIS_NOTA = '595959';
    public const AZUL_TITULO = '1F4E78';
    public const RELLENO_TOTAL = 'FFF2CC';
    public const VERDE_REFERENCIA = '008000';

    private const FMT_KG = '#,##0.00';
    private const FMT_BASCULA = '#,##0.00;-#,##0.00;"Sin captura"';
    private const FMT_ENTERO = '#,##0';
    private const FMT_PCT = '0.0%';
    private const FMT_FECHA = 'dd/mm/yyyy';

    /** Hojas fijas: ningún empaque puede llamarse igual. */
    private const HOJAS_RESERVADAS = ['resumen', 'consolidado'];

    /**
     * Motor de cálculo del libro en curso. Las fórmulas se escriben como
     * fórmulas, pero su valor se calcula aquí (en PHP) y se deja en la caché
     * del motor: así setPreCalculateFormulas(true) guarda los valores sin
     * evaluar cada COUNTIFS/SUMIFS, que en PhpSpreadsheet crece
     * cuadráticamente con las filas (≈30 s con 230 filas en Consolidado).
     * La prueba recalcula el archivo con el motor y compara.
     */
    private ?\PhpOffice\PhpSpreadsheet\Calculation\Calculation $motor = null;

    /**
     * Columnas de las pestañas de detalle: [encabezado, formato, ancho].
     */
    private const COLUMNAS_DETALLE = [
        'A' => ['Empaque', null, 14],
        'B' => ['Folio Recepción', '@', 17],
        'C' => ['Fecha Recepción', self::FMT_FECHA, 14],
        'D' => ['Productor', null, 22],
        'E' => ['Lote', null, 18],
        'F' => ['Peso Recibido (kg)', self::FMT_KG, 16],
        'G' => ['Peso Báscula (kg)', self::FMT_BASCULA, 16],
        'H' => ['No. Manifiestos', self::FMT_ENTERO, 12],
        'I' => ['Total Embarcado (kg)', self::FMT_KG, 18],
        'J' => ['Rezaga (kg)', self::FMT_KG, 14],
        'K' => ['Rezaga (%)', self::FMT_PCT, 11],
        'L' => ['Manifiesto', null, 15],
        'M' => ['Folio Embarque', null, 15],
        'N' => ['Fecha Embarque', self::FMT_FECHA, 14],
        'O' => ['Pallets', self::FMT_ENTERO, 9],
        'P' => ['Cajas', self::FMT_ENTERO, 9],
        'Q' => ['Peso Manifiesto (kg)', self::FMT_KG, 18],
        'R' => ['Báscula Embarque (kg)', self::FMT_KG, 18],
        'S' => ['Primera Fila Folio', '0', 11],
        'T' => ['Peso Recibido Prorrateado (kg)', self::FMT_KG, 18],
    ];

    private const COLUMNAS_RESUMEN = [
        'A' => ['Empaque', null, 16],
        'B' => ['Folios Recepción', self::FMT_ENTERO, 12],
        'C' => ['Folios con báscula', self::FMT_ENTERO, 12],
        'D' => ['Folios sin embarque', self::FMT_ENTERO, 12],
        'E' => ['Peso Recibido (kg)', self::FMT_KG, 16],
        'F' => ['Peso Báscula (kg)', self::FMT_KG, 16],
        'G' => ['Total Embarcado (kg)', self::FMT_KG, 18],
        'H' => ['% Embarcado vs Recibido', self::FMT_PCT, 14],
        'I' => ['Rezaga (kg)', self::FMT_KG, 16],
        'J' => ['Rezaga (%)', self::FMT_PCT, 11],
    ];

    /**
     * Filas ya unidas (folio × manifiesto), agrupadas por empaque y en el
     * orden de las pestañas. Solo trae los empaques con recepciones.
     *
     * @param  int[]  $empaques  ids de entities (plantas); vacío = todas
     * @return array{empaques: array<int, array{id:int, nombre:string, hoja:string, tabla:string, filas:array}>, filtros: array}
     */
    public function obtenerDatos(array $empaques, ?string $desde, ?string $hasta, ?int $temporadaId = null): array
    {
        $recepciones = $this->consultaRecepciones($empaques, $desde, $hasta, $temporadaId)->get();

        $embarquesPorRecepcion = $recepciones->isEmpty()
            ? collect()
            : $this->consultaEmbarques($recepciones->pluck('id')->all())->get()->groupBy('recepcion_id');

        $nombresUsados = self::HOJAS_RESERVADAS;
        $resultado = [];

        foreach ($recepciones->groupBy('entity_id') as $entityId => $deEmpaque) {
            $nombre = (string) $deEmpaque->first()->empaque;
            $hoja = $this->nombreHoja($nombre, (int) $entityId, $nombresUsados);
            $nombresUsados[] = mb_strtolower($hoja);

            $filas = [];
            foreach ($deEmpaque as $recepcion) {
                $base = [
                    'empaque' => $hoja,
                    'folio_recepcion' => (string) $recepcion->folio_recepcion,
                    'fecha_recepcion' => Carbon::parse($recepcion->fecha_recepcion)->format('Y-m-d'),
                    'productor' => trim(($recepcion->productor_nombre ?? '').' '.($recepcion->productor_apellido ?? '')),
                    'lote' => (string) ($recepcion->lote_nombre ?: ($recepcion->lote_numero ?? '')),
                    'peso_recibido' => round((float) $recepcion->peso_recibido_kg, 2),
                    'peso_bascula' => round((float) ($recepcion->peso_bascula ?? 0), 2),
                ];

                $embarques = $embarquesPorRecepcion->get($recepcion->id, collect())
                    ->sortBy([['fecha_embarque', 'asc'], ['manifiesto', 'asc']])
                    ->values();

                if ($embarques->isEmpty()) {
                    $filas[] = $base + [
                        'manifiesto' => null,
                        'folio_embarque' => null,
                        'fecha_embarque' => null,
                        'pallets' => 0,
                        'cajas' => 0,
                        'peso_manifiesto' => 0.0,
                        'bascula_embarque' => 0.0,
                        'primera_fila' => 1,
                    ];

                    continue;
                }

                foreach ($embarques as $i => $embarque) {
                    $filas[] = $base + [
                        'manifiesto' => (string) $embarque->manifiesto,
                        'folio_embarque' => (string) $embarque->folio_embarque,
                        'fecha_embarque' => $embarque->fecha_embarque
                            ? Carbon::parse($embarque->fecha_embarque)->format('Y-m-d')
                            : null,
                        'pallets' => (int) $embarque->pallets,
                        'cajas' => (int) $embarque->cajas,
                        'peso_manifiesto' => round((float) $embarque->peso_kg, 2),
                        'bascula_embarque' => round((float) $embarque->bascula_kg, 2),
                        'primera_fila' => $i === 0 ? 1 : 0,
                    ];
                }
            }

            $resultado[] = [
                'id' => (int) $entityId,
                'nombre' => $nombre,
                'hoja' => $hoja,
                'tabla' => $this->nombreTabla($hoja),
                'filas' => $filas,
            ];
        }

        usort($resultado, fn ($a, $b) => strcasecmp($a['hoja'], $b['hoja']));

        return [
            'empaques' => $resultado,
            'filtros' => [
                'temporada_id' => $temporadaId,
                'desde' => $desde,
                'hasta' => $hasta,
            ],
        ];
    }

    /**
     * Empaques (plantas) que tienen recepciones en la temporada, con el
     * nombre que tendrá su pestaña.
     */
    public function empaquesDisponibles(?int $temporadaId = null): array
    {
        $query = DB::table('recepciones_empaque')
            ->join('entities', 'entities.id', '=', 'recepciones_empaque.entity_id')
            ->whereNull('recepciones_empaque.deleted_at')
            ->whereNotNull('recepciones_empaque.folio_recepcion')
            ->where('recepciones_empaque.folio_recepcion', '<>', '')
            ->when($temporadaId, fn ($q) => $q->where('recepciones_empaque.temporada_id', $temporadaId))
            ->groupBy('entities.id', 'entities.name')
            ->selectRaw('entities.id, entities.name, COUNT(*) as folios')
            ->orderBy('entities.name');

        $usados = self::HOJAS_RESERVADAS;

        return $query->get()->map(function ($fila) use (&$usados) {
            $hoja = $this->nombreHoja((string) $fila->name, (int) $fila->id, $usados);
            $usados[] = mb_strtolower($hoja);

            return [
                'id' => (int) $fila->id,
                'nombre' => (string) $fila->name,
                'hoja' => $hoja,
                'folios' => (int) $fila->folios,
            ];
        })->sortBy('hoja', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * Genera el .xlsx y devuelve su ruta temporal.
     */
    public function generarExcel(array $datos): string
    {
        $libro = new Spreadsheet;
        $this->motor = $libro->getCalculationEngine();
        $libro->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $libro->getProperties()->setCreator('Sentinel ERP')->setTitle('Consolidado recepciones vs. embarques');

        $empaques = $datos['empaques'] ?? [];

        $resumen = $libro->getActiveSheet();
        $resumen->setTitle('Resumen');

        $ultimas = [];
        foreach ($empaques as $empaque) {
            $hoja = $libro->createSheet();
            $hoja->setTitle($empaque['hoja']);
            $ultimas[$empaque['hoja']] = $this->escribirDetalle($hoja, $empaque['filas'], 'tbl'.$empaque['tabla']);
        }

        $consolidado = $libro->createSheet();
        $consolidado->setTitle('Consolidado');
        $this->escribirDetalle(
            $consolidado,
            array_merge(...array_map(fn ($e) => $e['filas'], $empaques ?: [['filas' => []]])),
            'tblConsolidado',
        );

        foreach ($empaques as $empaque) {
            $hoja = $libro->createSheet();
            $hoja->setTitle('Rezaga '.$empaque['hoja']);
            $this->escribirRezaga($hoja, $empaque, $ultimas[$empaque['hoja']]);
        }

        $this->escribirResumen($resumen, $empaques, $ultimas, $datos['filtros'] ?? []);

        $libro->setActiveSheetIndex(0);

        $temporal = tempnam(sys_get_temp_dir(), 'rezaga_');
        $ruta = $temporal.'.xlsx';
        @unlink($temporal);
        $writer = new Xlsx($libro);
        $writer->setPreCalculateFormulas(true);
        $writer->save($ruta);
        $libro->disconnectWorksheets();
        $this->motor = null;
        $this->igualarNombresDeTablas($ruta);

        return $ruta;
    }

    /**
     * PhpSpreadsheet escribe cada tabla con name="TableN" y el nombre propio
     * solo en displayName; Excel muestra displayName, pero ListObject.Name
     * (y ListObjects("tblX") en VBA) usan name. Se igualan, como los guarda
     * Excel.
     */
    private function igualarNombresDeTablas(string $ruta): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($ruta) !== true) {
            return;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entrada = (string) $zip->getNameIndex($i);
            if (! preg_match('#^xl/tables/table\d+\.xml$#', $entrada)) {
                continue;
            }

            $xml = (string) $zip->getFromName($entrada);
            $corregido = preg_replace('/\bname="Table\d+"(\s+displayName="([^"]+)")/', 'name="$2"$1', $xml, 1);
            if ($corregido !== null && $corregido !== $xml) {
                $zip->addFromString($entrada, $corregido);
            }
        }

        $zip->close();
    }

    public function nombreArchivo(): string
    {
        return 'Consolidado_Recepciones_Embarques_'.now()->format('Y-m-d').'.xlsx';
    }

    // ---------------------------------------------------------------------
    // Consultas
    // ---------------------------------------------------------------------

    private function consultaRecepciones(array $empaques, ?string $desde, ?string $hasta, ?int $temporadaId): QueryBuilder
    {
        return DB::table('recepciones_empaque as r')
            ->join('entities as en', 'en.id', '=', 'r.entity_id')
            ->leftJoin('productores as p', 'p.id', '=', 'r.productor_id')
            ->leftJoin('lotes as l', 'l.id', '=', 'r.lote_id')
            ->whereNull('r.deleted_at')
            ->whereNotNull('r.folio_recepcion')
            ->where('r.folio_recepcion', '<>', '')
            ->when($temporadaId, fn ($q) => $q->where('r.temporada_id', $temporadaId))
            ->when(! empty($empaques), fn ($q) => $q->whereIn('r.entity_id', $empaques))
            ->when($desde, fn ($q) => $q->whereDate('r.fecha_recepcion', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('r.fecha_recepcion', '<=', $hasta))
            ->orderBy('r.fecha_recepcion')
            ->orderBy('r.folio_recepcion')
            ->select([
                'r.id',
                'r.entity_id',
                'en.name as empaque',
                'r.folio_recepcion',
                'r.fecha_recepcion',
                'p.nombre as productor_nombre',
                'p.apellido as productor_apellido',
                'l.nombre as lote_nombre',
                'l.numero_lote as lote_numero',
                'r.peso_recibido_kg',
                'r.peso_bascula',
            ]);
    }

    /**
     * Embarques agrupados por (folio de recepción × manifiesto). Excluye
     * embarques borrados o cancelados y los que no tienen manifiesto.
     */
    private function consultaEmbarques(array $recepcionIds): QueryBuilder
    {
        $base = fn () => DB::table('embarque_empaque_detalles as ed')
            ->join('embarques_empaque as e', 'e.id', '=', 'ed.embarque_id')
            ->join('produccion_empaque as pe', 'pe.id', '=', 'ed.produccion_id')
            ->whereNull('e.deleted_at')
            ->where('e.status', '<>', 'cancelado')
            ->whereNotNull('e.manifiesto')
            ->where('e.manifiesto', '<>', '');

        // Pallet sin desglose: todo el pallet es del folio de su proceso.
        $simple = $base()
            ->join('proceso_empaque as pr', 'pr.id', '=', 'pe.proceso_id')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('produccion_empaque_detalles as pdx')
                    ->whereColumn('pdx.produccion_id', 'pe.id');
            })
            ->whereIn('pr.recepcion_id', $recepcionIds)
            ->select([
                'pr.recepcion_id',
                'e.manifiesto',
                'e.folio_embarque',
                'e.fecha_embarque',
                'ed.numero_pallet',
                'ed.cajas',
                'ed.peso_kg',
                DB::raw('COALESCE(ed.peso_bascula_kg, pe.peso_bascula_kg, 0) as bascula_kg'),
            ]);

        // Pallet con desglose: cada entrada va a su folio; la báscula del
        // pallet se reparte por las cajas de la entrada.
        $conDetalle = $base()
            ->join('produccion_empaque_detalles as pd', 'pd.produccion_id', '=', 'pe.id')
            ->join('proceso_empaque as pr', 'pr.id', '=', 'pd.proceso_id')
            ->whereIn('pr.recepcion_id', $recepcionIds)
            ->select([
                'pr.recepcion_id',
                'e.manifiesto',
                'e.folio_embarque',
                'e.fecha_embarque',
                'ed.numero_pallet',
                DB::raw('pd.total_cajas as cajas'),
                DB::raw('COALESCE(pd.peso_neto_kg, 0) as peso_kg'),
                DB::raw('CASE WHEN COALESCE(ed.cajas, 0) > 0 AND COALESCE(pd.total_cajas, 0) > 0
                    THEN COALESCE(ed.peso_bascula_kg, pe.peso_bascula_kg, 0) * pd.total_cajas / ed.cajas
                    ELSE COALESCE(ed.peso_bascula_kg, pe.peso_bascula_kg, 0) END as bascula_kg'),
            ]);

        return DB::query()
            ->fromSub($simple->unionAll($conDetalle), 'x')
            ->groupBy('x.recepcion_id', 'x.manifiesto', 'x.folio_embarque', 'x.fecha_embarque')
            ->select(['x.recepcion_id', 'x.manifiesto', 'x.folio_embarque', 'x.fecha_embarque'])
            ->selectRaw('COUNT(DISTINCT x.numero_pallet) as pallets')
            ->selectRaw('COALESCE(SUM(x.cajas), 0) as cajas')
            ->selectRaw('COALESCE(SUM(x.peso_kg), 0) as peso_kg')
            ->selectRaw('COALESCE(SUM(x.bascula_kg), 0) as bascula_kg');
    }

    // ---------------------------------------------------------------------
    // Nombres
    // ---------------------------------------------------------------------

    /**
     * «Empaque Fresh Cooling» → «FreshCooling»: sin el prefijo «Empaque»,
     * sin espacios ni caracteres que Excel no admite, hasta 24 caracteres
     * (para que «Rezaga {hoja}» quepa en los 31 del nombre de hoja).
     */
    private function nombreHoja(string $nombre, int $id, array $usados): string
    {
        $limpio = preg_replace('/^\s*empaque\s+/iu', '', $nombre);
        $limpio = preg_replace('/[\s\[\]:*?\/\\\\\']+/u', '', (string) $limpio);
        $limpio = mb_substr((string) $limpio, 0, 24);

        if ($limpio === '') {
            $limpio = 'Empaque'.$id;
        }

        $candidato = $limpio;
        $n = 2;
        while (in_array(mb_strtolower($candidato), $usados, true)) {
            $sufijo = (string) $n++;
            $candidato = mb_substr($limpio, 0, 24 - mb_strlen($sufijo)).$sufijo;
        }

        return $candidato;
    }

    private function nombreTabla(string $hoja): string
    {
        $tabla = preg_replace('/[^A-Za-z0-9_]/', '', Str::ascii($hoja));

        return $tabla === '' ? 'Empaque' : $tabla;
    }

    private function ref(string $hoja): string
    {
        return "'".str_replace("'", "''", $hoja)."'!";
    }

    // ---------------------------------------------------------------------
    // Hojas
    // ---------------------------------------------------------------------

    /**
     * Pestaña de detalle (folio × manifiesto). Devuelve la última fila de
     * datos (n).
     */
    private function escribirDetalle(Worksheet $hoja, array $filas, string $nombreTabla): int
    {
        $this->escribirEncabezado($hoja, self::COLUMNAS_DETALLE);

        $fila = 2;
        $n = max(2, count($filas) + 1);
        $porFolio = $this->totalesPorFolio($filas);
        foreach ($filas as $dato) {
            $i = $fila;
            $folio = $porFolio[$dato['folio_recepcion']];
            $f = $dato['peso_recibido'];
            $q = $dato['peso_manifiesto'];
            $proporcion = $folio['embarcado'] == 0 ? 1 : $q / $folio['embarcado'];
            $rezaga = ($f - $folio['embarcado']) * $proporcion;
            $this->precalcular($hoja, "H{$i}", $folio['manifiestos']);
            $this->precalcular($hoja, "I{$i}", $folio['embarcado']);
            $this->precalcular($hoja, "J{$i}", $rezaga);
            $this->precalcular($hoja, "K{$i}", $f == 0 ? 0 : $rezaga / $f);
            $this->precalcular($hoja, "T{$i}", $f * $proporcion);

            $hoja->setCellValue("A{$i}", $dato['empaque']);
            $hoja->setCellValueExplicit("B{$i}", $dato['folio_recepcion'], DataType::TYPE_STRING);
            $this->escribirFecha($hoja, "C{$i}", $dato['fecha_recepcion']);
            $hoja->setCellValue("D{$i}", $dato['productor']);
            $hoja->setCellValueExplicit("E{$i}", $dato['lote'], DataType::TYPE_STRING);
            $hoja->setCellValue("F{$i}", $dato['peso_recibido']);
            $hoja->setCellValue("G{$i}", $dato['peso_bascula']);
            $hoja->setCellValue("H{$i}", "=COUNTIFS(\$B\$2:\$B\${$n},B{$i},\$M\$2:\$M\${$n},\"?*\")");
            $hoja->setCellValue("I{$i}", "=SUMIFS(\$Q\$2:\$Q\${$n},\$B\$2:\$B\${$n},B{$i})");
            $hoja->setCellValue("J{$i}", "=(F{$i}-I{$i})*IF(I{$i}=0,1,Q{$i}/I{$i})");
            $hoja->setCellValue("K{$i}", "=IF(F{$i}=0,0,J{$i}/F{$i})");

            if ($dato['manifiesto'] === null) {
                $hoja->setCellValue("L{$i}", 'Sin embarque');
                $hoja->getStyle("L{$i}")->getFont()->setItalic(true)->getColor()->setRGB(self::ROJO);
            } else {
                $hoja->setCellValueExplicit("L{$i}", $dato['manifiesto'], DataType::TYPE_STRING);
                $hoja->setCellValueExplicit("M{$i}", $dato['folio_embarque'], DataType::TYPE_STRING);
                $this->escribirFecha($hoja, "N{$i}", $dato['fecha_embarque']);
            }

            $hoja->setCellValue("O{$i}", $dato['pallets']);
            $hoja->setCellValue("P{$i}", $dato['cajas']);
            $hoja->setCellValue("Q{$i}", $dato['peso_manifiesto']);
            $hoja->setCellValue("R{$i}", $dato['bascula_embarque']);
            $hoja->setCellValue("S{$i}", $dato['primera_fila']);
            $hoja->setCellValue("T{$i}", "=F{$i}*IF(I{$i}=0,1,Q{$i}/I{$i})");
            $fila++;
        }

        $this->aplicarFormatos($hoja, self::COLUMNAS_DETALLE, 2, $n);
        $this->rojoSiNegativo($hoja, "J2:K{$n}");
        $this->agregarTabla($hoja, "A1:T{$n}", $nombreTabla, TableStyle::TABLE_STYLE_MEDIUM2);
        $hoja->freezePane('C2');

        return $n;
    }

    /**
     * Pestaña «Rezaga {Empaque}»: una fila por folio, sin columnas de
     * embarque; el embarcado se lee de la pestaña de detalle.
     */
    private function escribirRezaga(Worksheet $hoja, array $empaque, int $nDetalle): void
    {
        $columnas = array_slice(self::COLUMNAS_DETALLE, 0, 11, true);
        $this->escribirEncabezado($hoja, $columnas);

        $src = $this->ref($empaque['hoja']);
        $folios = array_values(array_filter($empaque['filas'], fn ($f) => $f['primera_fila'] === 1));
        $porFolio = $this->totalesPorFolio($empaque['filas']);
        $sumas = ['F' => 0, 'G' => 0, 'H' => 0, 'I' => 0, 'J' => 0];

        $i = 2;
        foreach ($folios as $dato) {
            $folio = $porFolio[$dato['folio_recepcion']];
            $rezaga = $dato['peso_recibido'] - $folio['embarcado'];
            $this->precalcular($hoja, "H{$i}", $folio['manifiestos']);
            $this->precalcular($hoja, "I{$i}", $folio['embarcado']);
            $this->precalcular($hoja, "J{$i}", $rezaga);
            $this->precalcular($hoja, "K{$i}", $dato['peso_recibido'] == 0 ? 0 : $rezaga / $dato['peso_recibido']);
            $sumas['F'] += $dato['peso_recibido'];
            $sumas['G'] += $dato['peso_bascula'];
            $sumas['H'] += $folio['manifiestos'];
            $sumas['I'] += $folio['embarcado'];
            $sumas['J'] += $rezaga;

            $hoja->setCellValue("A{$i}", $dato['empaque']);
            $hoja->setCellValueExplicit("B{$i}", $dato['folio_recepcion'], DataType::TYPE_STRING);
            $this->escribirFecha($hoja, "C{$i}", $dato['fecha_recepcion']);
            $hoja->setCellValue("D{$i}", $dato['productor']);
            $hoja->setCellValueExplicit("E{$i}", $dato['lote'], DataType::TYPE_STRING);
            $hoja->setCellValue("F{$i}", $dato['peso_recibido']);
            $hoja->setCellValue("G{$i}", $dato['peso_bascula']);
            $hoja->setCellValue("H{$i}", "=COUNTIFS({$src}\$B\$2:\$B\${$nDetalle},B{$i},{$src}\$M\$2:\$M\${$nDetalle},\"?*\")");
            $hoja->setCellValue("I{$i}", "=SUMIFS({$src}\$Q\$2:\$Q\${$nDetalle},{$src}\$B\$2:\$B\${$nDetalle},B{$i})");
            $hoja->setCellValue("J{$i}", "=F{$i}-I{$i}");
            $hoja->setCellValue("K{$i}", "=IF(F{$i}=0,0,J{$i}/F{$i})");
            $i++;
        }

        $m = max(2, count($folios) + 1);
        $this->aplicarFormatos($hoja, $columnas, 2, $m);
        $this->rojoSiNegativo($hoja, "J2:K{$m}");
        $this->agregarTabla($hoja, "A1:K{$m}", 'tblRezaga'.$empaque['tabla'], TableStyle::TABLE_STYLE_MEDIUM4);
        $hoja->freezePane('C2');

        // Fila de TOTAL dos filas debajo de la tabla (una en blanco).
        $tr = $m + 2;
        $hoja->setCellValue("A{$tr}", 'TOTAL');
        foreach (['F', 'G', 'H', 'I', 'J'] as $col) {
            $hoja->setCellValue("{$col}{$tr}", "=SUBTOTAL(109,{$col}2:{$col}{$m})");
            $hoja->getStyle("{$col}{$tr}")->getNumberFormat()->setFormatCode($columnas[$col][1]);
            $this->precalcular($hoja, "{$col}{$tr}", $sumas[$col]);
        }
        $hoja->setCellValue("K{$tr}", "=IF(F{$tr}=0,0,J{$tr}/F{$tr})");
        $this->precalcular($hoja, "K{$tr}", $sumas['F'] == 0 ? 0 : $sumas['J'] / $sumas['F']);
        $hoja->getStyle("K{$tr}")->getNumberFormat()->setFormatCode(self::FMT_PCT);
        $this->estiloTotal($hoja, "A{$tr}:K{$tr}");
        $this->rojoSiNegativo($hoja, "J{$tr}:K{$tr}");

        $nota = $tr + 1;
        $hoja->setCellValue(
            "A{$nota}",
            'Total sobre filas visibles (respeta filtros). Rezaga = Peso Recibido − Total Embarcado; '
            ."el embarcado se toma de la pestaña {$empaque['hoja']}.",
        );
        $this->estiloNota($hoja, "A{$nota}");
    }

    private function escribirResumen(Worksheet $hoja, array $empaques, array $ultimas, array $filtros): void
    {
        $hoja->setCellValue('A1', 'Consolidado recepciones vs. embarques por empaque');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::AZUL_TITULO);

        $notas = [
            $this->notaFecha($filtros),
            'Cada fila del detalle = folio de recepción × manifiesto; los datos del folio (fecha, productor, lote, pesos) se repiten en cada manifiesto.',
            'Para sumar peso recibido o báscula sin duplicar: filtrar Primera Fila Folio = 1, o usar Máx por folio en la tabla dinámica.',
            'Rezaga (kg) en el detalle va prorrateada por peso del manifiesto y sí se puede sumar; negativo en rojo = se embarcó más de lo recibido. '
                .'% de cualquier grupo = Σ Rezaga (kg) / Σ Peso Recibido Prorrateado.',
            '"Sin captura" = báscula en 0; "Sin embarque" = folio sin manifiesto.',
        ];
        foreach ($notas as $k => $texto) {
            $celda = 'A'.($k + 2);
            $hoja->setCellValue($celda, $texto);
            $this->estiloNota($hoja, $celda);
        }

        $encabezado = 7;
        foreach (self::COLUMNAS_RESUMEN as $col => [$titulo, , $ancho]) {
            $hoja->setCellValue("{$col}{$encabezado}", $titulo);
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
        $estilo = $hoja->getStyle("A{$encabezado}:J{$encabezado}");
        $estilo->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL_TITULO);
        $estilo->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $hoja->getRowDimension($encabezado)->setRowHeight(32);

        $i = $encabezado + 1;
        $totales = [];
        foreach ($empaques as $empaque) {
            $s = $this->ref($empaque['hoja']);
            $n = $ultimas[$empaque['hoja']];
            $p = "{$s}\$S\$2:\$S\${$n}";

            $hoja->setCellValue("A{$i}", $empaque['hoja']);
            $hoja->setCellValue("B{$i}", "=SUM({$p})");
            $hoja->setCellValue("C{$i}", "=COUNTIFS({$p},1,{$s}\$G\$2:\$G\${$n},\">0\")");
            $hoja->setCellValue("D{$i}", "=COUNTIFS({$p},1,{$s}\$H\$2:\$H\${$n},0)");
            $hoja->setCellValue("E{$i}", "=SUMIFS({$s}\$F\$2:\$F\${$n},{$p},1)");
            $hoja->setCellValue("F{$i}", "=SUMIFS({$s}\$G\$2:\$G\${$n},{$p},1)");
            $hoja->setCellValue("G{$i}", "=SUM({$s}\$Q\$2:\$Q\${$n})");

            $valores = $this->valoresResumen($empaque['filas']);
            foreach ($valores as $col => $valor) {
                $this->precalcular($hoja, "{$col}{$i}", $valor);
                $totales[$col] = ($totales[$col] ?? 0) + $valor;
            }
            $this->formulasDerivadasResumen($hoja, $i, $valores);
            $hoja->getStyle("B{$i}:G{$i}")->getFont()->getColor()->setRGB(self::VERDE_REFERENCIA);
            $i++;
        }

        $primera = $encabezado + 1;
        $ultima = max($primera, $i - 1);
        $tr = $i;
        $hoja->setCellValue("A{$tr}", 'TOTAL');
        foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $col) {
            $hoja->setCellValue("{$col}{$tr}", "=SUM({$col}{$primera}:{$col}{$ultima})");
            $this->precalcular($hoja, "{$col}{$tr}", $totales[$col] ?? 0);
        }
        $this->formulasDerivadasResumen($hoja, $tr, $totales ?? []);
        $this->estiloTotal($hoja, "A{$tr}:J{$tr}");

        foreach (self::COLUMNAS_RESUMEN as $col => [, $formato]) {
            if ($formato) {
                $hoja->getStyle("{$col}{$primera}:{$col}{$tr}")->getNumberFormat()->setFormatCode($formato);
            }
        }
        $this->rojoSiNegativo($hoja, "I{$primera}:J{$tr}");
    }

    private function formulasDerivadasResumen(Worksheet $hoja, int $i, array $valores): void
    {
        $recibido = $valores['E'] ?? 0;
        $embarcado = $valores['G'] ?? 0;

        $hoja->setCellValue("H{$i}", "=IF(E{$i}=0,0,G{$i}/E{$i})");
        $hoja->setCellValue("I{$i}", "=E{$i}-G{$i}");
        $hoja->setCellValue("J{$i}", "=IF(E{$i}=0,0,I{$i}/E{$i})");
        $this->precalcular($hoja, "H{$i}", $recibido == 0 ? 0 : $embarcado / $recibido);
        $this->precalcular($hoja, "I{$i}", $recibido - $embarcado);
        $this->precalcular($hoja, "J{$i}", $recibido == 0 ? 0 : ($recibido - $embarcado) / $recibido);
    }

    /**
     * Valores de las columnas B–G del Resumen para un empaque (lo mismo que
     * calculan sus fórmulas sobre la pestaña de detalle).
     */
    private function valoresResumen(array $filas): array
    {
        $porFolio = $this->totalesPorFolio($filas);
        $valores = ['B' => 0, 'C' => 0, 'D' => 0, 'E' => 0, 'F' => 0, 'G' => 0];

        foreach ($filas as $fila) {
            $valores['G'] += $fila['peso_manifiesto'];
            if ($fila['primera_fila'] !== 1) {
                continue;
            }
            $valores['B']++;
            $valores['C'] += $fila['peso_bascula'] > 0 ? 1 : 0;
            $valores['D'] += $porFolio[$fila['folio_recepcion']]['manifiestos'] === 0 ? 1 : 0;
            $valores['E'] += $fila['peso_recibido'];
            $valores['F'] += $fila['peso_bascula'];
        }

        return $valores;
    }

    /**
     * Por folio: manifiestos con folio de embarque (columna H) y total
     * embarcado (columna I) de una pestaña de detalle.
     *
     * @return array<string, array{manifiestos:int, embarcado:float}>
     */
    private function totalesPorFolio(array $filas): array
    {
        $totales = [];
        foreach ($filas as $fila) {
            $folio = $fila['folio_recepcion'];
            $totales[$folio] ??= ['manifiestos' => 0, 'embarcado' => 0.0];
            $totales[$folio]['manifiestos'] += ($fila['folio_embarque'] ?? '') !== '' ? 1 : 0;
            $totales[$folio]['embarcado'] += $fila['peso_manifiesto'];
        }

        return $totales;
    }

    private function precalcular(Worksheet $hoja, string $celda, int|float $valor): void
    {
        $this->motor?->saveValueToCache($hoja->getTitle().'!'.$celda, $valor);
    }

    private function notaFecha(array $filtros): string
    {
        $texto = 'Datos al '.now()->format('d/m/Y');

        $desde = $filtros['desde'] ?? null;
        $hasta = $filtros['hasta'] ?? null;
        if ($desde || $hasta) {
            $texto .= ' (recepciones'
                .($desde ? ' desde '.Carbon::parse($desde)->format('d/m/Y') : '')
                .($hasta ? ' hasta '.Carbon::parse($hasta)->format('d/m/Y') : '')
                .')';
        }

        if (! empty($filtros['temporada_id'])) {
            $temporada = DB::table('temporadas')->where('id', $filtros['temporada_id'])->value('nombre');
            if ($temporada) {
                $texto .= ", temporada {$temporada}";
            }
        }

        return $texto.'. Pestañas: una por empaque (detalle folio × manifiesto); Consolidado = todas las filas de detalle juntas, '
            .'ideal para tablas dinámicas; Rezaga {Empaque} = una fila por folio con su rezaga completa.';
    }

    // ---------------------------------------------------------------------
    // Estilos
    // ---------------------------------------------------------------------

    private function escribirEncabezado(Worksheet $hoja, array $columnas): void
    {
        foreach ($columnas as $col => [$titulo, , $ancho]) {
            $hoja->setCellValue("{$col}1", $titulo);
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }

        $ultima = array_key_last($columnas);
        $estilo = $hoja->getStyle("A1:{$ultima}1");
        $estilo->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $estilo->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $hoja->getRowDimension(1)->setRowHeight(32);
    }

    private function aplicarFormatos(Worksheet $hoja, array $columnas, int $desde, int $hasta): void
    {
        foreach ($columnas as $col => [, $formato]) {
            if ($formato) {
                $hoja->getStyle("{$col}{$desde}:{$col}{$hasta}")->getNumberFormat()->setFormatCode($formato);
            }
        }
    }

    private function escribirFecha(Worksheet $hoja, string $celda, ?string $fecha): void
    {
        if ($fecha) {
            $hoja->setCellValue($celda, ExcelDate::PHPToExcel(Carbon::parse($fecha)->startOfDay()));
        }
    }

    private function rojoSiNegativo(Worksheet $hoja, string $rango): void
    {
        $condicion = new Conditional;
        $condicion->setConditionType(Conditional::CONDITION_CELLIS)
            ->setOperatorType(Conditional::OPERATOR_LESSTHAN)
            ->addCondition('0');
        $condicion->getStyle()->getFont()->getColor()->setRGB(self::ROJO);

        $existentes = $hoja->getStyle($rango)->getConditionalStyles();
        $existentes[] = $condicion;
        $hoja->getStyle($rango)->setConditionalStyles($existentes);
    }

    private function agregarTabla(Worksheet $hoja, string $rango, string $nombre, string $tema): void
    {
        $tabla = new Table($rango, $nombre);
        $tabla->setStyle((new TableStyle)->setTheme($tema)->setShowRowStripes(true));
        $hoja->addTable($tabla);
    }

    private function estiloTotal(Worksheet $hoja, string $rango): void
    {
        $estilo = $hoja->getStyle($rango);
        $estilo->getFont()->setBold(true);
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::RELLENO_TOTAL);
    }

    private function estiloNota(Worksheet $hoja, string $celda): void
    {
        $hoja->getStyle($celda)->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB(self::GRIS_NOTA);
    }
}
