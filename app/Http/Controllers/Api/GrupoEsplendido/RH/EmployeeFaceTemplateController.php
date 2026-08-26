<?php
// sentinel-back/app/Http/Controllers/Api/GrupoEsplendido/RH/EmployeeFaceTemplateController.php
namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Exceptions\FaceRecognitionException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeFaceTemplate;
use App\Models\UserEnterpriseAccess;
use App\Services\FaceRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmployeeFaceTemplateController extends Controller
{
    public function __construct(private readonly FaceRecognitionService $faceService)
    {
    }

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
     * Enrolar (o re-enrolar) la plantilla facial de un empleado de Grupo Espléndido.
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEnterpriseAccess($request, (int) $employee->enterprise_id);

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png|max:5120',
            'consent_signed' => 'required|accepted',
            'consent_document' => 'nullable|file|mimes:pdf,jpeg,jpg,png|max:5120',
        ]);

        try {
            $result = $this->faceService->embed(
                $request->file('photo')->get(),
                $request->file('photo')->getClientOriginalName()
            );
        } catch (FaceRecognitionException $e) {
            $messages = [
                'no_face' => 'No se detectó ningún rostro en la foto. Toma la foto de frente, con buena luz.',
                'multiple_faces' => 'Se detectó más de un rostro. La foto debe contener solo al empleado.',
                'service_unavailable' => 'El servicio de reconocimiento facial no está disponible. Intenta de nuevo.',
                'invalid_response' => 'Respuesta inválida del servicio de reconocimiento facial.',
            ];

            $status = $e->getReason() === 'service_unavailable' ? 503 : 422;

            return response()->json([
                'status' => 'error',
                'message' => $messages[$e->getReason()] ?? $messages['invalid_response'],
            ], $status);
        }

        $photoPath = $request->file('photo')->store('private/employee-face-templates', 'local');

        $newConsentDocumentPath = null;
        if ($request->hasFile('consent_document')) {
            $newConsentDocumentPath = $request->file('consent_document')
                ->store('private/employee-face-consents', 'local');
        }

        $previous = EmployeeFaceTemplate::where('employee_id', $employee->id)->first();

        // Si no se subió un consentimiento nuevo, conservar el apuntador al
        // documento firmado previamente enrolado en vez de anularlo — de lo
        // contrario un re-enrolamiento sin re-adjuntar el PDF deja el
        // consentimiento firmado "huérfano" (archivo sigue en disco, pero
        // nada apunta a él) mientras consent_signed_at se refresca como si
        // el consentimiento se hubiera capturado de nuevo.
        $consentDocumentPath = $newConsentDocumentPath ?? $previous?->consent_document_path;

        // Rutas de archivos previos a borrar SOLO si la transacción de abajo
        // confirma exitosamente — nunca antes, para no dejar una foto o
        // documento borrados sin que la fila en BD apunte a los nuevos.
        $photoPathToDelete = ($previous && $previous->photo_path && $previous->photo_path !== $photoPath)
            ? $previous->photo_path
            : null;
        $consentDocumentPathToDelete = ($newConsentDocumentPath !== null
            && $previous
            && $previous->consent_document_path
            && $previous->consent_document_path !== $newConsentDocumentPath)
            ? $previous->consent_document_path
            : null;

        try {
            $template = DB::transaction(function () use ($employee, $result, $photoPath, $consentDocumentPath, $request) {
                return EmployeeFaceTemplate::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'embedding' => $result['embedding'],
                        'photo_path' => $photoPath,
                        'model_version' => $result['model_version'],
                        'enrolled_by_user_id' => $request->user()?->id,
                        'enrolled_at' => now(),
                        'consent_signed_at' => now(),
                        'consent_document_path' => $consentDocumentPath,
                        'status' => EmployeeFaceTemplate::STATUS_ACTIVE,
                        'revoked_at' => null,
                    ]
                );
            });
        } catch (\Throwable $e) {
            // La transacción no se confirmó: no borrar ningún archivo previo,
            // y limpiar los archivos recién subidos que quedaron huérfanos.
            if (Storage::disk('local')->exists($photoPath)) {
                Storage::disk('local')->delete($photoPath);
            }
            if ($newConsentDocumentPath && Storage::disk('local')->exists($newConsentDocumentPath)) {
                Storage::disk('local')->delete($newConsentDocumentPath);
            }

            throw $e;
        }

        // La transacción confirmó: ahora sí es seguro borrar los archivos
        // previos que fueron reemplazados, para no acumular datos
        // biométricos huérfanos en disco.
        if ($photoPathToDelete && Storage::disk('local')->exists($photoPathToDelete)) {
            Storage::disk('local')->delete($photoPathToDelete);
        }
        if ($consentDocumentPathToDelete && Storage::disk('local')->exists($consentDocumentPathToDelete)) {
            Storage::disk('local')->delete($consentDocumentPathToDelete);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plantilla facial enrolada correctamente',
            'data' => [
                'id' => $template->id,
                'employee_id' => $template->employee_id,
                'model_version' => $template->model_version,
                'enrolled_at' => $template->enrolled_at,
                'status' => $template->status,
            ],
        ], 201);
    }

    /**
     * Revocar la plantilla facial de un empleado.
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeEnterpriseAccess($request, (int) $employee->enterprise_id);

        $template = EmployeeFaceTemplate::where('employee_id', $employee->id)
            ->where('status', EmployeeFaceTemplate::STATUS_ACTIVE)
            ->first();

        if (! $template) {
            return response()->json([
                'status' => 'error',
                'message' => 'El empleado no tiene plantilla facial activa',
            ], 404);
        }

        $template->update([
            'status' => EmployeeFaceTemplate::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plantilla facial revocada correctamente',
            'data' => null,
        ]);
    }

    /**
     * Sirve la foto de referencia de la plantilla facial ACTIVA del empleado.
     */
    public function photo(Request $request, Employee $employee)
    {
        $this->authorizeEnterpriseAccess($request, (int) $employee->enterprise_id);

        $template = EmployeeFaceTemplate::where('employee_id', $employee->id)
            ->where('status', EmployeeFaceTemplate::STATUS_ACTIVE)
            ->first();

        if (! $template || ! $template->photo_path || ! Storage::disk('local')->exists($template->photo_path)) {
            abort(404, 'El empleado no tiene una foto de referencia disponible.');
        }

        return Storage::disk('local')->response($template->photo_path, null, [
            'Content-Type' => Storage::disk('local')->mimeType($template->photo_path),
        ]);
    }
}
