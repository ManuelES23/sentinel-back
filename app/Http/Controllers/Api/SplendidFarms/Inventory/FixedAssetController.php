<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Events\FixedAssetUpdated;
use App\Http\Controllers\Controller;
use App\Models\AssetCharacteristicDefinition;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\CreadorActivo;
use App\Services\ActivosFijos\GeneradorCodigoActivo;
use App\Services\ActivosFijos\PermisosActivos;
use App\Services\ActivosFijos\ResolutorDepreciacion;
use App\Services\ActivosFijos\ValidadorRelacionesActivo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FixedAssetController extends Controller
{
    private const RELATIONS = [
        'enterprise:id,name,slug',
        'brand:id,name,code',
        'category:id,name,code,parent_id,icon,depreciation_rate,salvage_percent',
        'subcategory:id,name,code,parent_id,icon,depreciation_rate,salvage_percent',
        'branch:id,name,code',
        'entity:id,name,code,entity_type_id',
        'entity.entityType:id,code,name,icon,color',
        'area:id,name,code',
        'performanceUnit:id,name,abbreviation',
        'characteristics',
        'asignacionActiva:id,fixed_asset_id,assignee_type,assignee_name,assigned_at,returned_at,signed_document_path',
        'salidaVigente:id,fixed_asset_id,type,disposal_date,accumulated_depreciation,book_value,result_amount,reversed_at,signed_document_path',
        'supplier:id,business_name',
        'purchaseReceipt:id,receipt_number,supplier_document,status', // status: el modelo agrega status_label e is_editable
    ];

    private const POR_PAGINA = 25;
    private const POR_PAGINA_MAX = 100;

    private const ORDENES = ['name', 'code', 'purchase_date', 'purchase_value', 'status'];

    /** Rangos de «Año de compra» del catálogo: [desde, hasta], null = abierto. */
    private const RANGOS_ANIO = [
        'a' => [null, '2020-12-31'],
        'b' => ['2021-01-01', '2023-12-31'],
        'c' => ['2024-01-01', null],
    ];

    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
        private GeneradorCodigoActivo $codigos,
        private CreadorActivo $creador,
        private ValidadorRelacionesActivo $relaciones,
        private ResolutorDepreciacion $depreciacion,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'view');

        $query = $this->aplicarFiltros(
            $this->alcance->aplicar(FixedAsset::with(self::RELATIONS), $request),
            $request,
        );

        $porPagina = min(max((int) $request->input('per_page', self::POR_PAGINA), 1), self::POR_PAGINA_MAX);

        $this->ordenar($query, $request);
        $pagina = $query->paginate($porPagina);
        $pagina->getCollection()->each(fn (FixedAsset $activo) => $this->conDepreciacion($activo));

        return response()->json([
            'success' => true,
            'data' => $pagina,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'create');

        $empresa = $this->alcance->empresaParaAlta($request);
        $validated = $this->validateData($request, $empresa);
        if (($validated['status'] ?? null) === 'baja') {
            throw ValidationException::withMessages(['status' => 'Para dar de baja un activo usa «Dar de baja o vender».']);
        }
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
        $this->conDepreciacion($asset);
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
            'data' => $this->conDepreciacion($asset->load(self::RELATIONS)),
        ]);
    }

    public function update(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->alcance->autorizarActivo($asset, $request);
        $this->permisos->autorizar($request, 'activos', 'edit');

        $empresa = $asset->enterprise; // la empresa dueña no cambia al editar
        $validated = $this->validateData($request, $empresa, $asset);
        $this->exigirEstadoEditable($asset, $validated);
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
        $this->conDepreciacion($asset);
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

        if ($asset->salidas()->exists()) {
            throw ValidationException::withMessages([
                'asset' => 'El activo tiene historial de salidas (bajas, ventas o traspasos) y no se puede eliminar.',
            ]);
        }

        if (FixedAssetDisposal::vigentes()->where('destination_asset_id', $asset->id)->exists()) {
            throw ValidationException::withMessages([
                'asset' => 'Este activo se recibió por un traspaso vigente. Deshaz el traspaso en «Bajas y ventas» para retirarlo.',
            ]);
        }

        if ($asset->status !== 'baja' && $asset->unidadCompra()->where('status', 'registered')->exists()) {
            throw ValidationException::withMessages([
                'asset' => 'Este activo viene de una compra y respalda una entrada de inventario, así que no se elimina. Si ya no se usa, regístrale su baja con «Dar de baja o vender».',
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
            'depreciation_start' => 'nullable|date',
            'depreciation_rate' => 'nullable|numeric|gt:0|max:100',
            'salvage_percent' => 'nullable|numeric|min:0|lt:100',

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

    /** Campos que, con una salida vigente, congelan las cifras de la salida y del cierre de su mes. */
    private const CAMPOS_CON_SALIDA = [
        'status' => 'el estado',
        'purchase_value' => 'el valor de compra',
        'purchase_date' => 'la fecha de compra',
        'depreciation_start' => 'el inicio de la depreciación',
        'depreciation_rate' => 'la tasa de depreciación',
        'salvage_percent' => 'el porcentaje residual',
        'useful_life_years' => 'la vida útil',
        'category_id' => 'el tipo de activo',
        'subcategory_id' => 'el subtipo de activo',
    ];

    /**
     * La baja se registra con «Dar de baja o vender» (salida con fecha, motivo y cifras). Mientras
     * un activo tenga una salida vigente no se puede cambiar su estado ni los datos que alimentan
     * la depreciación (valor, fechas, tasa, residual, vida útil y tipo): el cierre del mes de la
     * salida debe seguir cuadrando con las cifras congeladas. Enviar el mismo valor no es un cambio;
     * los datos descriptivos siguen editables. Un baja antiguo (sin salida) sí puede cambiar de estado.
     */
    private function exigirEstadoEditable(FixedAsset $asset, array $validated): void
    {
        if (array_key_exists('status', $validated) && $validated['status'] === 'baja' && $validated['status'] !== $asset->status) {
            throw ValidationException::withMessages(['status' => 'Para dar de baja un activo usa «Dar de baja o vender».']);
        }

        if (! $asset->salidaVigente()->exists()) {
            return;
        }

        $errores = [];
        foreach (self::CAMPOS_CON_SALIDA as $campo => $etiqueta) {
            if (array_key_exists($campo, $validated) && $this->cambiaElCampo($asset, $campo, $validated[$campo])) {
                $errores[$campo] = "No se puede cambiar {$etiqueta} mientras el activo tiene una salida registrada; deshaz la salida primero.";
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** ¿El valor pedido es distinto del que ya tiene el activo? Compara por valor (centavos, día, entero). */
    private function cambiaElCampo(FixedAsset $asset, string $campo, mixed $nuevo): bool
    {
        $actual = $asset->{$campo};

        return match ($campo) {
            'purchase_value' => $this->centavos($actual) !== $this->centavos($nuevo),
            'useful_life_years' => (int) $actual !== (int) $nuevo,
            'depreciation_rate', 'salvage_percent' => $this->centavosONulo($actual) !== $this->centavosONulo($nuevo),
            'purchase_date' => $this->fechaONula($actual) !== $this->fechaONula($nuevo),
            'depreciation_start' => $this->fechaONula($actual, true) !== $this->fechaONula($nuevo, true),
            'category_id', 'subcategory_id' => $this->enteroONulo($actual) !== $this->enteroONulo($nuevo),
            default => $actual !== $nuevo,
        };
    }

    private function centavos(mixed $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    private function centavosONulo(mixed $valor): ?int
    {
        return $valor === null || $valor === '' ? null : $this->centavos($valor);
    }

    private function enteroONulo(mixed $valor): ?int
    {
        return $valor === null || $valor === '' ? null : (int) $valor;
    }

    private function fechaONula(mixed $valor, bool $primerDelMes = false): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $fecha = Carbon::parse($valor);

        return ($primerDelMes ? $fecha->startOfMonth() : $fecha)->toDateString();
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

    /**
     * Filtros compartidos por `index` y `resumen`. `$omitir` lista los grupos que
     * no se aplican (`status`, `category`, `entity`, `assigned`, `has_value`,
     * `year`): los conteos de `resumen` omiten el grupo propio para que marcar una
     * opción no ponga en cero a las demás del mismo grupo.
     *
     * @param  array<int, string>  $omitir
     */
    private function aplicarFiltros(Builder $query, Request $request, array $omitir = []): Builder
    {
        if ($request->boolean('active_only')) {
            $query->active();
        }

        foreach (['enterprise_id', 'branch_id', 'area_id', 'subcategory_id'] as $filtro) {
            if ($request->filled($filtro)) {
                $query->where($filtro, $request->input($filtro));
            }
        }

        foreach (['status' => 'status', 'category' => 'category_id', 'entity' => 'entity_id'] as $grupo => $columna) {
            if (in_array($grupo, $omitir, true)) {
                continue;
            }
            $valores = $this->lista($request, $columna);
            if ($valores !== []) {
                $query->whereIn($columna, $valores);
            }
        }

        if (! in_array('assigned', $omitir, true)) {
            match ($request->input('assigned')) {
                'si' => $query->whereHas('asignacionActiva'),
                'no' => $query->whereDoesntHave('asignacionActiva'),
                default => null,
            };
        }

        if (! in_array('has_value', $omitir, true)) {
            match ($request->input('has_value')) {
                'si' => $query->whereNotNull('purchase_value'),
                'no' => $query->whereNull('purchase_value'),
                default => null,
            };
        }

        if (! in_array('year', $omitir, true)) {
            $rangos = array_values(array_intersect($this->lista($request, 'year'), array_keys(self::RANGOS_ANIO)));
            if ($rangos !== []) {
                $this->aplicarRangosAnio($query, $rangos);
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
                foreach (['brand', 'category', 'subcategory'] as $relacion) {
                    $q->orWhereHas($relacion, fn ($r) => $r->whereRaw("name LIKE ? ESCAPE '!'", [$term]));
                }
                $q->orWhereHas('asignacionActiva', fn ($r) => $r->whereRaw("assignee_name LIKE ? ESCAPE '!'", [$term]));
            });
        }

        return $query;
    }

    /** @param  array<int, string>  $rangos  claves de RANGOS_ANIO */
    private function aplicarRangosAnio(Builder $query, array $rangos): void
    {
        $query->where(function ($q) use ($rangos) {
            foreach ($rangos as $clave) {
                [$desde, $hasta] = self::RANGOS_ANIO[$clave];
                $q->orWhere(function ($r) use ($desde, $hasta) {
                    $r->whereNotNull('purchase_date');
                    if ($desde) {
                        $r->whereDate('purchase_date', '>=', $desde);
                    }
                    if ($hasta) {
                        $r->whereDate('purchase_date', '<=', $hasta);
                    }
                });
            }
        });
    }

    /** Valores separados por coma de un parámetro; en las columnas `*_id` solo se aceptan enteros. */
    private function lista(Request $request, string $clave): array
    {
        $crudo = $request->input($clave);
        if (is_array($crudo)) {
            $crudo = implode(',', $crudo);
        }

        $valores = array_filter(array_map('trim', explode(',', (string) $crudo)), fn ($v) => $v !== '');
        if (str_ends_with($clave, '_id')) {
            $valores = array_filter($valores, 'ctype_digit');
        }

        return array_values(array_unique($valores));
    }

    private function ordenar(Builder $query, Request $request): void
    {
        $campo = in_array($request->input('sort'), self::ORDENES, true) ? $request->input('sort') : 'name';
        $dir = strtolower((string) $request->input('dir')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($campo, $dir);
        if ($campo !== 'name') {
            $query->orderBy('name');
        }
        $query->orderBy('id');
    }

    /**
     * Resumen de depreciación a la fecha de hoy; se agrega al JSON, no se guarda.
     *
     * ADVERTENCIA: `depreciacion` NO es una columna, solo un atributo en memoria. No llamar
     * save()/update() sobre la instancia después de `conDepreciacion`: intentaría escribir
     * una columna inexistente.
     */
    private function conDepreciacion(FixedAsset $activo): FixedAsset
    {
        $activo->setAttribute('depreciacion', $this->depreciacion->resumen($activo));

        return $activo;
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
