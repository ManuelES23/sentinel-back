<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProduccionEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code,abbreviation',
        'proceso:id,folio_proceso,productor_id,lote_id,recepcion_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'proceso.recepcion:id,salida_campo_id',
        'proceso.recepcion.salidaCampo:id,variedad_id',
        'proceso.recepcion.salidaCampo.variedad:id,nombre',
        'variedad:id,nombre',
        'recipe:id,name,code,recipe_type,peso_pieza,output_quantity,output_product_id',
        'recipe.items:id,recipe_id,group_key,quantity',
        'recipe.outputProduct:id,name,brand_id',
        'recipe.outputProduct.brand:id,name,code',
        'creador:id,name',
        'detalles',
        'detalles.proceso:id,folio_proceso,productor_id,lote_id,recepcion_id',
        'detalles.proceso.productor:id,nombre,apellido',
        'detalles.proceso.lote:id,nombre,numero_lote',
        'detalles.proceso.recepcion:id,salida_campo_id',
        'detalles.proceso.recepcion.salidaCampo:id,variedad_id',
        'detalles.proceso.recepcion.salidaCampo.variedad:id,nombre',
        'detalles.recipe:id,name,code,output_quantity,output_product_id',
        'detalles.recipe.items:id,recipe_id,group_key,quantity',
        'detalles.recipe.outputProduct:id,name,brand_id',
        'detalles.recipe.outputProduct.brand:id,name,code',
        'detalles.creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = ProduccionEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_produccion', 'like', "%{$search}%")
                  ->orWhere('numero_pallet', 'like', "%{$search}%")
                  ->orWhere('tipo_empaque', 'like', "%{$search}%")
                  ->orWhere('etiqueta', 'like', "%{$search}%");
            });
        }

        $producciones = $query->orderByDesc('fecha_produccion')->orderByDesc('id')->get();

        $producciones->each(function (ProduccionEmpaque $produccion) {
            $this->syncAggregateFieldsFromDetalles($produccion);

            if (! $produccion->is_cola) {
                return;
            }

            $produccion->cajas_objetivo = $this->resolveCajasObjetivo($produccion);
        });

        return response()->json(['success' => true, 'data' => $producciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'required|date',
            'turno' => 'nullable|string|max:50',
            'variedad_id' => 'nullable|exists:variedades,id',
            'linea_empaque' => 'nullable|string|max:100',
            'numero_pallet' => 'nullable|string|max:100',
            'lote_producto_terminado' => 'nullable|string|max:100',
            'pallet_qr_id' => 'nullable|string|max:36',
            'total_cajas' => 'required|integer|min:1',
            'cajas_objetivo' => 'nullable|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'marca' => 'nullable|string|max:150',
            'presentacion' => 'nullable|string|max:150',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'is_cola' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($validated, $request) {
                    $validated['status'] = $validated['status'] ?? 'empacado';
                    $validated['is_cola'] = $validated['is_cola'] ?? false;
                    $validated['en_cuarto_frio'] = false;
                    $validated['created_by'] = $request->user()->id;
                    $validated['folio_produccion'] = $this->generarFolio($validated);

                    // Auto-resolver variedad_id desde el proceso si no viene
                    if (empty($validated['variedad_id']) && !empty($validated['proceso_id'])) {
                        $proceso = ProcesoEmpaque::with([
                            'etapa:id,variedad_id',
                            'recepcion:id,salida_campo_id',
                            'recepcion.salidaCampo:id,variedad_id',
                        ])->find($validated['proceso_id']);

                        $variedadId = $proceso?->etapa?->variedad_id
                            ?? $proceso?->recepcion?->salidaCampo?->variedad_id;

                        if ($variedadId) {
                            $validated['variedad_id'] = $variedadId;
                        }
                    }

                    // Si es cola, usar nomenclatura COLA-XXXX
                    if ($validated['is_cola'] && (empty($validated['numero_pallet']) || str_starts_with($validated['numero_pallet'], 'COLA-'))) {
                        $validated['numero_pallet'] = $this->generarNumeroCola($validated['entity_id'], $validated['temporada_id']);
                    }

                    // Generar UUID para QR del pallet si no viene
                    if (empty($validated['pallet_qr_id'])) {
                        $validated['pallet_qr_id'] = (string) Str::uuid();
                    }

                    // Si es cola y tiene receta, auto-set cajas_objetivo desde el item grupo 'caja'
                    if ($validated['is_cola'] && !empty($validated['recipe_id']) && empty($validated['cajas_objetivo'])) {
                        $recipe = Recipe::with('items')->find($validated['recipe_id']);
                        if ($recipe) {
                            $cajaItem = $recipe->items->firstWhere('group_key', 'caja');
                            if ($cajaItem && (int) $cajaItem->quantity > 0) {
                                $validated['cajas_objetivo'] = (int) $cajaItem->quantity;
                            } elseif ($recipe->output_quantity > 1) {
                                $validated['cajas_objetivo'] = (int) $recipe->output_quantity;
                            }
                        }
                    }

                    // Auto-calcular peso neto si hay receta con peso_pieza y no se envió peso manual
                    if (!empty($validated['recipe_id']) && empty($validated['peso_neto_kg'])) {
                        $recipe = $recipe ?? Recipe::find($validated['recipe_id']);
                        if ($recipe && $recipe->peso_pieza > 0) {
                            $validated['peso_neto_kg'] = round($validated['total_cajas'] * (float) $recipe->peso_pieza, 2);
                        }
                    }

                    $produccion = ProduccionEmpaque::create($validated);

                    // Si es cola, crear el primer detalle automáticamente
                    if ($validated['is_cola']) {
                        $produccion->detalles()->create([
                            'numero_entrada' => 1,
                            'proceso_id' => $validated['proceso_id'],
                            'recipe_id' => $validated['recipe_id'] ?? null,
                            'fecha_produccion' => $validated['fecha_produccion'],
                            'total_cajas' => $validated['total_cajas'],
                            'peso_neto_kg' => $validated['peso_neto_kg'] ?? null,
                            'turno' => $validated['turno'] ?? null,
                            'observaciones' => $validated['observaciones'] ?? null,
                            'created_by' => $request->user()->id,
                        ]);
                    }

                    $produccion->load($this->eagerLoad);

                    return response()->json([
                        'success' => true,
                        'message' => 'Producción creada correctamente',
                        'data' => $produccion,
                    ], 201);
                });
            } catch (QueryException $e) {
                $isDuplicateFolio = (int) ($e->errorInfo[1] ?? 0) === 1062
                    && str_contains(strtolower($e->getMessage()), 'folio_produccion');

                if (!$isDuplicateFolio || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No fue posible generar un folio único de producción. Intenta nuevamente.',
        ], 409);
    }

    public function update(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'sometimes|date',
            'turno' => 'nullable|string|max:50',
            'variedad_id' => 'nullable|exists:variedades,id',
            'linea_empaque' => 'nullable|string|max:100',
            'numero_pallet' => 'nullable|string|max:100',
            'lote_producto_terminado' => 'nullable|string|max:100',
            'total_cajas' => 'sometimes|integer|min:1',
            'cajas_objetivo' => 'nullable|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'marca' => 'nullable|string|max:150',
            'presentacion' => 'nullable|string|max:150',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'is_cola' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        $produccion->update($validated);
        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Producción actualizada',
            'data' => $produccion,
        ]);
    }

    public function destroy(ProduccionEmpaque $produccion): JsonResponse
    {
        $produccion->delete();

        return response()->json(['success' => true, 'message' => 'Producción eliminada']);
    }

    /**
     * Obtener el siguiente número de pallet consecutivo para una entidad.
     */
    public function nextPalletNumber(Request $request): JsonResponse
    {
        $request->validate(['entity_id' => 'required|exists:entities,id']);

        $entity = Entity::find($request->entity_id);
        $abbreviation = $entity->abbreviation ?: 'PLT';

        $lastPallet = ProduccionEmpaque::where('entity_id', $entity->id)
            ->where('numero_pallet', 'like', $abbreviation . '-%')
            ->selectRaw("MAX(CAST(SUBSTRING(numero_pallet, ?) AS UNSIGNED)) as max_num", [strlen($abbreviation) + 2])
            ->value('max_num');

        $nextNum = ($lastPallet ?? 0) + 1;

        return response()->json([
            'success' => true,
            'data' => [
                'abbreviation' => $abbreviation,
                'next_number' => str_pad($nextNum, 4, '0', STR_PAD_LEFT),
                'full_pallet' => $abbreviation . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT),
            ],
        ]);
    }

    /**
     * Toggle estado cuarto frío de un pallet.
     */
    public function toggleCuartoFrio(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        $nuevoEstado = ! $produccion->en_cuarto_frio;

        if ($nuevoEstado) {
            $validated = $request->validate([
                'peso_bascula_kg' => 'required|numeric|min:0.01',
            ]);

            $produccion->update([
                'en_cuarto_frio' => true,
                'peso_bascula_kg' => $validated['peso_bascula_kg'],
            ]);
        } else {
            $produccion->update([
                'en_cuarto_frio' => false,
            ]);
        }

        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => $produccion->en_cuarto_frio
                ? 'Pallet marcado en cuarto frío'
                : 'Pallet retirado de cuarto frío',
            'data' => $produccion,
        ]);
    }

    /**
     * Toggle masivo de cuarto frío para múltiples pallets.
     */
    public function toggleCuartoFrioMasivo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:produccion_empaque,id',
            'en_cuarto_frio' => 'required|boolean',
        ]);

        if ($validated['en_cuarto_frio']) {
            return response()->json([
                'status' => 'error',
                'message' => 'Para ingresar a cuarto frío debes capturar peso báscula por pallet. Usa el ingreso individual.',
            ], 422);
        }

        ProduccionEmpaque::whereIn('id', $validated['ids'])
            ->update(['en_cuarto_frio' => $validated['en_cuarto_frio']]);

        $label = $validated['en_cuarto_frio'] ? 'ingresados a' : 'retirados de';

        return response()->json([
            'success' => true,
            'message' => count($validated['ids']) . " pallet(s) {$label} cuarto frío",
        ]);
    }

    /**
     * Actualizar peso báscula de un pallet ya ingresado en cuarto frío.
     */
    public function actualizarPesoBascula(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        if (! $produccion->en_cuarto_frio) {
            return response()->json([
                'status' => 'error',
                'message' => 'El pallet debe estar en cuarto frío para capturar peso báscula desde esta acción',
            ], 422);
        }

        $validated = $request->validate([
            'peso_bascula_kg' => 'required|numeric|min:0.01',
        ]);

        $produccion->update([
            'peso_bascula_kg' => $validated['peso_bascula_kg'],
        ]);

        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Peso báscula actualizado correctamente',
            'data' => $produccion,
        ]);
    }

    /**
     * Listar pallets cola (incompletos) para poder continuar al día siguiente.
     */
    public function colaPallets(Request $request): JsonResponse
    {
        $query = ProduccionEmpaque::where('is_cola', true)
            ->with([
                'entity:id,name,code,abbreviation',
                'proceso:id,folio_proceso,productor_id,lote_id',
                'proceso.productor:id,nombre,apellido',
                'recipe:id,name,code',
            ]);

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('temporada_id')) {
            $query->where('temporada_id', $request->temporada_id);
        }

        $pallets = $query->orderByDesc('fecha_produccion')->orderByDesc('id')->get();

        $pallets->each(function (ProduccionEmpaque $produccion) {
            $this->syncAggregateFieldsFromDetalles($produccion);
        });

        $pallets->each(function (ProduccionEmpaque $produccion) {
            $produccion->cajas_objetivo = $this->resolveCajasObjetivo($produccion);
        });

        return response()->json(['success' => true, 'data' => $pallets]);
    }

    /**
     * Completar un pallet cola agregando una nueva entrada de producción.
     * Acepta los mismos datos que crear un pallet (wizard completo).
     * Si la nueva entrada tiene receta/calibre/caja diferente → pallet mixto.
     */
    public function completarCola(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        if (!$produccion->is_cola) {
            return response()->json([
                'status' => 'error',
                'message' => 'Este pallet no es una cola, no se puede completar',
            ], 422);
        }

        $validated = $request->validate([
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'required|date',
            'total_cajas' => 'required|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'marca' => 'nullable|string|max:150',
            'presentacion' => 'nullable|string|max:150',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'turno' => 'nullable|string|max:50',
            'marcar_completo' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        // Calcular objetivo real de cajas (prioriza grupo 'caja' de receta)
        $produccion->loadMissing([
            'recipe.items:id,recipe_id,group_key,quantity',
            'detalles.recipe.items:id,recipe_id,group_key,quantity',
        ]);
        $cajasObjetivo = $this->resolveCajasObjetivo($produccion);

        if ($cajasObjetivo > 0 && (!$produccion->cajas_objetivo || $produccion->cajas_objetivo <= 1)) {
            $produccion->update(['cajas_objetivo' => $cajasObjetivo]);
            $produccion->refresh();
        }

        // Validar que no exceda el máximo restante del pallet cola
        if ($cajasObjetivo > 0) {
            $cajasActuales = $produccion->total_cajas;
            $nuevasCajas = $validated['total_cajas'];
            $total = $cajasActuales + $nuevasCajas;

            if ($total > $cajasObjetivo) {
                $restantes = max($cajasObjetivo - $cajasActuales, 0);
                return response()->json([
                    'status' => 'error',
                    'message' => "Excede el objetivo de la receta. Cajas actuales: {$cajasActuales}, objetivo: {$cajasObjetivo}, máximo a agregar: {$restantes}",
                ], 422);
            }
        }

        return DB::transaction(function () use ($produccion, $validated, $request) {
            // Determinar número de entrada
            $maxEntrada = $produccion->detalles()->max('numero_entrada') ?? 0;
            $numeroEntrada = $maxEntrada + 1;

            // Auto-calcular peso si hay receta en esta entrada
            $recipeId = $validated['recipe_id'] ?? $produccion->recipe_id;
            if (empty($validated['peso_neto_kg']) && $recipeId) {
                $recipe = Recipe::find($recipeId);
                if ($recipe && $recipe->peso_pieza > 0) {
                    $validated['peso_neto_kg'] = round($validated['total_cajas'] * (float) $recipe->peso_pieza, 2);
                }
            }

            // Crear detalle con info completa de producto
            $produccion->detalles()->create([
                'numero_entrada' => $numeroEntrada,
                'proceso_id' => $validated['proceso_id'],
                'recipe_id' => $validated['recipe_id'] ?? null,
                'tipo_empaque' => $validated['tipo_empaque'] ?? null,
                'marca' => $validated['marca'] ?? null,
                'presentacion' => $validated['presentacion'] ?? null,
                'etiqueta' => $validated['etiqueta'] ?? null,
                'calibre' => $validated['calibre'] ?? null,
                'categoria' => $validated['categoria'] ?? null,
                'fecha_produccion' => $validated['fecha_produccion'],
                'total_cajas' => $validated['total_cajas'],
                'peso_neto_kg' => $validated['peso_neto_kg'] ?? null,
                'turno' => $validated['turno'] ?? null,
                'observaciones' => $validated['observaciones'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Detectar si es pallet mixto comparando con entradas anteriores
            $isMixto = $produccion->is_mixto; // mantener si ya era mixto
            if (!$isMixto) {
                $detalles = $produccion->detalles()->get();
                $recipeIds = $detalles->pluck('recipe_id')->unique()->filter()->values();
                $calibres = $detalles->pluck('calibre')->unique()->filter()->values();
                $tiposEmpaque = $detalles->pluck('tipo_empaque')->unique()->filter()->values();

                // Es mixto si hay más de una receta, calibre o tipo de empaque diferente
                $isMixto = $recipeIds->count() > 1
                    || $calibres->count() > 1
                    || $tiposEmpaque->count() > 1;
            }

            // Recalcular totales del pallet padre desde los detalles ya persistidos.
            $produccion->refresh()->load('detalles');
            $aggregateFields = $this->buildAggregateFieldsFromDetalles($produccion);
            $totalCajas = $aggregateFields['total_cajas'];
            $totalPeso = $aggregateFields['peso_neto_kg'];

            $updateData = [
                'total_cajas' => $totalCajas,
                'peso_neto_kg' => $totalPeso,
                'is_mixto' => $isMixto,
            ];

            // Marcar completo si lo indica el usuario, o si alcanzó el objetivo
            $marcarCompleto = $validated['marcar_completo'] ?? false;
            $cajasObjetivoActual = max((int) ($produccion->cajas_objetivo ?? 0), $this->resolveCajasObjetivo($produccion));

            if ($marcarCompleto || ($cajasObjetivoActual > 0 && $totalCajas >= $cajasObjetivoActual)) {
                $updateData['is_cola'] = false;

                // Reasignar número de pallet: COLA-XXXX → PREFIJO-XXXX consecutivo
                if (str_starts_with($produccion->numero_pallet, 'COLA-')) {
                    $entity = Entity::find($produccion->entity_id);
                    $abbreviation = $entity?->abbreviation ?: 'PLT';

                    $lastPallet = ProduccionEmpaque::where('entity_id', $produccion->entity_id)
                        ->where('numero_pallet', 'like', $abbreviation . '-%')
                        ->selectRaw("MAX(CAST(SUBSTRING(numero_pallet, ?) AS UNSIGNED)) as max_num", [strlen($abbreviation) + 2])
                        ->value('max_num');

                    $nextNum = ($lastPallet ?? 0) + 1;
                    $updateData['numero_pallet'] = $abbreviation . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
                }
            }

            $produccion->update($updateData);
            $produccion->load($this->eagerLoad);
            $this->syncAggregateFieldsFromDetalles($produccion);

            $mixtoMsg = $isMixto ? ' (Pallet mixto)' : '';
            $message = $produccion->is_cola
                ? "Entrada #{$numeroEntrada} agregada{$mixtoMsg}. Cajas: {$totalCajas}" . ($produccion->cajas_objetivo ? "/{$produccion->cajas_objetivo}" : "")
                : "Cola completada{$mixtoMsg}. Pallet listo con {$totalCajas} cajas";

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $produccion,
            ]);
        });
    }

    /**
     * Obtener siguiente número de cola consecutivo para una entidad.
     */
    public function nextColaNumber(Request $request): JsonResponse
    {
        $request->validate([
            'entity_id' => 'required|exists:entities,id',
            'temporada_id' => 'nullable|exists:temporadas,id',
        ]);

        $query = ProduccionEmpaque::where('entity_id', $request->entity_id)
            ->where('numero_pallet', 'like', 'COLA-%');

        if ($request->filled('temporada_id')) {
            $query->where('temporada_id', $request->temporada_id);
        }

        $lastCola = $query->selectRaw("MAX(CAST(SUBSTRING(numero_pallet, 6) AS UNSIGNED)) as max_num")
            ->value('max_num');

        $nextNum = ($lastCola ?? 0) + 1;

        return response()->json([
            'success' => true,
            'data' => [
                'prefix' => 'COLA',
                'next_number' => str_pad($nextNum, 4, '0', STR_PAD_LEFT),
                'full_pallet' => 'COLA-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT),
            ],
        ]);
    }

    /**
     * Buscar pallet por QR (para escaneo de colas).
     */
    public function buscarPorQr(Request $request): JsonResponse
    {
        $request->validate(['qr_code' => 'required|string']);

        $produccion = ProduccionEmpaque::where('pallet_qr_id', $request->qr_code)
            ->with($this->eagerLoad)
            ->first();

        if (!$produccion) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pallet no encontrado con ese código QR',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $produccion,
            'is_cola' => $produccion->is_cola,
        ]);
    }

    private function generarFolio(array $data): string
    {
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        $prefix = "PROD-{$entityId}-";

        $lastFolio = ProduccionEmpaque::withTrashed()
            ->where('entity_id', $data['entity_id'])
            ->where('folio_produccion', 'like', $prefix . '%')
            ->orderByDesc('folio_produccion')
            ->value('folio_produccion');

        $nextNumber = $lastFolio
            ? (int) substr($lastFolio, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function resolveCajasObjetivo(ProduccionEmpaque $produccion): int
    {
        // Buscar la cantidad del grupo 'caja' en los items de la receta del pallet
        $cajaItem = $produccion->recipe?->items
            ?->firstWhere('group_key', 'caja');
        if ($cajaItem && (int) $cajaItem->quantity > 0) {
            return (int) $cajaItem->quantity;
        }

        // Buscar en las recetas de los detalles (entradas)
        $cajaItemDetalle = $produccion->detalles
            ->map(fn($d) => $d->recipe?->items?->firstWhere('group_key', 'caja'))
            ->filter()
            ->first();
        if ($cajaItemDetalle && (int) $cajaItemDetalle->quantity > 0) {
            return (int) $cajaItemDetalle->quantity;
        }

        // Fallback legacy: output_quantity si es mayor a 1
        $porReceta = (int) ($produccion->recipe?->output_quantity ?? 0);
        if ($porReceta > 1) {
            return $porReceta;
        }

        // Solo usar el valor persistido como último recurso.
        $directo = (int) ($produccion->cajas_objetivo ?? 0);
        return $directo > 1 ? $directo : 0;
    }

    private function buildAggregateFieldsFromDetalles(ProduccionEmpaque $produccion): array
    {
        $detalles = $produccion->relationLoaded('detalles')
            ? $produccion->detalles
            : $produccion->detalles()->get();

        if ($detalles->isEmpty()) {
            return [
                'total_cajas' => (int) ($produccion->total_cajas ?? 0),
                'peso_neto_kg' => round((float) ($produccion->peso_neto_kg ?? 0), 2),
            ];
        }

        return [
            'total_cajas' => (int) $detalles->sum(fn (ProduccionEmpaqueDetalle $detalle) => (int) ($detalle->total_cajas ?? 0)),
            'peso_neto_kg' => round(
                (float) $detalles->sum(fn (ProduccionEmpaqueDetalle $detalle) => (float) ($detalle->peso_neto_kg ?? 0)),
                2,
            ),
        ];
    }

    private function syncAggregateFieldsFromDetalles(ProduccionEmpaque $produccion): void
    {
        $aggregateFields = $this->buildAggregateFieldsFromDetalles($produccion);

        $produccion->forceFill([
            'total_cajas' => $aggregateFields['total_cajas'],
            'peso_neto_kg' => $aggregateFields['peso_neto_kg'],
        ]);
    }

    private function generarNumeroCola(int $entityId, int $temporadaId): string
    {
        $lastCola = ProduccionEmpaque::withTrashed()
            ->where('entity_id', $entityId)
            ->where('temporada_id', $temporadaId)
            ->where('numero_pallet', 'like', 'COLA-%')
            ->selectRaw("MAX(CAST(SUBSTRING(numero_pallet, 6) AS UNSIGNED)) as max_num")
            ->value('max_num');

        $nextNum = ($lastCola ?? 0) + 1;

        return 'COLA-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
