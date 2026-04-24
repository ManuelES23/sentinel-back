<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AttendanceSpreadsheetService
{
    /**
     * Parsea el archivo del checador y devuelve filas agrupadas por (checker_key, date).
     *
     * Soporta dos formatos:
     *  A) Archivo pre-agrupado con columnas entrada/salida (hora_entrada, hora_salida).
     *  B) Archivo "Transaction" exportado por el checador (ZKTeco/ISS):
     *     First Name | Last Name | ID | Department | Date | Time | Weekday | Data Source |
     *     Device Name | Device Serial No. | Punch State | Location | Remarks
     *     → Una fila por marcación; se agrupa por (ID, Date) tomando la menor hora
     *       como entrada y la mayor como salida.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, false);

        if (count($rawRows) < 2) {
            return [];
        }

        // Detectar la fila de encabezados (puede estar en la fila 1, 2 o 3 si el export
        // incluye títulos como "Transaction" y "Export Time" antes de los headers).
        [$headerRowIndex, $headers] = $this->detectHeaders($rawRows);
        if ($headerRowIndex === null) {
            return [];
        }

        $dataRows = array_slice($rawRows, $headerRowIndex + 1);
        $mappedRows = [];

        foreach ($dataRows as $row) {
            $mapped = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $mapped[$header] = $row[$index] ?? null;
            }

            $normalized = $this->normalizeRow($mapped);
            if (! $normalized) {
                continue;
            }
            $mappedRows[] = $normalized;
        }

        return $this->groupByEmployeeAndDate($mappedRows);
    }

    /**
     * Localiza la fila de encabezados buscando columnas conocidas.
     * @return array{0: int|null, 1: array<int, string>}
     */
    private function detectHeaders(array $rawRows): array
    {
        $maxScan = min(5, count($rawRows));
        for ($i = 0; $i < $maxScan; $i++) {
            $candidate = array_map(fn($h) => $this->normalizeHeader((string) $h), $rawRows[$i]);
            $flat = array_filter($candidate);
            if (empty($flat)) {
                continue;
            }
            $hasId = $this->intersectAny($candidate, [
                'id', 'id_checador', 'llave_checador', 'checker_key',
                'id_checker', 'id_empleado', 'empleado_id_checador', 'llave',
            ]);
            $hasDate = $this->intersectAny($candidate, ['date', 'fecha', 'dia']);
            if ($hasId && $hasDate) {
                return [$i, $candidate];
            }
        }

        return [0, array_map(fn($h) => $this->normalizeHeader((string) $h), $rawRows[0])];
    }

    private function intersectAny(array $headers, array $needles): bool
    {
        foreach ($needles as $n) {
            if (in_array($n, $headers, true)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeHeader(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';
        return trim($normalized, '_');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $checkerKey = $this->firstString($row, [
            'id',
            'id_checador',
            'llave_checador',
            'checker_key',
            'id_checker',
            'id_empleado_checador',
            'id_empleado',
            'empleado_id_checador',
            'llave',
            'badge',
            'badge_number',
        ]);

        $dateRaw = $this->firstValue($row, ['fecha', 'date', 'dia']);
        if (! $checkerKey || $dateRaw === null || $dateRaw === '') {
            return null;
        }

        $date = $this->parseDate($dateRaw);
        if (! $date) {
            return null;
        }

        // Formato A: entrada/salida en la misma fila
        $checkIn = $this->parseTime($this->firstValue($row, ['entrada', 'check_in', 'hora_entrada', 'in']));
        $checkOut = $this->parseTime($this->firstValue($row, ['salida', 'check_out', 'hora_salida', 'out']));

        // Formato B: una sola columna "time" (una marcación por fila)
        $time = $this->parseTime($this->firstValue($row, ['time', 'hora', 'punch_time']));

        return [
            'checker_key' => strtoupper(trim($checkerKey)),
            'date' => $date,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'time' => $time,
            'punch_state' => $this->firstString($row, ['punch_state', 'estado_marcacion']),
            'status' => strtolower((string) ($this->firstString($row, ['status', 'estatus', 'estado']) ?? 'present')),
            'device' => $this->firstString($row, [
                'device_name',
                'dispositivo',
                'device',
                'terminal',
                'device_serial_no',
            ]),
            'notes' => $this->firstString($row, ['notes', 'notas', 'observaciones', 'remarks']),
        ];
    }

    /**
     * Agrupa filas por (checker_key, date). Cuando el archivo trae una marcación por
     * fila, toma la menor hora como entrada y la mayor como salida.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function groupByEmployeeAndDate(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $key = $row['checker_key'] . '|' . $row['date'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'checker_key' => $row['checker_key'],
                    'date' => $row['date'],
                    'check_in' => $row['check_in'],
                    'check_out' => $row['check_out'],
                    'status' => $row['status'],
                    'device' => $row['device'],
                    'notes' => $row['notes'],
                    'times' => [],
                ];
            }

            if (! empty($row['time'])) {
                $grouped[$key]['times'][] = $row['time'];
            }

            if (! empty($row['check_in'])) {
                $grouped[$key]['check_in'] = $row['check_in'];
            }
            if (! empty($row['check_out'])) {
                $grouped[$key]['check_out'] = $row['check_out'];
            }
            if (! empty($row['device']) && empty($grouped[$key]['device'])) {
                $grouped[$key]['device'] = $row['device'];
            }
        }

        $result = [];
        foreach ($grouped as $entry) {
            if (! empty($entry['times'])) {
                sort($entry['times']);
                $entry['check_in'] = $entry['check_in'] ?: $entry['times'][0];
                if (count($entry['times']) > 1) {
                    $entry['check_out'] = $entry['check_out'] ?: end($entry['times']);
                }
            }
            unset($entry['times']);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstString(array $row, array $keys): ?string
    {
        $value = $this->firstValue($row, $keys);
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float) $value);
                return Carbon::instance($dt)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float) $value);
                return Carbon::instance($dt)->format('H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->format('H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
