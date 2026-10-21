<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\VacationHistoryEntry;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\HistorialVacaciones;
use App\Services\RH\ImportadorHistorialVacaciones;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Historial de vacaciones capturado por RH (RH → Gestión → Vacaciones): `view` lo
 * consulta; `edit` lo captura, corrige, borra e importa. Las reglas viven en
 * HistorialVacaciones y la lectura del Excel en ImportadorHistorialVacaciones.
 */
class VacationHistoryController extends Controller
{
    use VerificaPermisoSubmodulo;

    private const REGLAS_FILA = [
        'vacation_year' => 'required|integer|min:1950|max:2100',
        'start_date' => 'nullable|date_format:Y-m-d',
        'end_date' => 'nullable|date_format:Y-m-d',
        'days' => 'required|integer|min:1|max:'.HistorialVacaciones::MAX_DIAS,
        'notes' => 'nullable|string|max:500',
    ];

    public function __construct(private HistorialVacaciones $historial) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigir('view');
        $empleadoId = $request->validate(['employee_id' => 'required|integer|exists:employees,id'])['employee_id'];

        $entradas = VacationHistoryEntry::with('creator:id,name')
            ->where('employee_id', $empleadoId)
            ->orderByDesc('vacation_year')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $entradas->map(fn (VacationHistoryEntry $e) => $this->presentar($e))->values(),
            'meta' => ['total_dias' => (int) $entradas->sum('days')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigir('edit');
        $datos = $request->validate(['employee_id' => 'required|integer|exists:employees,id'] + self::REGLAS_FILA);

        $entrada = $this->historial->crear(Employee::findOrFail($datos['employee_id']), $datos, Auth::user());

        return response()->json(['success' => true, 'message' => 'Periodo agregado al historial.', 'data' => $this->presentar($entrada->load('creator:id,name'))], 201);
    }

    public function update(Request $request, VacationHistoryEntry $historico): JsonResponse
    {
        $this->exigir('edit');
        $datos = $request->validate(self::REGLAS_FILA);

        $entrada = $this->historial->actualizar($historico, $datos, Auth::user());

        return response()->json(['success' => true, 'message' => 'Periodo actualizado.', 'data' => $this->presentar($entrada->load('creator:id,name'))]);
    }

    public function destroy(VacationHistoryEntry $historico): JsonResponse
    {
        $this->exigir('edit');
        $this->historial->borrar($historico);

        return response()->json(['success' => true, 'message' => 'Periodo eliminado del historial.']);
    }

    /** Paso 1 de la importación: lee el archivo y propone empleados y estados. No guarda nada. */
    public function leer(Request $request, ImportadorHistorialVacaciones $importador): JsonResponse
    {
        $this->exigir('edit');
        $request->validate(['archivo' => 'required|file|max:5120|mimes:xlsx,xls,csv,txt']);

        return response()->json(['success' => true, 'data' => $importador->leer($request->file('archivo'))]);
    }

    /** Paso 2: revisa las filas ya asignadas (errores, ya importadas y saldos). No guarda nada. */
    public function validar(Request $request): JsonResponse
    {
        $this->exigir('edit');

        return response()->json(['success' => true, 'data' => $this->historial->revisarLote($this->filas($request))]);
    }

    /** Paso 3: guarda el lote, todo o nada. */
    public function importar(Request $request): JsonResponse
    {
        $this->exigir('edit');
        $filas = $this->filas($request);
        $archivo = $request->validate(['archivo' => 'nullable|string|max:255'])['archivo'] ?? null;

        $resultado = $this->historial->importarLote($filas, Auth::user(), $archivo);

        return response()->json([
            'success' => true,
            'message' => "Se importaron {$resultado['importadas']} periodos al historial.",
            'data' => $resultado,
        ], 201);
    }

    private function filas(Request $request): array
    {
        $reglas = ['filas' => 'required|array|min:1|max:'.ImportadorHistorialVacaciones::MAX_FILAS, 'filas.*.fila' => 'nullable|integer', 'filas.*.employee_id' => 'required|integer|exists:employees,id'];
        foreach (self::REGLAS_FILA as $campo => $regla) {
            $reglas["filas.*.{$campo}"] = $regla;
        }

        return $request->validate($reglas)['filas'];
    }

    private function presentar(VacationHistoryEntry $entrada): array
    {
        return [
            'id' => $entrada->id,
            'employee_id' => $entrada->employee_id,
            'vacation_year' => $entrada->vacation_year,
            'start_date' => $entrada->start_date?->format('Y-m-d'),
            'end_date' => $entrada->end_date?->format('Y-m-d'),
            'days' => $entrada->days,
            'notes' => $entrada->notes,
            'source' => $entrada->source,
            'import_file' => $entrada->import_file,
            'created_by' => $entrada->creator?->name,
            'created_at' => $entrada->created_at?->toIso8601String(),
        ];
    }

    private function exigir(string $permiso): void
    {
        $this->exigirPermisoSubmodulo(
            (int) Enterprise::where('slug', AprobadorVacaciones::EMPRESA_CORPORATIVA)->value('id') ?: null,
            'gestion',
            'vacaciones',
            $permiso,
            'No tienes permiso para realizar esta acción en Vacaciones.',
            'rh',
        );
    }
}
