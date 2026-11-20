<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use App\Models\SfPosition;
use App\Models\SfPositionAssignment;
use App\Services\AttendanceSpreadsheetService;
use App\Services\SplendidFarms\SfAttendanceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SfAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceSpreadsheetService $spreadsheetService,
        private readonly SfAttendanceImportService $importService,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = SfAttendanceRecord::query()
            ->with('employee:id,code,checker_key,first_name,last_name,second_last_name')
            ->when($request->enterprise_id, function ($q, $enterpriseId) {
                $q->whereHas('employee', fn($sq) => $sq->where('enterprise_id', $enterpriseId));
            })
            ->when($request->sf_employee_id, fn($q, $id) => $q->where('sf_employee_id', $id))
            ->when($request->start_date && $request->end_date, fn($q) => $q->whereBetween('date', [$request->start_date, $request->end_date]))
            ->orderBy('date', 'desc');

        $perPage = (int) $request->get('per_page', 50);
        $records = $query->paginate($perPage);

        $collection = $records->getCollection();
        $employeeIds = $collection->pluck('sf_employee_id')->filter()->unique()->values();
        $dates = $collection->map(function (SfAttendanceRecord $record) {
            return $record->date?->toDateString();
        })->filter()->unique()->values();

        $assignmentMap = [];
        if ($employeeIds->isNotEmpty() && $dates->isNotEmpty()) {
            $assignments = SfPositionAssignment::query()
                ->with('position:id,code,name')
                ->whereIn('sf_employee_id', $employeeIds)
                ->whereIn('assignment_date', $dates)
                ->get();

            foreach ($assignments as $assignment) {
                $key = $assignment->sf_employee_id . '|' . $assignment->assignment_date?->toDateString();
                $assignmentMap[$key] = [
                    'code' => $assignment->position?->code,
                    'name' => $assignment->position?->name,
                ];
            }
        }

        $records->setCollection(
            $collection->map(function (SfAttendanceRecord $record) use ($assignmentMap) {
                $dateKey = $record->date?->toDateString();
                $mapKey = $record->sf_employee_id . '|' . $dateKey;
                $position = $assignmentMap[$mapKey] ?? null;

                $record->setAttribute('assigned_position_code', $position['code'] ?? null);
                $record->setAttribute('assigned_position_name', $position['name'] ?? null);

                return $record;
            })
        );

        return response()->json([
            'success' => true,
            'data' => $records,
        ]);
    }

    /**
     * Importa el Excel del checador (export «Transaction» o archivo con entrada/salida)
     * sin generar corte de nómina. Si el empleado ya tiene registro ese día, se reemplaza.
     */
    public function importExcel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        $file = $request->file('file');
        $allowedExtensions = ['xlsx', 'xls', 'csv', 'txt', 'ods'];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions, true)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo debe ser: ' . implode(', ', $allowedExtensions),
            ], 422);
        }

        $rows = $this->spreadsheetService->parse($file);
        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron marcaciones en el archivo. Revisa que tenga las columnas ID, Date y Time del checador.',
            ], 422);
        }

        $summary = DB::transaction(fn () => $this->importService->import(
            $rows,
            (int) $validated['enterprise_id'],
            $file->getClientOriginalName(),
            $request->user()?->id,
        ));

        return response()->json([
            'success' => true,
            'message' => 'Importación de asistencias SF completada',
            'data' => $summary,
        ]);
    }

    public function payrollSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        $employees = SfEmployee::query()
            ->where('enterprise_id', $validated['enterprise_id'])
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->get();

        $rows = [];

        foreach ($employees as $employee) {
            $records = SfAttendanceRecord::query()
                ->where('sf_employee_id', $employee->id)
                ->whereBetween('date', [$validated['start_date'], $validated['end_date']])
                ->get();

            $effectiveDays = $records->sum(function (SfAttendanceRecord $record) {
                return match ($record->status) {
                    'present', 'late', 'early_leave' => 1,
                    'half_day' => 0.5,
                    default => 0,
                };
            });

            $dailyRate = (float) ($employee->daily_rate ?? 0);
            if ($dailyRate <= 0 && $employee->salary) {
                $dailyRate = match ($employee->payment_frequency) {
                    'weekly' => ((float) $employee->salary) / 7,
                    'monthly' => ((float) $employee->salary) / 30,
                    default => ((float) $employee->salary) / 15,
                };
            }

            $gross = round($effectiveDays * $dailyRate, 2);

            $rows[] = [
                'sf_employee_id' => $employee->id,
                'code' => $employee->code,
                'checker_key' => $employee->checker_key,
                'full_name' => $employee->full_name,
                'payment_frequency' => $employee->payment_frequency,
                'salary' => (float) ($employee->salary ?? 0),
                'daily_rate' => round($dailyRate, 2),
                'effective_days' => $effectiveDays,
                'gross_pay' => $gross,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $validated['start_date'],
                    'end' => $validated['end_date'],
                ],
                'employees' => $rows,
                'totals' => [
                    'employees' => count($rows),
                    'gross_pay' => round(collect($rows)->sum('gross_pay'), 2),
                ],
            ],
        ]);
    }

    /**
     * Listar asignaciones de puesto por fecha (operativa diaria de personal temporal).
     */
    public function positionAssignments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'assignment_date' => 'nullable|date',
        ]);

        $assignmentDate = $validated['assignment_date'] ?? now()->toDateString();

        $items = SfPositionAssignment::query()
            ->with([
                'employee:id,enterprise_id,code,checker_key,first_name,last_name,second_last_name',
                'position:id,enterprise_id,code,name,department',
                'assignedBy:id,name',
            ])
            ->where('assignment_date', $assignmentDate)
            ->whereHas('employee', fn($q) => $q->where('enterprise_id', $validated['enterprise_id']))
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Escanear QR/llave de checador y asignar puesto del día.
     */
    public function assignPositionByQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'sf_position_id' => 'required|exists:sf_positions,id',
            'qr_code' => 'required|string|max:120',
            'assignment_date' => 'nullable|date',
            'source_device' => 'nullable|string|max:100',
        ]);

        $position = SfPosition::query()
            ->where('id', $validated['sf_position_id'])
            ->where('enterprise_id', $validated['enterprise_id'])
            ->first();

        if (! $position) {
            return response()->json([
                'status' => 'error',
                'message' => 'El puesto seleccionado no pertenece a la empresa activa',
            ], 422);
        }

        $qrCode = strtoupper(trim($validated['qr_code']));

        $employee = SfEmployee::query()
            ->where('enterprise_id', $validated['enterprise_id'])
            ->where(function ($query) use ($qrCode) {
                $query->whereRaw('UPPER(checker_key) = ?', [$qrCode])
                    ->orWhereRaw('UPPER(code) = ?', [$qrCode]);
            })
            ->first();

        if (! $employee) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró personal con ese QR/ID checador en la empresa seleccionada',
            ], 404);
        }

        $assignmentDate = $validated['assignment_date'] ?? now()->toDateString();

        $assignment = SfPositionAssignment::updateOrCreate(
            [
                'sf_employee_id' => $employee->id,
                'assignment_date' => $assignmentDate,
            ],
            [
                'sf_position_id' => $position->id,
                'assigned_at' => now(),
                'assigned_by_user_id' => $request->user()?->id,
                'source_device' => $validated['source_device'] ?? null,
                'qr_code_raw' => $qrCode,
            ]
        );

        $assignment->load([
            'employee:id,enterprise_id,code,checker_key,first_name,last_name,second_last_name',
            'position:id,enterprise_id,code,name,department',
            'assignedBy:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Asignación de puesto registrada correctamente',
            'data' => $assignment,
        ]);
    }
}
