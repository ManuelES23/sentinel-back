<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Events\AssetCategoryUpdated;
use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\AssetCharacteristicDefinition;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\GeneradorCodigoActivo;
use App\Services\ActivosFijos\PermisosActivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo central de tipos/subtipos de activo (dos niveles). Lo leen todas
 * las empresas con Activos Fijos; solo Grupo Espléndido lo modifica.
 */
class AssetCategoryController extends Controller
{
    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
        private GeneradorCodigoActivo $codigos,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permisos->autorizarVerTipos($request);

        $query = AssetCategory::with(['parent:id,name,code', 'children:id,parent_id,name,code,icon,is_active'])
            ->withCount($this->conteos($request));

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->boolean('root_only')) {
            $query->root();
        }

        if ($request->has('parent_id')) {
            $parentId = $request->input('parent_id');
            if ($parentId === 'null' || $parentId === '' || $parentId === null) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('order')->orderBy('name')->get(),
        ]);
    }

    /** Árbol completo, inactivos incluidos (se muestran con su badge para poder reactivarlos). */
    public function tree(Request $request): JsonResponse
    {
        $this->permisos->autorizarVerTipos($request);
        $conteos = $this->conteos($request);

        $categories = AssetCategory::query()
            ->with(['children' => fn ($q) => $q->withCount($conteos)->orderBy('order')->orderBy('name')])
            ->withCount($conteos)
            ->whereNull('parent_id')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        // El front lee allChildren: se conserva el nombre del arreglo.
        $categories->each(fn ($c) => $c->setRelation('allChildren', $c->children)->unsetRelation('children'));

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permisos->autorizarEscrituraTipos($request, 'create');

        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:asset_categories,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:asset_categories,slug',
            'description' => 'nullable|string',
            'parent_id' => ['nullable', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        $this->validarPadre($validated['parent_id'] ?? null, null);

        $category = DB::transaction(function () use ($validated) {
            if (empty($validated['code'])) {
                $validated['code'] = $this->codigos->siguienteTipo();
            }

            return AssetCategory::create($validated);
        });

        $category->load('parent:id,name,code');
        $category->loadCount($this->conteos($request));
        $this->emitir('created', ['id' => $category->id]);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de activo creado exitosamente',
            'data' => $category,
        ], 201);
    }

    public function show(Request $request, AssetCategory $tipoActivo): JsonResponse
    {
        $this->permisos->autorizarVerTipos($request);

        $tipoActivo->load(['parent', 'children']);
        $tipoActivo->loadCount($this->conteos($request));

        return response()->json([
            'success' => true,
            'data' => $tipoActivo,
        ]);
    }

    public function update(Request $request, AssetCategory $tipoActivo): JsonResponse
    {
        $this->permisos->autorizarEscrituraTipos($request, 'edit');

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:asset_categories,slug,'.$tipoActivo->id,
            'description' => 'nullable|string',
            'parent_id' => ['nullable', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        if (array_key_exists('parent_id', $validated)) {
            $this->validarPadre($validated['parent_id'], $tipoActivo);
        }

        $tipoActivo->update($validated);

        $tipoActivo = $tipoActivo->fresh(['parent:id,name,code', 'children:id,parent_id,name,code']);
        $tipoActivo->loadCount($this->conteos($request));
        $this->emitir('updated', ['id' => $tipoActivo->id]);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de activo actualizado exitosamente',
            'data' => $tipoActivo,
        ]);
    }

    public function destroy(Request $request, AssetCategory $tipoActivo): JsonResponse
    {
        $this->permisos->autorizarEscrituraTipos($request, 'delete');

        if ($tipoActivo->children()->exists()) {
            throw ValidationException::withMessages(['id' => 'No se puede eliminar el tipo de activo porque tiene subtipos']);
        }

        // Aquí se cuentan los activos de TODAS las empresas: el catálogo es compartido.
        if ($tipoActivo->assetsAsCategory()->exists() || $tipoActivo->assetsAsSubcategory()->exists()) {
            throw ValidationException::withMessages(['id' => 'No se puede eliminar el tipo de activo porque tiene activos fijos asociados']);
        }

        $tipoActivo->delete();
        $this->emitir('deleted', ['id' => $tipoActivo->id]);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de activo eliminado exitosamente',
        ]);
    }

    public function characteristics(Request $request, AssetCategory $tipoActivo): JsonResponse
    {
        $this->permisos->autorizarVerTipos($request);

        return response()->json([
            'success' => true,
            'data' => $tipoActivo->characteristicDefinitions()->get(),
        ]);
    }

    public function storeCharacteristic(Request $request, AssetCategory $tipoActivo): JsonResponse
    {
        $this->permisos->autorizarEscrituraTipos($request, 'edit');

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                // Solo contra definiciones vivas: una borrada se recicla.
                Rule::unique('asset_characteristic_definitions', 'name')
                    ->where('category_id', $tipoActivo->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $definition = AssetCharacteristicDefinition::withTrashed()
            ->where('category_id', $tipoActivo->id)
            ->where('name', $validated['name'])
            ->first();

        if ($definition) {
            $definition->restore();
        } else {
            $order = (int) ($tipoActivo->characteristicDefinitions()->max('order') ?? 0) + 1;

            $definition = AssetCharacteristicDefinition::create([
                'category_id' => $tipoActivo->id,
                'name' => $validated['name'],
                'order' => $order,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Característica agregada al catálogo',
            'data' => $definition,
        ], 201);
    }

    /** No borra los valores ya capturados en activos (quedan como campo libre). */
    public function destroyCharacteristic(Request $request, AssetCharacteristicDefinition $characteristic): JsonResponse
    {
        $this->permisos->autorizarEscrituraTipos($request, 'edit');

        $characteristic->delete();

        return response()->json([
            'success' => true,
            'message' => 'Característica eliminada del catálogo',
        ]);
    }

    /**
     * Dos niveles: el padre debe ser raíz, no puede ser el propio tipo, y un
     * tipo que ya tiene subtipos no puede pasar a ser subtipo.
     */
    private function validarPadre(?int $parentId, ?AssetCategory $tipo): void
    {
        if ($parentId === null) {
            return;
        }

        if ($tipo && $parentId === $tipo->id) {
            throw ValidationException::withMessages(['parent_id' => 'Un tipo de activo no puede ser su propio padre']);
        }

        if (AssetCategory::whereKey($parentId)->whereNotNull('parent_id')->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Solo hay dos niveles: el padre debe ser un tipo principal']);
        }

        if ($tipo && $tipo->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'Un tipo con subtipos no puede convertirse en subtipo']);
        }
    }

    /** Conteos de activos limitados a las empresas que ve la petición. */
    private function conteos(Request $request): array
    {
        $ids = $this->alcance->idsVisibles($request);
        $filtro = fn ($q) => $q->whereIn('enterprise_id', $ids);

        return ['assetsAsCategory' => $filtro, 'assetsAsSubcategory' => $filtro];
    }

    private function emitir(string $accion, array $data): void
    {
        // Un Reverb caído no debe convertir un guardado exitoso en un 500.
        try {
            broadcast(new AssetCategoryUpdated($accion, $data));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
