<?php

namespace App\Http\Controllers\Api\ActivosFijos;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Brand;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\PermisosActivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Datos de apoyo de la pantalla de Activos Fijos. El formulario no puede usar
 * las rutas de Organización porque Grupo Espléndido no las tiene y, en la
 * vista corporativa, necesita las ubicaciones de la empresa elegida.
 */
class ContextoActivosController extends Controller
{
    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
    ) {
    }

    public function permisos(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->permisos->resumen($request),
        ]);
    }

    public function catalogos(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'view');

        $visibles = $this->alcance->empresasVisibles($request);
        $empresa = $this->alcance->empresaActual($request);

        if ($this->alcance->esCorporativo($request) && $request->filled('enterprise_id')) {
            $empresa = $visibles->firstWhere('id', (int) $request->input('enterprise_id'));
            if (! $empresa) {
                throw ValidationException::withMessages(['enterprise_id' => 'La empresa seleccionada no está disponible.']);
            }
        }

        $sucursales = Branch::query()
            ->where('enterprise_id', $empresa->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->with(['entities' => fn ($q) => $q->where('is_active', true)->orderBy('name')
                ->select('id', 'branch_id', 'entity_type_id', 'name', 'code')
                ->with([
                    'entityType:id,code,name',
                    'areas' => fn ($a) => $a->where('areas.is_active', true)->wherePivot('is_active', true)
                        ->select('areas.id', 'areas.name', 'areas.code'),
                ])])
            ->get(['id', 'name', 'code'])
            ->map(fn (Branch $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'entidades' => $s->entities->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'code' => $e->code,
                    'entity_type' => $e->entityType?->only(['id', 'code', 'name']),
                    'areas' => $e->areas->map->only(['id', 'name', 'code'])->values(),
                ])->values(),
            ]);

        $marcas = Brand::forEnterprise($empresa->id)->where('is_active', true)->orderBy('name')->get(['brands.id', 'brands.name', 'brands.code']);

        return response()->json([
            'success' => true,
            'data' => [
                'empresas' => $visibles->map->only(['id', 'name', 'slug'])->values(),
                'empresa_id' => $empresa->id,
                'sucursales' => $sucursales,
                'marcas' => $marcas->map->only(['id', 'name', 'code'])->values(),
            ],
        ]);
    }
}
