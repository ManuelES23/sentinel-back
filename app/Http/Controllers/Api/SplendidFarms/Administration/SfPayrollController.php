<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use App\Models\SfPayrollRun;
use App\Services\AttendanceSpreadsheetService;
use App\Services\SplendidFarms\SfAttendanceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SfPayrollController extends Controller
{
    public function __construct(
        private readonly AttendanceSpreadsheetService $spreadsheetService,
        private readonly SfAttendanceImportService $importService,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $runs = SfPayrollRun::query()
            ->where('enterprise_id', $validated['enterprise_id'])
            ->with(['generatedBy:id,name'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $runs,
        ]);
    }

    public function show(Request $request, SfPayrollRun $nomina): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        if ((int) $nomina->enterprise_id !== (int) $validated['enterprise_id']) {
            return response()->json([
                'status' => 'error',
                'message' => 'El corte de nómina no pertenece a la empresa seleccionada.',
            ], 403);
        }

        $nomina->load([
            'generatedBy:id,name',
            'items.employee:id,code,checker_key,first_name,last_name,second_last_name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $nomina,
        ]);
    }

    /**
     * Procesa archivo de checador y guarda histórico de nómina en un solo paso.
     */
    public function processFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'enterprise_id' => 'required|exists:enterprises,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        // Validar extensión permitida después de obtener el archivo
        $file = $request->file('file');
        $allowedExtensions = ['xlsx', 'xls', 'csv', 'txt', 'ods'];
        $ext = strtolower($file->getClientOriginalExtension());
        
        if (!in_array($ext, $allowedExtensions)) {
            return response()->json([
                'status' => 'error',
                'message' => 'El archivo debe tener una extensión válida: ' . implode(', ', $allowedExtensions),
            ], 422);
        }

        $rows = $this->spreadsheetService->parse($request->file('file'));

        // Mismo reconocimiento tolerante que Asistencia SF (clave con/sin ceros, código).
        $importSummary = $this->importService->import(
            $rows,
            (int) $validated['enterprise_id'],
            $request->file('file')->getClientOriginalName(),
            $request->user()?->id,
        );

        $payrollRows = $this->buildPayrollRows(
            $validated['enterprise_id'],
            $validated['start_date'],
            $validated['end_date']
        );

        $run = $this->persistPayrollRun(
            enterpriseId: (int) $validated['enterprise_id'],
            startDate: $validated['start_date'],
            endDate: $validated['end_date'],
            rows: $payrollRows,
            sourceFile: $request->file('file')->getClientOriginalName(),
            generatedByUserId: $request->user()?->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Archivo procesado y nómina guardada en histórico.',
            'data' => [
                'import' => $importSummary,
                'payroll_run' => [
                    'id' => $run->id,
                    'start_date' => $run->start_date?->format('Y-m-d'),
                    'end_date' => $run->end_date?->format('Y-m-d'),
                    'total_employees' => $run->total_employees,
                    'total_gross_pay' => (float) $run->total_gross_pay,
                    'source_file' => $run->source_file,
                ],
            ],
        ]);
    }

    private function buildPayrollRows(int $enterpriseId, string $startDate, string $endDate): array
    {
        $employees = SfEmployee::query()
            ->where('enterprise_id', $enterpriseId)
            ->where('status', SfEmployee::STATUS_ACTIVE)
            ->get();

        $rows = [];

        foreach ($employees as $employee) {
            $records = SfAttendanceRecord::query()
                ->where('sf_employee_id', $employee->id)
                ->whereBetween('date', [$startDate, $endDate])
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
                'daily_rate' => round($dailyRate, 4),
                'effective_days' => round($effectiveDays, 2),
                'gross_pay' => $gross,
            ];
        }

        return $rows;
    }

    private function persistPayrollRun(
        int $enterpriseId,
        string $startDate,
        string $endDate,
        array $rows,
        ?string $sourceFile,
        ?int $generatedByUserId,
    ): SfPayrollRun {
        return DB::transaction(function () use (
            $enterpriseId,
            $startDate,
            $endDate,
            $rows,
            $sourceFile,
            $generatedByUserId
        ) {
            $run = SfPayrollRun::create([
                'enterprise_id' => $enterpriseId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'source_file' => $sourceFile,
                'total_employees' => count($rows),
                'total_gross_pay' => round(collect($rows)->sum('gross_pay'), 2),
                'generated_by_user_id' => $generatedByUserId,
            ]);

            foreach ($rows as $row) {
                $run->items()->create($row);
            }

            return $run;
        });
    }
}
