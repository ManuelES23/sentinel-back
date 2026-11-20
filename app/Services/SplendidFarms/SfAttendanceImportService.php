<?php

namespace App\Services\SplendidFarms;

use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use Carbon\Carbon;

/**
 * Guarda en sf_attendance_records las filas ya parseadas por
 * AttendanceSpreadsheetService (una por empleado y día). Lo usan Asistencia SF
 * (importar-excel) y Nómina SF (procesar-archivo), para que ambas reconozcan
 * igual a los empleados.
 *
 * Reconocimiento tolerante: el checador exporta "0000000153" y el empleado puede
 * estar guardado como "153"; también se prueba con el código de empleado. Si una
 * misma llave sirve para la clave de un empleado y el código de otro, gana la
 * clave del checador.
 */
class SfAttendanceImportService
{
    private const VALID_STATUSES = [
        'present',
        'absent',
        'late',
        'early_leave',
        'half_day',
        'holiday',
        'sick_leave',
    ];

    /**
     * @param array<int, array<string, mixed>> $rows Salida de AttendanceSpreadsheetService::parse()
     * @return array{total_rows: int, created: int, updated: int, not_matched: int, invalid_rows: int, unmatched_keys: array<int, string>, unmatched_employees: array<int, array{key: string, name: ?string, days: int}>, imported_dates: array<int, string>, start_date: ?string, end_date: ?string}
     */
    public function import(array $rows, int $enterpriseId, string $sourceFile, ?int $userId): array
    {
        $employeeMap = $this->buildEmployeeMap($enterpriseId);

        $created = 0;
        $updated = 0;
        $notMatched = 0;
        $invalidRows = 0;
        $unmatched = [];
        $importedDates = [];

        foreach ($rows as $row) {
            $rawKey = strtoupper(trim((string) $row['checker_key']));
            $employee = $employeeMap[$rawKey] ?? $employeeMap[ltrim($rawKey, '0')] ?? null;

            if (! $employee) {
                $notMatched++;
                $unmatched[$rawKey] ??= ['key' => $rawKey, 'name' => $row['name'] ?? null, 'days' => 0];
                $unmatched[$rawKey]['days']++;
                continue;
            }

            try {
                $checkIn = $row['check_in'] ? Carbon::parse($row['date'] . ' ' . $row['check_in']) : null;
                $checkOut = $row['check_out'] ? Carbon::parse($row['date'] . ' ' . $row['check_out']) : null;
                if ($checkIn && $checkOut && $checkOut->lessThan($checkIn)) {
                    $checkOut->addDay();
                }
            } catch (\Throwable) {
                $invalidRows++;
                continue;
            }

            $payload = [
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'status' => in_array($row['status'], self::VALID_STATUSES, true) ? $row['status'] : 'present',
                'source_file' => $sourceFile,
                'source_device' => $row['device'],
                // El export «Transaction» pone "--" en Remarks cuando no hay nada.
                'notes' => in_array(trim((string) $row['notes']), ['', '--', '-'], true) ? null : $row['notes'],
                'imported_by_user_id' => $userId,
            ];

            if ($checkIn && $checkOut) {
                $payload['hours_worked'] = $checkIn->diffInMinutes($checkOut) / 60;
            }

            $record = SfAttendanceRecord::updateOrCreate(
                ['sf_employee_id' => $employee->id, 'date' => $row['date']],
                $payload
            );

            $record->wasRecentlyCreated ? $created++ : $updated++;
            $importedDates[$row['date']] = true;
        }

        $dates = array_keys($importedDates);
        sort($dates);

        return [
            'total_rows' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'not_matched' => $notMatched,
            'invalid_rows' => $invalidRows,
            'unmatched_keys' => array_slice(array_keys($unmatched), 0, 50),
            // Con nombre del checador y días, para saber a quién capturarle la clave.
            'unmatched_employees' => array_slice(array_values($unmatched), 0, 200),
            'imported_dates' => $dates,
            'start_date' => $dates[0] ?? null,
            'end_date' => $dates ? end($dates) : null,
        ];
    }

    /**
     * @return array<string, SfEmployee>
     */
    private function buildEmployeeMap(int $enterpriseId): array
    {
        $employees = SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->get(['id', 'code', 'checker_key']);

        $map = [];
        // Primero códigos y luego claves del checador: la clave sobrescribe y gana.
        foreach (['code', 'checker_key'] as $field) {
            foreach ($employees as $employee) {
                $value = strtoupper(trim((string) $employee->{$field}));
                foreach ([$value, ltrim($value, '0')] as $key) {
                    if ($key !== '') {
                        $map[$key] = $employee;
                    }
                }
            }
        }

        return $map;
    }
}
