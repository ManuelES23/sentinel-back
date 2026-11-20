<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use App\Models\SfPositionAssignment;
use App\Services\AttendanceSpreadsheetService;
use App\Services\SplendidFarms\SfAttendanceImportService;
use App\Services\SplendidFarms\SfDailyPositionService;
use App\Services\SplendidFarms\SfPayrollCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SfAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceSpreadsheetService $spreadsheetService,
        private readonly SfAttendanceImportService $importService,
        private readonly SfDailyPositionService $dailyPositions,
        private readonly SfPayrollCalculator $payrollCalculator,
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

        // Sin asignación ese día, el permanente muestra su puesto fijo del alta.
        $fixedMap = [];
        if ($employeeIds->isNotEmpty()) {
            $employees = SfEmployee::query()
                ->whereIn('id', $employeeIds)
                ->get(['id', 'enterprise_id', 'employee_type', 'position']);
            foreach ($employees->groupBy('enterprise_id') as $enterpriseId => $group) {
                $positions = $this->dailyPositions->positions((int) $enterpriseId);
                foreach ($this->dailyPositions->fixedPositions($group, $positions) as $employeeId => $position) {
                    $fixedMap[$employeeId] = ['code' => $position->code, 'name' => $position->name];
                }
            }
        }

        $records->setCollection(
            $collection->map(function (SfAttendanceRecord $record) use ($assignmentMap, $fixedMap) {
                $dateKey = $record->date?->toDateString();
                $mapKey = $record->sf_employee_id . '|' . $dateKey;
                $position = $assignmentMap[$mapKey] ?? $fixedMap[$record->sf_employee_id] ?? null;

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

        $rows = $this->payrollCalculator->rows(
            (int) $validated['enterprise_id'],
            $validated['start_date'],
            $validated['end_date'],
        );

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
     * Hoja de puestos del día: personal activo con su puesto, pago y si ya checó.
     */
    public function dailyPositions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'date' => 'required|date_format:Y-m-d',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->dailyPositions->sheet((int) $validated['enterprise_id'], $validated['date']),
        ]);
    }

    /**
     * Asigna (o quita, con sf_position_id null) el puesto del día a uno o varios empleados.
     */
    public function assignDailyPositions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'date' => 'required|date_format:Y-m-d',
            'employee_ids' => 'required|array|min:1|max:1000',
            'employee_ids.*' => 'integer',
            'sf_position_id' => 'nullable|integer',
        ]);

        $rows = $this->dailyPositions->assign(
            (int) $validated['enterprise_id'],
            $validated['date'],
            $validated['employee_ids'],
            isset($validated['sf_position_id']) ? (int) $validated['sf_position_id'] : null,
            $request->user()?->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Puesto del día actualizado',
            'data' => ['employees' => $rows],
        ]);
    }

    /**
     * Copia a los temporales sin puesto el puesto del día anterior con asignaciones.
     */
    public function copyPreviousDailyPositions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'date' => 'required|date_format:Y-m-d',
        ]);

        $result = $this->dailyPositions->copyPrevious(
            (int) $validated['enterprise_id'],
            $validated['date'],
            $request->user()?->id,
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
