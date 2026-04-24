<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfAttendanceRecord;
use App\Models\SfEmployee;
use App\Services\AttendanceSpreadsheetService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SfAttendanceController extends Controller
{
    public function __construct(private readonly AttendanceSpreadsheetService $spreadsheetService)
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

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    public function importExcel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        $rows = $this->spreadsheetService->parse($request->file('file'));

        $created = 0;
        $updated = 0;
        $notMatched = 0;
        $invalidRows = 0;

        foreach ($rows as $row) {
            $employee = SfEmployee::query()
                ->where('enterprise_id', $validated['enterprise_id'])
                ->where('checker_key', $row['checker_key'])
                ->first();

            if (! $employee) {
                $notMatched++;
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
                'status' => in_array($row['status'], [
                    'present',
                    'absent',
                    'late',
                    'early_leave',
                    'half_day',
                    'holiday',
                    'sick_leave',
                ], true) ? $row['status'] : 'present',
                'source_file' => $request->file('file')->getClientOriginalName(),
                'source_device' => $row['device'],
                'notes' => $row['notes'],
                'imported_by_user_id' => $request->user()?->id,
            ];

            if ($checkIn && $checkOut) {
                $payload['hours_worked'] = $checkIn->diffInMinutes($checkOut) / 60;
            }

            $record = SfAttendanceRecord::updateOrCreate(
                ['sf_employee_id' => $employee->id, 'date' => $row['date']],
                $payload
            );

            if ($record->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Importación de asistencias SF completada',
            'data' => [
                'total_rows' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'not_matched' => $notMatched,
                'invalid_rows' => $invalidRows,
            ],
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
}
