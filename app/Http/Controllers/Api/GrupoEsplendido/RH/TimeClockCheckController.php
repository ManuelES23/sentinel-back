<?php
// sentinel-back/app/Http/Controllers/Api/GrupoEsplendido/RH/TimeClockCheckController.php
namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\TimeClockCheck;
use App\Models\UserEnterpriseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TimeClockCheckController extends Controller
{
    private function authorizeEnterpriseAccess(Request $request, int $enterpriseId): void
    {
        abort_unless(
            UserEnterpriseAccess::where('user_id', $request->user()->id)
                ->where('enterprise_id', $enterpriseId)
                ->where('is_active', true)
                ->exists(),
            403,
            'No tienes acceso a esta empresa'
        );
    }

    /**
     * Listado de chequeos biométricos con filtros — usado por la bandeja de
     * revisión y por vistas de auditoría.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'nullable|exists:enterprises,id',
            'employee_id' => 'nullable|exists:employees,id',
            'verification_status' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = TimeClockCheck::query()
            ->with('employee:id,enterprise_id,employee_number,first_name,last_name,second_last_name');

        if ($validated['enterprise_id'] ?? null) {
            $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

            $query->whereHas('employee', fn ($q) => $q->where('enterprise_id', $validated['enterprise_id']));
        } else {
            // RH es una app corporativa multi-empresa: sin enterprise_id
            // explícito, se escala a todas las empresas donde el usuario
            // tiene acceso activo (UserEnterpriseAccess), no solo a la
            // empresa dueña de la app RH.
            $enterpriseIds = UserEnterpriseAccess::where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->pluck('enterprise_id');

            $query->whereHas('employee', fn ($q) => $q->whereIn('enterprise_id', $enterpriseIds));
        }

        $query->when($validated['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($validated['verification_status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->when(
                ($validated['start_date'] ?? null) && ($validated['end_date'] ?? null),
                fn ($q) => $q->whereBetween('checked_at', [$validated['start_date'], $validated['end_date']])
            )
            ->orderByDesc('checked_at');

        $perPage = $validated['per_page'] ?? 20;

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    /**
     * RH aprueba o rechaza un chequeo pendiente de revisión humana
     * (low_confidence | no_template). No hay reasignación de empleado —
     * la identidad ya se resolvió por número+PIN antes de crear el
     * registro (ver Plan 2, TimeClockController::sync()).
     */
    public function review(Request $request, TimeClockCheck $timeClockCheck): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
        ]);

        $employee = Employee::findOrFail($timeClockCheck->employee_id);
        $this->authorizeEnterpriseAccess($request, (int) $employee->enterprise_id);

        $reviewableStatuses = [
            TimeClockCheck::STATUS_LOW_CONFIDENCE,
            TimeClockCheck::STATUS_NO_TEMPLATE,
        ];
        abort_unless(
            in_array($timeClockCheck->verification_status, $reviewableStatuses, true),
            422,
            'Este chequeo ya fue resuelto o aún no ha sido procesado.'
        );

        if ($validated['decision'] === 'reject') {
            $timeClockCheck->update([
                'verification_status' => TimeClockCheck::STATUS_REJECTED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chequeo rechazado',
                'data' => $timeClockCheck->fresh(),
            ]);
        }

        try {
            if ($timeClockCheck->type === TimeClockCheck::TYPE_CHECK_IN) {
                AttendanceRecord::checkIn($employee, 'biometric', null, $timeClockCheck->checked_at);
            } else {
                AttendanceRecord::checkOut($employee, 'biometric', null, $timeClockCheck->checked_at);
            }
        } catch (\Exception $e) {
            abort(422, $e->getMessage());
        }

        $timeClockCheck->update([
            'verification_status' => TimeClockCheck::STATUS_MANUALLY_APPROVED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Chequeo aprobado',
            'data' => $timeClockCheck->fresh(),
        ]);
    }

    /**
     * Sirve la foto de evidencia de un chequeo, autenticada y scoped a la
     * empresa del empleado. 404 si ya fue purgada.
     */
    public function evidencePhoto(Request $request, TimeClockCheck $timeClockCheck)
    {
        $employee = Employee::findOrFail($timeClockCheck->employee_id);
        $this->authorizeEnterpriseAccess($request, (int) $employee->enterprise_id);

        if (! $timeClockCheck->evidence_photo_path || ! Storage::disk('local')->exists($timeClockCheck->evidence_photo_path)) {
            abort(404, 'La foto de evidencia ya no está disponible.');
        }

        return Storage::disk('local')->response($timeClockCheck->evidence_photo_path, null, [
            'Content-Type' => 'image/jpeg',
        ]);
    }
}
