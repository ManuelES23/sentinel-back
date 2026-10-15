<?php

namespace App\Http\Controllers\Api\GrupoEsplendido\RH;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\Holiday;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\FestivosOficiales;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de días festivos (spec §4.4) con los permisos del submódulo RH → Gestión → Festivos.
 * Un cambio solo afecta las solicitudes NUEVAS: las existentes conservan los días que ya se les descontaron.
 */
class HolidayController extends Controller
{
    use VerificaPermisoSubmodulo;

    private const PERMISOS = ['view', 'create', 'edit', 'delete'];

    public function index(Request $request): JsonResponse
    {
        $this->exigir('view');

        $anio = (int) ($request->validate(['year' => 'nullable|integer|min:2000|max:2100'])['year'] ?? now()->year);

        return response()->json([
            'success' => true,
            'data' => Holiday::with('enterprise:id,name')
                ->whereBetween('date', ["{$anio}-01-01", "{$anio}-12-31"])
                ->orderBy('date')->orderBy('id')
                ->get(),
            'meta' => ['year' => $anio],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigir('create');

        $datos = $this->validar($request);
        $this->exigirUnico($datos['date'], $datos['enterprise_id'] ?? null, null);

        $festivo = Holiday::create($datos + ['is_official' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Festivo creado.',
            'data' => $festivo->load('enterprise:id,name'),
        ], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $this->exigir('edit');

        $datos = $this->validar($request);
        $this->exigirUnico($datos['date'], $datos['enterprise_id'] ?? null, $holiday->id);

        $holiday->update($datos + ['enterprise_id' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Festivo actualizado.',
            'data' => $holiday->fresh('enterprise:id,name'),
        ]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->exigir('delete');

        $holiday->delete();

        return response()->json(['success' => true, 'message' => 'Festivo eliminado.']);
    }

    public function cargarOficiales(Request $request, FestivosOficiales $oficiales): JsonResponse
    {
        $this->exigir('create');

        $anio = (int) $request->validate(['year' => 'required|integer|min:2000|max:2100'])['year'];
        $creados = $oficiales->cargar($anio);
        $existentes = count($oficiales->paraAnio($anio)) - $creados;

        return response()->json([
            'success' => true,
            'message' => "Festivos oficiales {$anio}: {$creados} nuevos, {$existentes} ya existían.",
            'data' => ['creados' => $creados, 'existentes' => $existentes],
        ]);
    }

    public function misPermisos(): JsonResponse
    {
        $empresaId = $this->empresaCorporativa();

        return response()->json([
            'success' => true,
            'data' => collect(self::PERMISOS)->mapWithKeys(fn (string $permiso) => [
                $permiso => $empresaId && $this->tienePermisoSubmodulo($empresaId, 'gestion', 'festivos', $permiso, 'rh'),
            ]),
        ]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'name' => 'required|string|max:100',
            'enterprise_id' => 'nullable|integer|exists:enterprises,id',
        ]);
    }

    /**
     * El índice único (date, enterprise_id) de MySQL deja pasar varios NULL, así que «una fecha
     * para todas las empresas» se vigila aquí.
     */
    private function exigirUnico(string $fecha, ?int $empresaId, ?int $ignorarId): void
    {
        $existe = Holiday::where('date', $fecha)
            ->when($empresaId, fn ($q) => $q->where('enterprise_id', $empresaId), fn ($q) => $q->whereNull('enterprise_id'))
            ->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['date' => ['Ya hay un festivo en esa fecha para esa empresa.']]);
        }
    }

    private function exigir(string $permiso): void
    {
        $this->exigirPermisoSubmodulo(
            $this->empresaCorporativa() ?: null,
            'gestion',
            'festivos',
            $permiso,
            'No tienes permiso para realizar esta acción en Festivos.',
            'rh',
        );
    }

    private function empresaCorporativa(): int
    {
        return (int) Enterprise::where('slug', AprobadorVacaciones::EMPRESA_CORPORATIVA)->value('id');
    }
}
