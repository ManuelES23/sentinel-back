<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfFieldCheck;
use App\Services\AttendanceConsolidationService;
use App\Services\SplendidFarms\ChecadorCampo\PaqueteCuadrillaSf;
use App\Services\SplendidFarms\ChecadorCampo\RegistradorChequeosCampo;
use App\Traits\AuthorizesEnterpriseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SfFieldCheckController extends Controller
{
    use AuthorizesEnterpriseAccess;

    public function __construct(
        private readonly AttendanceConsolidationService $consolidationService,
        private readonly PaqueteCuadrillaSf $paquete,
        private readonly RegistradorChequeosCampo $registrador,
    ) {
    }

    /**
     * Paquete de cuadrilla: empleados activos y enrolados de la empresa, con embedding y miniatura.
     */
    public function crewPackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        return response()->json([
            'success' => true,
            'data' => $this->paquete->armar((int) $validated['enterprise_id']),
        ]);
    }

    /**
     * Sincroniza un lote de chequeos de campo. Idempotente por client_uuid.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge(
            ['enterprise_id' => 'required|exists:enterprises,id'],
            $this->registrador->reglas((int) $request->input('enterprise_id')),
        ));

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $results = $this->registrador->registrar(
            $validated['checks'],
            (int) $validated['enterprise_id'],
            ['checked_by_user_id' => $request->user()->id],
        );

        return response()->json(['success' => true, 'data' => ['results' => $results]]);
    }

    /**
     * Listado paginado de chequeos de campo, con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'sf_employee_id' => 'nullable|exists:sf_employees,id',
            'verification_status' => 'nullable|in:pending,verified,low_confidence,mismatch,no_template,manually_approved,rejected',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $validated['enterprise_id']);

        $query = SfFieldCheck::query()
            ->with(['employee:id,enterprise_id,code,first_name,last_name,second_last_name', 'checkedBy:id,name', 'rescuedBy:id,name'])
            ->where('enterprise_id', $validated['enterprise_id'])
            ->when($validated['sf_employee_id'] ?? null, fn ($q, $v) => $q->where('sf_employee_id', $v))
            ->when($validated['verification_status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->when(($validated['start_date'] ?? null) && ($validated['end_date'] ?? null), fn ($q) => $q->whereBetween('checked_at', [$validated['start_date'], $validated['end_date']]))
            ->orderByDesc('checked_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    /**
     * RH aprueba o rechaza un chequeo pendiente de revisión humana
     * (low_confidence | mismatch | no_template). Aprobar puede reasignar el
     * empleado (obligatorio si el check no trae uno) y consolida en
     * sf_attendance_records. Rechazar es terminal, nunca consolida.
     */
    public function review(Request $request, SfFieldCheck $fieldCheck): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approve,reject',
            'sf_employee_id' => [
                'nullable',
                Rule::exists('sf_employees', 'id')->where(
                    fn ($query) => $query->where('enterprise_id', $fieldCheck->enterprise_id)
                ),
            ],
        ]);

        $this->authorizeEnterpriseAccess($request, (int) $fieldCheck->enterprise_id);

        $reviewableStatuses = [
            SfFieldCheck::STATUS_LOW_CONFIDENCE,
            SfFieldCheck::STATUS_MISMATCH,
            SfFieldCheck::STATUS_NO_TEMPLATE,
        ];
        abort_unless(
            in_array($fieldCheck->verification_status, $reviewableStatuses, true),
            422,
            'Este chequeo ya fue resuelto o aún no ha sido procesado.'
        );

        if ($validated['decision'] === 'reject') {
            $fieldCheck->update([
                'verification_status' => SfFieldCheck::STATUS_REJECTED,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chequeo rechazado',
                'data' => $fieldCheck->fresh(),
            ]);
        }

        // approve: sfEmployeeId (si viene) siempre reemplaza el empleado
        // actual del check — necesario para 'mismatch' (el empleado que trae
        // hoy es justo el que el servidor determinó que NO coincide) y para
        // 'no_template' (no trae ninguno).
        $employeeId = $validated['sf_employee_id'] ?? $fieldCheck->sf_employee_id;
        abort_if(
            $employeeId === null,
            422,
            'Debes asignar un empleado antes de aprobar un chequeo sin coincidencia.'
        );

        $fieldCheck->update([
            'sf_employee_id' => $employeeId,
            'verification_status' => SfFieldCheck::STATUS_MANUALLY_APPROVED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->consolidationService->consolidate($fieldCheck->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Chequeo aprobado',
            'data' => $fieldCheck->fresh(),
        ]);
    }

    /**
     * Sirve la foto de evidencia de un chequeo, autenticada y con el mismo
     * guard de empresa que el resto del módulo. 404 si ya fue purgada.
     */
    public function evidencePhoto(Request $request, SfFieldCheck $fieldCheck)
    {
        $this->authorizeEnterpriseAccess($request, (int) $fieldCheck->enterprise_id);

        if (! $fieldCheck->evidence_photo_path || ! Storage::disk('local')->exists($fieldCheck->evidence_photo_path)) {
            abort(404, 'La foto de evidencia ya no está disponible.');
        }

        return Storage::disk('local')->response($fieldCheck->evidence_photo_path, null, [
            'Content-Type' => 'image/jpeg',
        ]);
    }
}
