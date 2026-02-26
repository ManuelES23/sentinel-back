<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador del Checador de Asistencia
 * Este endpoint es para las terminales/kioscos de checado
 */
class TimeClockController extends Controller
{
    /**
     * Registrar entrada/salida mediante código QR
     */
    public function checkByQR(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_code' => 'required|string',
            'device_id' => 'nullable|string|max:100',
        ]);

        $employee = Employee::findByQRCode($validated['qr_code']);

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Código QR no válido o empleado inactivo',
                'type' => 'error',
            ], 404);
        }

        return $this->processCheck($employee, 'qr', $validated['device_id'] ?? null);
    }

    /**
     * Registrar entrada/salida mediante PIN
     */
    public function checkByPIN(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => 'required|string',
            'pin' => 'required|string|size:6',
            'device_id' => 'nullable|string|max:100',
        ]);

        $employee = Employee::where('employee_number', $validated['employee_number'])
            ->where('pin', $validated['pin'])
            ->where('status', Employee::STATUS_ACTIVE)
            ->first();

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Número de empleado o PIN incorrecto',
                'type' => 'error',
            ], 401);
        }

        return $this->processCheck($employee, 'pin', $validated['device_id'] ?? null);
    }

    /**
     * Procesar checada (entrada o salida automática)
     */
    private function processCheck(Employee $employee, string $method, ?string $deviceId): JsonResponse
    {
        try {
            $todayRecord = $employee->todayAttendance();
            $now = now();

            if (!$todayRecord || !$todayRecord->check_in) {
                // ENTRADA
                $record = AttendanceRecord::checkIn($employee, $method, $deviceId);

                return response()->json([
                    'success' => true,
                    'type' => 'check_in',
                    'message' => '¡Buenos días, ' . $employee->first_name . '!',
                    'data' => [
                        'employee' => [
                            'id' => $employee->id,
                            'employee_number' => $employee->employee_number,
                            'full_name' => $employee->full_name,
                            'photo_url' => $employee->photo_url,
                            'department' => $employee->department?->name,
                            'position' => $employee->position?->name,
                        ],
                        'check_time' => $now->format('H:i:s'),
                        'date' => $now->format('Y-m-d'),
                        'status' => $record->status,
                        'status_label' => $record->status_label,
                        'late_minutes' => $record->late_minutes,
                    ],
                ]);
            } elseif (!$todayRecord->check_out) {
                // SALIDA
                $record = AttendanceRecord::checkOut($employee, $method, $deviceId);

                return response()->json([
                    'success' => true,
                    'type' => 'check_out',
                    'message' => '¡Hasta mañana, ' . $employee->first_name . '!',
                    'data' => [
                        'employee' => [
                            'id' => $employee->id,
                            'employee_number' => $employee->employee_number,
                            'full_name' => $employee->full_name,
                            'photo_url' => $employee->photo_url,
                            'department' => $employee->department?->name,
                            'position' => $employee->position?->name,
                        ],
                        'check_in_time' => $record->check_in->format('H:i:s'),
                        'check_out_time' => $now->format('H:i:s'),
                        'hours_worked' => round($record->hours_worked, 2),
                        'status' => $record->status,
                        'status_label' => $record->status_label,
                    ],
                ]);
            } else {
                // Ya tiene entrada y salida
                return response()->json([
                    'success' => false,
                    'type' => 'already_complete',
                    'message' => 'Ya completaste tu registro del día',
                    'data' => [
                        'employee' => [
                            'full_name' => $employee->full_name,
                        ],
                        'check_in' => $todayRecord->check_in->format('H:i:s'),
                        'check_out' => $todayRecord->check_out->format('H:i:s'),
                    ],
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'type' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Consultar estado del empleado (sin checar)
     */
    public function getStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_code' => 'required_without:employee_number|string',
            'employee_number' => 'required_without:qr_code|string',
        ]);

        if (isset($validated['qr_code'])) {
            $employee = Employee::findByQRCode($validated['qr_code']);
        } else {
            $employee = Employee::where('employee_number', $validated['employee_number'])
                ->where('status', Employee::STATUS_ACTIVE)
                ->first();
        }

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        $todayRecord = $employee->todayAttendance();

        return response()->json([
            'success' => true,
            'data' => [
                'employee' => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'photo_url' => $employee->photo_url,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->name,
                    'enterprise' => $employee->enterprise?->name,
                ],
                'today' => $todayRecord ? [
                    'date' => $todayRecord->date->format('Y-m-d'),
                    'check_in' => $todayRecord->check_in?->format('H:i:s'),
                    'check_out' => $todayRecord->check_out?->format('H:i:s'),
                    'status' => $todayRecord->status,
                    'status_label' => $todayRecord->status_label,
                    'hours_worked' => $todayRecord->hours_worked,
                ] : null,
                'can_check_in' => !$todayRecord || !$todayRecord->check_in,
                'can_check_out' => $todayRecord && $todayRecord->check_in && !$todayRecord->check_out,
            ],
        ]);
    }

    /**
     * Obtener hora actual del servidor (para sincronizar terminal)
     */
    public function serverTime(): JsonResponse
    {
        $now = now();

        return response()->json([
            'success' => true,
            'data' => [
                'datetime' => $now->toISOString(),
                'date' => $now->format('Y-m-d'),
                'time' => $now->format('H:i:s'),
                'timezone' => config('app.timezone'),
                'timestamp' => $now->timestamp,
            ],
        ]);
    }

    /**
     * Obtener lista de empleados que han checado hoy (para mostrar en pantalla del kiosco)
     */
    public function todayChecks(Request $request): JsonResponse
    {
        $enterpriseId = $request->get('enterprise_id');

        $query = AttendanceRecord::with(['employee'])
            ->today()
            ->whereNotNull('check_in');

        if ($enterpriseId) {
            $query->whereHas('employee', function ($q) use ($enterpriseId) {
                $q->where('enterprise_id', $enterpriseId);
            });
        }

        $records = $query->orderBy('check_in', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($record) {
                return [
                    'employee_name' => $record->employee->full_name,
                    'employee_photo' => $record->employee->photo_url,
                    'department' => $record->employee->department?->name,
                    'check_in' => $record->check_in->format('H:i'),
                    'check_out' => $record->check_out?->format('H:i'),
                    'status' => $record->status,
                    'status_label' => $record->status_label,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }
}
