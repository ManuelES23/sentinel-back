<?php
// sentinel-back/app/Http/Controllers/Api/GrupoEsplendido/RH/RosterPackageController.php
namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeFaceTemplate;
use App\Services\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RosterPackageController extends Controller
{
    private const CURRENT_MODEL_VERSION = 'faceapi-v1';

    public function __construct(private readonly ThumbnailService $thumbnailService)
    {
    }

    /**
     * Paquete de plantillas: TODOS los empleados activos con biometría
     * enrolada, sin filtrar por empresa — RH es corporativo (ver spec
     * 2026-08-27, Decisiones). Protegido por el middleware device.token,
     * nunca por número+PIN ni por Sanctum.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $employees = Employee::query()
            ->where('status', Employee::STATUS_ACTIVE)
            ->whereHas('faceTemplate', function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION)
                    ->where('status', EmployeeFaceTemplate::STATUS_ACTIVE);
            })
            ->with(['faceTemplate' => function ($q) {
                $q->where('model_version', self::CURRENT_MODEL_VERSION)
                    ->where('status', EmployeeFaceTemplate::STATUS_ACTIVE);
            }])
            ->get(['id', 'employee_number', 'first_name', 'last_name', 'second_last_name']);

        $rows = $employees->map(function (Employee $employee) {
            $template = $employee->faceTemplate;

            return [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => trim("{$employee->first_name} {$employee->last_name} {$employee->second_last_name}"),
                'embedding' => $template->embedding,
                'thumbnail' => $this->thumbnailService->makeThumbnailDataUri($template->photo_path),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'model_version' => self::CURRENT_MODEL_VERSION,
                'generated_at' => now()->toIso8601String(),
                'employees' => $rows,
            ],
        ]);
    }
}
