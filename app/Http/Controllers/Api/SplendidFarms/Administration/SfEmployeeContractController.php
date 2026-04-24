<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfEmployee;
use App\Models\SfEmployeeContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SfEmployeeContractController extends Controller
{
    /**
     * Listar contratos (filtrable por empleado).
     */
    public function index(Request $request): JsonResponse
    {
        $query = SfEmployeeContract::query()
            ->with(['employee:id,code,first_name,last_name,second_last_name', 'generatedBy:id,name'])
            ->when($request->sf_employee_id, fn($q, $v) => $q->where('sf_employee_id', $v))
            ->when($request->status, fn($q, $v) => $q->where('status', $v))
            ->when($request->contract_type, fn($q, $v) => $q->where('contract_type', $v))
            ->orderByDesc('created_at');

        $perPage = (int) $request->get('per_page', 15);
        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    /**
     * Crear contrato (snapshot del empleado al momento).
     * La generación de PDF/DOCX se realiza en el frontend.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sf_employee_id' => 'required|exists:sf_employees,id',
            'contract_type' => 'required|in:permanent,temporary',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:draft,active,terminated,archived',
        ]);

        $employee = SfEmployee::findOrFail($validated['sf_employee_id']);

        $lastVersion = SfEmployeeContract::where('sf_employee_id', $employee->id)->max('version');
        $version = ($lastVersion ?? 0) + 1;

        $contract = SfEmployeeContract::create([
            'sf_employee_id' => $employee->id,
            'code' => SfEmployeeContract::generateCode(),
            'version' => $version,
            'contract_type' => $validated['contract_type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'snapshot_full_name' => $employee->full_name,
            'snapshot_curp' => $employee->curp,
            'snapshot_rfc' => $employee->rfc,
            'snapshot_nss' => $employee->nss,
            'snapshot_position' => $employee->position,
            'snapshot_department' => $employee->department,
            'snapshot_work_location' => $employee->work_location,
            'snapshot_salary' => $employee->salary,
            'snapshot_daily_rate' => $employee->daily_rate,
            'snapshot_weekly_hours' => $employee->weekly_hours,
            'snapshot_payment_frequency' => $employee->payment_frequency,
            'status' => $validated['status'] ?? SfEmployeeContract::STATUS_ACTIVE,
            'notes' => $validated['notes'] ?? null,
            'generated_at' => now(),
            'generated_by_user_id' => $request->user()?->id,
        ]);

        $contract->load(['employee:id,code,first_name,last_name,second_last_name']);

        return response()->json([
            'success' => true,
            'message' => 'Contrato creado exitosamente',
            'data' => $contract,
        ], 201);
    }

    public function show(SfEmployeeContract $contract): JsonResponse
    {
        $contract->load(['employee', 'generatedBy:id,name']);
        return response()->json(['success' => true, 'data' => $contract]);
    }

    public function update(Request $request, SfEmployeeContract $contract): JsonResponse
    {
        $validated = $request->validate([
            'contract_type' => 'sometimes|required|in:permanent,temporary',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:draft,active,terminated,archived',
            'notes' => 'nullable|string',
        ]);

        $contract->update($validated);
        $contract->load(['employee:id,code,first_name,last_name,second_last_name']);

        return response()->json([
            'success' => true,
            'message' => 'Contrato actualizado exitosamente',
            'data' => $contract,
        ]);
    }

    public function destroy(SfEmployeeContract $contract): JsonResponse
    {
        $contract->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contrato eliminado exitosamente',
        ]);
    }
}
