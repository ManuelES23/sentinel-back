<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Events\FixedAssetUpdated;
use App\Http\Controllers\Controller;
use App\Models\AssetCharacteristicDefinition;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\CreadorActivo;
use App\Services\ActivosFijos\GeneradorCodigoActivo;
use App\Services\ActivosFijos\PermisosActivos;
use App\Services\ActivosFijos\ValidadorRelacionesActivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FixedAssetController extends Controller
{
    private const RELATIONS = [
        'enterprise:id,name,slug',
        'brand:id,name,code',
        'category:id,name,code,parent_id,icon',
        'subcategory:id,name,code,parent_id,icon',
        'branch:id,name,code',
        'entity:id,name,code,entity_type_id',
        'entity.entityType:id,code,name,icon,color',
        'area:id,name,code',
        'performanceUnit:id,name,abbreviation',
        'characteristics',
        'asignacionActiva:id,fixed_asset_id,assignee_type,assignee_name,assigned_at,returned_at,signed_document_path',
        'supplier:id,business_name',
        'purchaseReceipt:id,receipt_number,supplier_document,status', // status: el modelo agrega status_label e is_editable
    ];

    private const POR_PAGINA = 25;
    private const POR_PAGINA_MAX = 100;

    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
        private GeneradorCodigoActivo $codigos,
        private CreadorActivo $creador,
        private ValidadorRelacionesActivo $relaciones,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'view');

        $query = $this->alcance->aplicar(FixedAsset::with(self::RELATIONS), $request);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        foreach (['enterprise_id', 'branch_id', 'entity_id', 'area_id', 'category_id', 'subcategory_id', 'status'] as $filtro) {
            if ($request->filled($filtro)) {
                $query->where($filtro, $request->input($filtro));
            }
        }

        // Filtro por ubicación (Campo/Empaque/Oficina) vía tipo de entidad
        if ($request->filled('entity_type')) {
            $entityTypes = array_filter(explode(',', $request->input('entity_type')));
            if (! empty($entityTypes)) {
                $query->whereHas('entity.entityType', fn ($q) => $q->whereIn('code', $entityTypes));
            }
        }

        if ($request->filled('search')) {
            // '!' como carácter de escape: funciona igual en MySQL y SQLite.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('search')).'%';
            $query->where(function ($q) use ($term) {
                foreach (['name', 'code', 'serial_number', 'model'] as $columna) {
                    $q->orWhereRaw("{$columna} LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }

        $porPagina = min(max((int) $request->input('per_page', self::POR_PAGINA), 1), self::POR_PAGINA_MAX);

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('name')->orderBy('id')->paginate($porPagina),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'create');

        $empresa = $this->alcance->empresaParaAlta($request);
        $validated = $this->validateData($request, $empresa);
        $imagenNueva = $request->hasFile('image') ? $request->file('image')->store('fixed-assets', 'public') : null;

        try {
            $asset = DB::transaction(function () use ($request, $validated, $empresa, $imagenNueva) {
                if ($imagenNueva) {
                    $validated['image'] = $imagenNueva;
                }

                return $this->creador->crear(
                    $empresa,
                    $validated,
                    $this->debeSincronizar($request) ? (array) $request->input('characteristics', []) : null,
                );
            });
        } catch (\Throwable $e) {
            if ($imagenNueva) {
                Storage::disk('public')->delete($imagenNueva);
            }
            throw $e;
        }

        $asset->load(self::RELATIONS);
        $this->emitir('created', $asset);

        return response()->json([
            'success' => true,
            'message' => 'Activo fijo creado exitosamente',
            'data' => $asset,
        ], 201);
    }

    public function show(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'view');
        $this->alcance->autorizarActivo($asset, $request);

        return response()->json([
            'success' => true,
            'data' => $asset->load(self::RELATIONS),
        ]);
    }

    public function update(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->alcance->autorizarActivo($asset, $request);
        $this->permisos->autorizar($request, 'activos', 'edit');

        $empresa = $asset->enterprise; // la empresa dueña no cambia al editar
        $validated = $this->validateData($request, $empresa, $asset);
        unset($validated['enterprise_id']);
        if (empty($validated['code'])) {
            unset($validated['code']); // vacío = conservar el código actual
        }

        $imagenVieja = $asset->image;
        $imagenNueva = $request->hasFile('image') ? $request->file('image')->store('fixed-assets', 'public') : null;
        $quitarImagen = $imagenNueva || $request->boolean('remove_image');
        if (! $quitarImagen) {
            unset($validated['image']); // image null/vacío sin remove_image no debe borrar la imagen actual
        }

        try {
            DB::transaction(function () use ($request, $asset, $validated, $imagenNueva, $quitarImagen) {
                if ($quitarImagen) {
                    $validated['image'] = $imagenNueva; // null si solo se quitó
                }

                $asset->update($validated);

                if ($this->debeSincronizar($request)) {
                    $this->creador->sincronizarCaracteristicas($asset, (array) $request->input('characteristics', []));
                }
            });
        } catch (\Throwable $e) {
            if ($imagenNueva) {
                Storage::disk('public')->delete($imagenNueva);
            }
            throw $e;
        }

        // Se borra el archivo viejo solo cuando el cambio ya quedó guardado.
        if ($quitarImagen && $imagenVieja) {
            Storage::disk('public')->delete($imagenVieja);
        }

        $asset = $asset->fresh(self::RELATIONS);
        $this->emitir('updated', $asset);

        return response()->json([
            'success' => true,
            'message' => 'Activo fijo actualizado exitosamente',
            'data' => $asset,
        ]);
    }

    /** Borrado lógico: la imagen se conserva para poder restaurar el activo. */
    public function destroy(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->alcance->autorizarActivo($asset, $request);
        $this->permisos->autorizar($request, 'activos', 'delete');

        if ($asset->asignacionActiva()->exists()) {
            throw ValidationException::withMessages([
                'asset' => 'El activo está asignado a alguien. Regístrale la devolución antes de eliminarlo.',
            ]);
        }

        if ($asset->status !== 'baja' && $asset->unidadCompra()->where('status', 'registered')->exists()) {
            throw ValidationException::withMessages([
                'asset' => 'Este activo viene de una compra y respalda una entrada de inventario. Cambia su estado a «Baja» antes de eliminarlo.',
            ]);
        }

        $asset->delete();
        $this->emitir('deleted', $asset);

        return response()->json([
            'success' => true,
            'message' => 'Activo fijo eliminado exitosamente',
        ]);
    }

    /**
     * Vista previa del siguiente código (no lo reserva: se asigna al guardar).
     * En GE se calcula para la empresa elegida (?enterprise_id=).
     */
    public function nextCodeEndpoint(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'view');

        $empresa = $this->alcance->esCorporativo($request) && $request->filled('enterprise_id')
            ? $this->alcance->empresasVisibles($request)->firstWhere('id', (int) $request->input('enterprise_id'))
            : $this->alcance->empresaActual($request);

        return response()->json([
            'success' => true,
            'data' => ['code' => $empresa ? $this->codigos->vistaPrevia($empresa) : null],
        ]);
    }

    private function validateData(Request $request, Enterprise $empresa, ?FixedAsset $asset = null): array
    {
        $esAlta = $asset === null;

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('fixed_assets', 'code')->ignore($asset?->id)],
            'name' => [$esAlta ? 'required' : 'sometimes', 'string', 'max:255'],
            'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            'remove_image' => 'sometimes|boolean',
            'serial_number' => 'nullable|string|max:150',
            'model' => 'nullable|string|max:150',
            'year' => 'nullable|integer|min:1900|max:'.(date('Y') + 1),
            'brand_id' => 'nullable|exists:brands,id',

            'category_id' => [$esAlta ? 'required' : 'sometimes', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'subcategory_id' => ['nullable', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],

            'branch_id' => [$esAlta ? 'required' : 'sometimes', 'exists:branches,id'],
            'entity_id' => [$esAlta ? 'required' : 'sometimes', 'exists:entities,id'],
            'area_id' => 'nullable|exists:areas,id',

            'status' => ['sometimes', 'required', Rule::in(array_keys(FixedAsset::STATUSES))],
            'useful_life_years' => 'nullable|integer|min:0|max:100',
            'performance_unit_id' => 'nullable|exists:units_of_measure,id',
            'description' => 'nullable|string',
            'observations' => 'nullable|string',

            'purchase_date' => 'nullable|date',
            'invoice_number' => 'nullable|string|max:100',
            'purchase_value' => 'nullable|numeric|min:0',

            'is_active' => 'boolean',
            'metadata' => 'nullable|array',

            'characteristics' => 'nullable|array',
            'characteristics.*.name' => 'required_with:characteristics.*.value|nullable|string|max:150',
            'characteristics.*.value' => 'nullable|string|max:500',
            'characteristics.*.definition_id' => 'nullable|integer|exists:asset_characteristic_definitions,id',
        ]);

        unset($validated['characteristics'], $validated['remove_image']);

        // Valores efectivos (los del request o, al editar, los que ya tiene el activo)
        $efectivo = fn (string $campo) => array_key_exists($campo, $validated) ? $validated[$campo] : $asset?->{$campo};

        $categoryId = $efectivo('category_id');
        $subcategoryId = $efectivo('subcategory_id');
        $errores = $this->relaciones->errores($empresa, [
            'branch_id' => $efectivo('branch_id'),
            'entity_id' => $efectivo('entity_id'),
            'area_id' => $efectivo('area_id'),
            'brand_id' => $validated['brand_id'] ?? null, // solo se revisa si el request la trae
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
        ]);

        $categoriasValidas = array_filter([$categoryId, $subcategoryId]);
        foreach ((array) $request->input('characteristics', []) as $i => $fila) {
            $definitionId = $fila['definition_id'] ?? null;
            if ($definitionId && ! AssetCharacteristicDefinition::whereKey($definitionId)->whereIn('category_id', $categoriasValidas)->exists()) {
                $errores["characteristics.{$i}.definition_id"] = 'La característica no corresponde al tipo de activo';
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        return $validated;
    }

    /**
     * Las características solo se reemplazan si la petición las trae. El
     * formulario manda sync_characteristics=1 (FormData no envía arreglos
     * vacíos), así "sin filas" sí significa "quítalas todas"; una petición
     * que no las menciona (p. ej. un cambio de estado) no las toca.
     */
    private function debeSincronizar(Request $request): bool
    {
        return $request->has('characteristics') || $request->boolean('sync_characteristics');
    }

    private function emitir(string $accion, FixedAsset $asset): void
    {
        $empresa = $asset->relationLoaded('enterprise') ? $asset->enterprise : $asset->enterprise()->first();

        // Un Reverb caído no debe convertir un guardado exitoso en un 500.
        try {
            broadcast(new FixedAssetUpdated($accion, ['id' => $asset->id], $empresa->slug));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
