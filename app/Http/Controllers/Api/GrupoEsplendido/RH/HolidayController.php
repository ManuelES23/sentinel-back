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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de días festivos (spec §4.4) con los permisos del submódulo RH → Gestión → Festivos.
 * Cada festivo es de una empresa; cada empresa usa los suyos o los de otra (`empresas`), y el
 * empleado puede tener su excepción (ver EmpresaDeFestivos). Un cambio solo afecta las solicitudes
 * NUEVAS: las existentes conservan los días que ya se les descontaron.
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
                ->when($request->filled('enterprise_id'), fn ($q) => $q->where('enterprise_id', (int) $request->enterprise_id))
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

        $holiday->update($datos);

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

        $datos = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'enterprise_id' => 'required|integer|exists:enterprises,id',
            'pais' => ['nullable', Rule::in(FestivosOficiales::PAISES)],
        ]);
        $anio = (int) $datos['year'];
        $pais = $datos['pais'] ?? FestivosOficiales::MEXICO;
        $creados = $oficiales->cargar($anio, (int) $datos['enterprise_id'], $pais);
        $existentes = count($oficiales->paraAnio($anio, $pais)) - $creados;

        return response()->json([
            'success' => true,
            'message' => "Festivos oficiales {$anio}: {$creados} nuevos, {$existentes} ya existían.",
            'data' => ['creados' => $creados, 'existentes' => $existentes],
        ]);
    }

    /** Empresas activas con la empresa cuyos festivos siguen y cuántos festivos propios tienen en el año. */
    public function empresas(Request $request): JsonResponse
    {
        $this->exigir('view');

        $anio = (int) ($request->validate(['year' => 'nullable|integer|min:2000|max:2100'])['year'] ?? now()->year);

        $empresas = Enterprise::where('is_active', true)->orderBy('name')
            ->withCount(['holidays as festivos_propios' => fn ($q) => $q->whereBetween('date', ["{$anio}-01-01", "{$anio}-12-31"])])
            ->get(['id', 'name', 'slug', 'holiday_source_enterprise_id']);

        return response()->json([
            'success' => true,
            'data' => $empresas->map(fn (Enterprise $empresa) => [
                'id' => $empresa->id,
                'name' => $empresa->name,
                'slug' => $empresa->slug,
                'holiday_source_enterprise_id' => $empresa->holiday_source_enterprise_id,
                'festivos_propios' => (int) $empresa->festivos_propios,
            ])->values(),
            'meta' => ['year' => $anio],
        ]);
    }

    /**
     * Qué festivos sigue una empresa: null = los suyos. Sin cadenas (a un solo nivel): no se sigue
     * a sí misma, no sigue a una que ya sigue a otra, y no puede seguir a otra mientras alguien la sigue.
     */
    public function actualizarEmpresa(Request $request, Enterprise $enterprise): JsonResponse
    {
        $this->exigir('edit');

        $fuente = $request->validate(['holiday_source_enterprise_id' => 'present|nullable|integer|exists:enterprises,id'])['holiday_source_enterprise_id'];
        $fuente = $fuente !== null ? (int) $fuente : null;

        if ($fuente !== null) {
            $error = match (true) {
                $fuente === $enterprise->id => 'Una empresa no puede seguirse a sí misma: elige «Los suyos».',
                Enterprise::whereKey($fuente)->whereNotNull('holiday_source_enterprise_id')->exists() => 'Esa empresa ya sigue los festivos de otra: elige una con festivos propios.',
                Enterprise::where('holiday_source_enterprise_id', $enterprise->id)->exists() => "Otras empresas siguen los festivos de {$enterprise->name}: cámbialas primero.",
                default => null,
            };
            if ($error) {
                throw ValidationException::withMessages(['holiday_source_enterprise_id' => [$error]]);
            }
        }

        $enterprise->update(['holiday_source_enterprise_id' => $fuente]);

        return response()->json([
            'success' => true,
            'message' => 'Festivos de la empresa actualizados.',
            'data' => ['id' => $enterprise->id, 'holiday_source_enterprise_id' => $fuente],
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
            'enterprise_id' => 'required|integer|exists:enterprises,id',
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
