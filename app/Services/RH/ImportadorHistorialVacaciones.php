<?php

namespace App\Services\RH;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lee el Excel de RH (hoja «Registro de Vacaciones», tal como lo llevan) o una
 * plantilla simple y arma la vista previa de la importación del historial. No guarda
 * nada: la importación real valida otra vez fila por fila (HistorialVacaciones).
 *
 * Del archivo de RH: «Fecha de regreso» es el día en que vuelve, así que el último día
 * de vacaciones es el anterior; los días se toman de «Días disfrutados» (el valor que
 * Excel dejó calculado; algunas fórmulas no se pueden evaluar fuera de Excel). De la
 * plantilla: «Hasta» ya es el último día.
 */
class ImportadorHistorialVacaciones
{
    public const MAX_FILAS = 5000;

    public const LISTA = 'lista';

    public const REVISAR = 'revisar';

    public const OMITIDA = 'omitida';

    private const COLUMNAS = [
        'nombre' => ['nombre del empleado', 'nombre', 'empleado'],
        'numero' => ['numero de empleado', 'no. de empleado', 'num. de empleado'],
        'periodo' => ['periodo', 'ano', 'ano vacacional'],
        'inicio' => ['fecha de inicio', 'desde', 'inicio'],
        'regreso' => ['fecha de regreso', 'regreso'],
        'fin' => ['hasta', 'fecha de fin', 'ultimo dia'],
        'dias' => ['dias disfrutados', 'dias'],
        'nota' => ['observaciones', 'nota', 'notas'],
    ];

    private const PALABRAS_DE_DIAS = ['NAVIDAD', '1RO DE ENERO', 'PRIMERO DE ENERO', 'SEMANA SANTA', 'FESTIVO', 'INHABIL'];

    /** @return array{formato: string, hoja: string, archivo: string, empleados: array, filas: array, total_filas: int, opciones: array} */
    public function leer(UploadedFile $archivo): array
    {
        $libro = $this->abrir($archivo);
        $hoja = $this->hojaDeRegistro($libro);
        [$columnas, $filaEncabezado] = $this->columnas($hoja);

        if (! isset($columnas['inicio']) && ! isset($columnas['periodo'])) {
            throw ValidationException::withMessages(['archivo' => 'No encontré las columnas del historial. Usa la hoja «Registro de Vacaciones» o la plantilla (Número de empleado, Nombre, Año, Desde, Hasta, Días, Nota).']);
        }
        if (! isset($columnas['nombre']) && ! isset($columnas['numero'])) {
            throw ValidationException::withMessages(['archivo' => 'El archivo necesita una columna «Nombre del empleado» o «Número de empleado».']);
        }

        $formato = isset($columnas['regreso']) ? 'rh' : 'plantilla';
        $personal = $this->controlDePersonal($libro);
        $filas = $this->filas($hoja, $columnas, $filaEncabezado);
        $empleados = $this->empleados($filas, $personal);

        return [
            'formato' => $formato,
            'hoja' => $hoja->getTitle(),
            'archivo' => $archivo->getClientOriginalName(),
            'empleados' => array_values($empleados),
            'filas' => $filas,
            'total_filas' => count($filas),
            // Para asignar a mano un nombre sin coincidencia: todos los empleados (lo mínimo).
            'opciones' => Employee::with('enterprise:id,name')
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'enterprise_id', 'employee_number', 'first_name', 'last_name', 'second_last_name', 'hire_date', 'status'])
                ->map(fn (Employee $e) => $this->presentarEmpleado($e))
                ->values()
                ->all(),
        ];
    }

    public static function normalizar(?string $texto): string
    {
        $texto = mb_strtoupper(trim((string) $texto));
        $texto = strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    private function abrir(UploadedFile $archivo)
    {
        try {
            $lector = IOFactory::createReaderForFile($archivo->getRealPath());
            if ($lector instanceof Csv) {
                $lector->setInputEncoding(Csv::GUESS_ENCODING);
            }
            $lector->setReadDataOnly(true);

            return $lector->load($archivo->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Sube un Excel (.xlsx, .xls) o un CSV.']);
        }
    }

    private function hojaDeRegistro($libro): Worksheet
    {
        foreach ($libro->getWorksheetIterator() as $hoja) {
            if (self::normalizar($hoja->getTitle()) === 'REGISTRO DE VACACIONES') {
                return $hoja;
            }
        }

        return $libro->getSheet(0);
    }

    /** @return array{0: array<string, string>, 1: int} columna (letra) por campo y número de la fila de encabezados */
    private function columnas(Worksheet $hoja): array
    {
        // El encabezado está en una de las primeras 5 filas.
        foreach (range(1, min(5, $hoja->getHighestDataRow())) as $fila) {
            $encontradas = [];
            foreach ($hoja->getRowIterator($fila, $fila)->current()->getCellIterator() as $celda) {
                $titulo = mb_strtolower(self::normalizar((string) $this->valor($celda)));
                foreach (self::COLUMNAS as $campo => $alias) {
                    if (! isset($encontradas[$campo]) && in_array($titulo, $alias, true)) {
                        $encontradas[$campo] = $celda->getColumn();
                    }
                }
            }
            if (count($encontradas) >= 3) {
                return [$encontradas, $fila];
            }
        }

        return [[], 1];
    }

    private function filas(Worksheet $hoja, array $columnas, int $filaEncabezado): array
    {
        $ultima = $hoja->getHighestDataRow();
        if ($ultima - $filaEncabezado > self::MAX_FILAS) {
            throw ValidationException::withMessages(['archivo' => 'El archivo tiene más de '.self::MAX_FILAS.' filas. Divídelo en partes.']);
        }

        $hoy = today()->format('Y-m-d');
        $filas = [];
        $vistas = [];

        for ($numero = $filaEncabezado + 1; $numero <= $ultima; $numero++) {
            $leer = fn (string $campo) => isset($columnas[$campo]) ? $this->valor($hoja->getCell($columnas[$campo].$numero)) : null;
            $nombre = trim((string) $leer('nombre'));
            $numeroEmpleado = trim((string) $leer('numero'));
            $desde = $this->fecha($leer('inicio'));
            $hasta = isset($columnas['regreso'])
                ? ($this->fecha($leer('regreso')) ? Carbon::parse($this->fecha($leer('regreso')))->subDay()->format('Y-m-d') : null)
                : $this->fecha($leer('fin'));
            $diasCrudos = $leer('dias');
            $nota = trim((string) $leer('nota'));
            $periodo = trim((string) $leer('periodo'));

            if ($nombre === '' && $numeroEmpleado === '' && $desde === null && $hasta === null && $nota === '' && ! is_numeric($diasCrudos)) {
                continue;
            }

            preg_match('/(\d{4})/', $periodo, $anioDelPeriodo);
            $anio = isset($anioDelPeriodo[1]) ? (int) $anioDelPeriodo[1] : ($desde ? (int) substr($desde, 0, 4) : null);
            $dias = is_numeric($diasCrudos) && (float) $diasCrudos > 0 ? (int) round((float) $diasCrudos) : null;
            $clave = $numeroEmpleado !== '' ? 'num:'.mb_strtoupper($numeroEmpleado) : 'nom:'.self::normalizar($nombre);
            $notaNormal = self::normalizar($nota);

            $fila = [
                'fila' => $numero,
                'clave' => $clave,
                'nombre' => $nombre !== '' ? $nombre : null,
                'numero' => $numeroEmpleado !== '' ? $numeroEmpleado : null,
                'periodo' => $periodo !== '' ? $periodo : null,
                'vacation_year' => $anio,
                'start_date' => $desde,
                'end_date' => $hasta,
                'days' => $dias,
                'notes' => $nota !== '' ? mb_substr($nota, 0, 500) : null,
                'estado' => self::LISTA,
                'avisos' => [],
            ];

            $motivoOmision = match (true) {
                $nombre === '' && $numeroEmpleado === '' => 'Sin empleado.',
                ($desde && $desde > $hoy) || ($hasta && $hasta > $hoy) => 'Fecha futura: no es historial, captúrala como solicitud.',
                str_contains($notaNormal, 'PATERNIDAD') => 'Permiso de paternidad: no son vacaciones.',
                $desde && $hasta && isset($vistas["{$clave}|{$desde}|{$hasta}"]) => 'Duplicada en el archivo (fila '.$vistas["{$clave}|{$desde}|{$hasta}"].').',
                default => null,
            };

            if ($motivoOmision !== null) {
                $fila['estado'] = self::OMITIDA;
                $fila['avisos'] = [$motivoOmision];
            } else {
                if (($desde === null) !== ($hasta === null) || ($desde === null && ($anio === null || $dias === null))) {
                    $fila['avisos'][] = 'Faltan fechas o días: escribe el año y los días, o omítela.';
                } elseif ($desde === null) {
                    $fila['avisos'][] = 'Sin fechas: solo cuenta los días.';
                }
                if ($desde !== null && $dias === null) {
                    $fila['avisos'][] = 'Faltan los días.';
                }
                if (preg_match('/\(\s*\d+\s*\)/', $nota) || collect(self::PALABRAS_DE_DIAS)->contains(fn ($palabra) => str_contains($notaNormal, $palabra))) {
                    $fila['avisos'][] = 'La nota sugiere otro número de días: revísalo.';
                }
                foreach ($filas as $otra) {
                    if ($otra['clave'] === $clave && $otra['estado'] !== self::OMITIDA && $desde && $hasta && $otra['start_date']
                        && $desde <= $otra['end_date'] && $hasta >= $otra['start_date']) {
                        $fila['avisos'][] = "Se encima con la fila {$otra['fila']}.";
                        break;
                    }
                }
                $listaSinDudas = $fila['avisos'] === [] || $fila['avisos'] === ['Sin fechas: solo cuenta los días.'];
                $fila['estado'] = $listaSinDudas ? self::LISTA : self::REVISAR;
            }

            if ($desde && $hasta) {
                $vistas["{$clave}|{$desde}|{$hasta}"] ??= $numero;
            }
            $filas[] = $fila;
        }

        return $filas;
    }

    /** Empleado sugerido por cada nombre (o número) del archivo, con candidatos si no hay coincidencia exacta. */
    private function empleados(array $filas, array $personal): array
    {
        $todos = Employee::with('enterprise:id,name')->get(['id', 'enterprise_id', 'employee_number', 'first_name', 'last_name', 'second_last_name', 'hire_date', 'status']);
        $porNombre = $todos->groupBy(fn (Employee $e) => self::normalizar($e->full_name));
        $porNumero = $todos->keyBy(fn (Employee $e) => mb_strtoupper(trim((string) $e->employee_number)));
        $resultado = [];

        foreach ($filas as $fila) {
            // Una fila sin nombre ni número ya va omitida: no hay a quién asignarla.
            if ($fila['nombre'] === null && $fila['numero'] === null) {
                continue;
            }
            if (isset($resultado[$fila['clave']])) {
                $resultado[$fila['clave']]['filas']++;
                continue;
            }

            $nombreNormal = self::normalizar($fila['nombre']);
            $sugerido = null;
            $coincidencia = 'ninguna';
            $candidatos = collect();

            if ($fila['numero'] !== null && $porNumero->has(mb_strtoupper($fila['numero']))) {
                $sugerido = $porNumero[mb_strtoupper($fila['numero'])];
                $coincidencia = 'numero';
            } elseif ($nombreNormal !== '' && ($porNombre[$nombreNormal] ?? collect())->count() === 1) {
                $sugerido = $porNombre[$nombreNormal]->first();
                $coincidencia = 'exacta';
            } elseif ($nombreNormal !== '') {
                $palabras = array_filter(explode(' ', $nombreNormal), fn ($p) => mb_strlen($p) > 2);
                $candidatos = $todos
                    ->map(function (Employee $e) use ($palabras) {
                        $suyas = explode(' ', self::normalizar($e->full_name));

                        return ['empleado' => $e, 'comunes' => count(array_intersect($palabras, $suyas)), 'todas' => array_diff($palabras, $suyas) === []];
                    })
                    ->filter(fn ($c) => $c['comunes'] >= 2)
                    ->sortByDesc(fn ($c) => [$c['todas'], $c['comunes']])
                    ->take(5)
                    ->values();
                $completas = $candidatos->where('todas', true);
                if ($completas->count() === 1) {
                    $sugerido = $completas->first()['empleado'];
                    $coincidencia = 'parcial';
                }
                $candidatos = $candidatos->map(fn ($c) => $c['empleado']);
            }

            $excel = $personal[$nombreNormal] ?? null;
            $avisos = [];
            if ($excel && $sugerido && $excel['ingreso'] && $sugerido->hire_date && $excel['ingreso'] !== $sugerido->hire_date->format('Y-m-d')) {
                $avisos[] = "Fecha de ingreso distinta: Excel {$excel['ingreso']}, Sentinel {$sugerido->hire_date->format('Y-m-d')}.";
            }
            if ($excel && $excel['observaciones']) {
                $avisos[] = "Nota en Control de Personal: «{$excel['observaciones']}». Si son días pagados, regístralos como ajuste.";
            }

            $resultado[$fila['clave']] = [
                'clave' => $fila['clave'],
                'nombre' => $fila['nombre'],
                'numero' => $fila['numero'],
                'coincidencia' => $coincidencia,
                'employee_id' => $sugerido?->id,
                'candidatos' => $candidatos->map(fn (Employee $e) => $this->presentarEmpleado($e))->values()->all(),
                'sugerido' => $sugerido ? $this->presentarEmpleado($sugerido) : null,
                'pendientes_excel' => $excel['pendientes'] ?? null,
                'avisos' => $avisos,
                'filas' => 1,
            ];
        }

        return $resultado;
    }

    private function presentarEmpleado(Employee $empleado): array
    {
        return [
            'id' => $empleado->id,
            'nombre' => $empleado->full_name,
            'numero' => $empleado->employee_number,
            'empresa' => $empleado->enterprise?->name,
            'fecha_ingreso' => $empleado->hire_date?->format('Y-m-d'),
            'activo' => $empleado->status === Employee::STATUS_ACTIVE,
        ];
    }

    /** Datos de la hoja «Control de Personal» por nombre normalizado (si el archivo la trae). */
    private function controlDePersonal($libro): array
    {
        $hoja = null;
        foreach ($libro->getWorksheetIterator() as $candidata) {
            if (self::normalizar($candidata->getTitle()) === 'CONTROL DE PERSONAL') {
                $hoja = $candidata;
            }
        }
        if (! $hoja) {
            return [];
        }

        $encabezados = [];
        foreach ($hoja->getRowIterator(1, 1)->current()->getCellIterator() as $celda) {
            $encabezados[mb_strtolower(self::normalizar((string) $this->valor($celda)))] = $celda->getColumn();
        }
        $col = fn (string $titulo) => $encabezados[$titulo] ?? null;
        if (! $col('nombre del empleado')) {
            return [];
        }

        $personal = [];
        for ($fila = 2; $fila <= min($hoja->getHighestDataRow(), self::MAX_FILAS); $fila++) {
            $nombre = self::normalizar((string) $this->valor($hoja->getCell($col('nombre del empleado').$fila)));
            if ($nombre === '') {
                continue;
            }
            $pendientes = $col('dias pendientes') ? $this->valor($hoja->getCell($col('dias pendientes').$fila)) : null;
            $observaciones = $col('observaciones') ? trim((string) $this->valor($hoja->getCell($col('observaciones').$fila))) : '';
            $personal[$nombre] = [
                'pendientes' => is_numeric($pendientes) ? (int) round((float) $pendientes) : null,
                'observaciones' => $observaciones !== '' ? mb_substr($observaciones, 0, 200) : null,
                'ingreso' => $col('fecha de ingreso') ? $this->fecha($this->valor($hoja->getCell($col('fecha de ingreso').$fila))) : null,
            ];
        }

        return $personal;
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

    /** Fecha de Excel (número de serie), objeto o texto (2025-03-14, 14/03/2025, 14-03-25) → Y-m-d. */
    private function fecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }
        if (is_numeric($valor)) {
            $serie = (float) $valor;

            return $serie > 20000 && $serie < 80000 ? FechaExcel::excelToDateTimeObject($serie)->format('Y-m-d') : null;
        }

        $texto = trim((string) $valor);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'] as $formato) {
            $fecha = \DateTime::createFromFormat('!'.$formato, $texto);
            if ($fecha && $fecha->format($formato) === $texto) {
                return $fecha->format('Y-m-d');
            }
        }

        return null;
    }
}
