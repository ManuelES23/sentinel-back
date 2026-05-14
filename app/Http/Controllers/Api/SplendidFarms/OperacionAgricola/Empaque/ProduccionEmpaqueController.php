<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Entity;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Recipe;
use Carbon\Carbon;
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
        'proceso.recepcion:id,salida_campo_id,lote_producto_terminado',
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
        'detalles.proceso.recepcion:id,salida_campo_id,lote_producto_terminado',
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

        $asOf = null;
        if ($request->filled('as_of_date')) {
            // created_at/deleted_at se almacenan en UTC; convertir el corte local a UTC evita excluir registros válidos del mismo día.
            $asOf = Carbon::parse($request->input('as_of_date'), 'America/Mexico_City')
                ->endOfDay()
                ->utc();
            $query->withTrashed()
                ->where('created_at', '<=', $asOf)
                ->where(function ($q) use ($asOf) {
                    $q->whereNull('deleted_at')->orWhere('deleted_at', '>', $asOf);
                });
        }

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

        $producciones = $query->distinct()->orderByDesc('fecha_produccion')->orderByDesc('id')->get();

        if ($asOf && $producciones->isNotEmpty()) {
            $ids = $producciones->pluck('id')->filter()->values();
            $logsByModel = ActivityLog::query()
                ->where('model', 'ProduccionEmpaque')
                ->whereIn('model_id', $ids)
                ->where('created_at', '<=', $asOf)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['model_id', 'new_values'])
                ->groupBy('model_id');

            $producciones->each(function (ProduccionEmpaque $produccion) use ($logsByModel) {
                $logs = $logsByModel->get($produccion->id, collect());
                if ($logs->isEmpty()) {
                    return;
                }

                // Conservar estado real por defecto; solo cambiar si hay un log explícito del campo.
                $historicoEnCuartoFrio = (bool) $produccion->en_cuarto_frio;
                $encontroCambioCuartoFrio = false;
                foreach ($logs as $log) {
                    if (is_array($log->new_values) && array_key_exists('en_cuarto_frio', $log->new_values)) {
                        $historicoEnCuartoFrio = filter_var($log->new_values['en_cuarto_frio'], FILTER_VALIDATE_BOOLEAN);
                        $encontroCambioCuartoFrio = true;
                    }
                }

                if (! $encontroCambioCuartoFrio) {
                    return;
                }

                $produccion->setAttribute('en_cuarto_frio', $historicoEnCuartoFrio);
                $produccion->setAttribute('en_cuarto_frio_historico', $historicoEnCuartoFrio);
            });
        }

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
        return DB::transaction(function () use ($produccion) {
            $produccion->loadMissing('detalles');

            $esPalletMixto = (bool) $produccion->is_mixto && $produccion->detalles->isNotEmpty();

            if (! $esPalletMixto) {
                $produccion->delete();

                return response()->json(['success' => true, 'message' => 'Producción eliminada']);
            }

            $colasFallback = collect();
            $numerosCola = $this->extractMixSourceNumeroPallets($produccion->observaciones);
            if (! empty($numerosCola)) {
                $colasFallback = ProduccionEmpaque::withTrashed()
                    ->where('entity_id', $produccion->entity_id)
                    ->where('temporada_id', $produccion->temporada_id)
                    ->whereIn('numero_pallet', $numerosCola)
                    ->get();
            }

            $detallesPorCola = [];

            foreach ($produccion->detalles as $detalle) {
                $sourceColaId = $this->extractMixSourceColaId($detalle->observaciones);

                if (! $sourceColaId && $colasFallback->isNotEmpty()) {
                    $matchByProceso = $colasFallback->first(
                        fn (ProduccionEmpaque $cola) => (int) ($cola->proceso_id ?? 0) > 0
                            && (int) ($cola->proceso_id ?? 0) === (int) ($detalle->proceso_id ?? 0)
                    );

                    $sourceColaId = $matchByProceso?->id ?? $colasFallback->first()?->id;
                }

                if (! $sourceColaId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No fue posible identificar la cola origen para revertir este pallet mixto',
                    ], 422);
                }

                $detallesPorCola[$sourceColaId][] = $detalle;
            }

            $createdBy = (int) (auth()->id() ?: $produccion->created_by);

            foreach ($detallesPorCola as $sourceColaId => $detalles) {
                $cola = ProduccionEmpaque::withTrashed()->find($sourceColaId);

                if (! $cola) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "No se encontró la cola origen #{$sourceColaId} para revertir el mixteo",
                    ], 422);
                }

                if ($cola->trashed()) {
                    $cola->restore();
                    $cola->refresh();
                }

                $nextEntrada = (int) ($cola->detalles()->max('numero_entrada') ?? 0) + 1;

                foreach ($detalles as $detalle) {
                    $cola->detalles()->create([
                        'numero_entrada' => $nextEntrada++,
                        'proceso_id' => $detalle->proceso_id,
                        'recipe_id' => $detalle->recipe_id,
                        'tipo_empaque' => $detalle->tipo_empaque,
                        'marca' => $detalle->marca,
                        'presentacion' => $detalle->presentacion,
                        'etiqueta' => $detalle->etiqueta,
                        'calibre' => $detalle->calibre,
                        'categoria' => $detalle->categoria,
                        'fecha_produccion' => $detalle->fecha_produccion,
                        'total_cajas' => (int) ($detalle->total_cajas ?? 0),
                        'peso_neto_kg' => (float) ($detalle->peso_neto_kg ?? 0),
                        'turno' => $detalle->turno,
                        'observaciones' => $this->stripMixSourceTag($detalle->observaciones),
                        'created_by' => $createdBy,
                    ]);
                }

                $cola->update([
                    'total_cajas' => (int) $cola->detalles()->sum('total_cajas'),
                    'peso_neto_kg' => round((float) $cola->detalles()->sum('peso_neto_kg'), 2),
                    'is_cola' => true,
                    'is_mixto' => false,
                ]);
            }

            $produccion->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pallet mixto eliminado y colas origen restauradas',
            ]);
        });
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
     * Revertir mixteo de un pallet mixto: reconstruye colas origen y elimina pallet.
     */
    public function revertirMixteo(ProduccionEmpaque $produccion): JsonResponse
    {
        return DB::transaction(function () use ($produccion) {
            $produccion->loadMissing('detalles');

            if (! $produccion->is_mixto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Este pallet no es mixto',
                ], 422);
            }

            // Primero intentar usar la estructura JSON guardada
            $sourceColasFromJSON = $this->extractSourceColasFromMixture($produccion->observaciones);

            if (! empty($sourceColasFromJSON) && $produccion->detalles->isNotEmpty()) {
                // Tenemos estructura JSON: reconstruir colas según esa estructura
                return $this->revertirMixteoDesdeJSON($produccion, $sourceColasFromJSON);
            }

            // Fallback: reconstruir desde detalles (legacy)
            if ($produccion->detalles->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Este pallet no tiene detalles para revertir',
                ], 422);
            }

            return $this->revertirMixteoDesdeDetalles($produccion);
        });
    }

    /**
     * Revertir parcialmente un pallet mixto: extrae una cola origen específica.
     */
    public function revertirMixteoCola(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        $request->merge([
            'source_cola_id' => $request->input('source_cola_id', $request->input('sourceColaId')),
            'source_cola_numero_pallet' => $request->input('source_cola_numero_pallet', $request->input('sourceColaNumeroPallet')),
            'source_proceso_id' => $request->input('source_proceso_id', $request->input('sourceProcesoId')),
        ]);

        $validated = $request->validate([
            'source_cola_id' => 'nullable|integer|min:1|required_without_all:source_cola_numero_pallet,source_proceso_id',
            'source_cola_numero_pallet' => 'nullable|string|max:100|required_without_all:source_cola_id,source_proceso_id',
            'source_proceso_id' => 'nullable|integer|min:1|required_without_all:source_cola_id,source_cola_numero_pallet',
        ]);

        return DB::transaction(function () use ($produccion, $validated) {
            $produccion->loadMissing('detalles');

            if (! $produccion->is_mixto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Este pallet no es mixto',
                ], 422);
            }

            $sourceColaId = (int) ($validated['source_cola_id'] ?? 0);
            $sourceColaNumero = $this->normalizePalletName($validated['source_cola_numero_pallet'] ?? null);
            $sourceProcesoId = (int) ($validated['source_proceso_id'] ?? 0);

            if ($sourceColaId <= 0 && $sourceColaNumero !== '') {
                $colaByNumero = ProduccionEmpaque::withTrashed()
                    ->where('entity_id', $produccion->entity_id)
                    ->where('temporada_id', $produccion->temporada_id)
                    ->where('numero_pallet', $sourceColaNumero)
                    ->first();

                if (! $colaByNumero) {
                    $colaByNumero = ProduccionEmpaque::withTrashed()
                        ->where('entity_id', $produccion->entity_id)
                        ->where('temporada_id', $produccion->temporada_id)
                        ->get()
                        ->first(fn (ProduccionEmpaque $cola) => $this->normalizePalletName($cola->numero_pallet) === $sourceColaNumero);
                }

                if ($colaByNumero) {
                    $sourceColaId = (int) $colaByNumero->id;
                }
            }

            if ($sourceColaId <= 0 && $sourceProcesoId > 0) {
                $colaByProceso = ProduccionEmpaque::withTrashed()
                    ->where('entity_id', $produccion->entity_id)
                    ->where('temporada_id', $produccion->temporada_id)
                    ->where('is_cola', true)
                    ->where('proceso_id', $sourceProcesoId)
                    ->latest('id')
                    ->first();

                if ($colaByProceso) {
                    $sourceColaId = (int) $colaByProceso->id;
                }
            }

            if ($sourceColaId <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No fue posible identificar la cola origen seleccionada',
                ], 422);
            }

            $detallesParaEstaCola = $produccion->detalles
                ->filter(fn ($d) => $this->extractMixSourceColaId($d->observaciones) === $sourceColaId)
                ->values();

            $cola = ProduccionEmpaque::withTrashed()->find($sourceColaId);
            if (! $cola) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No se encontró la cola origen #{$sourceColaId}",
                ], 422);
            }

            if ($detallesParaEstaCola->isEmpty()) {
                $detallesParaEstaCola = $produccion->detalles
                    ->filter(fn ($d) => (int) ($d->proceso_id ?? 0) > 0 && (int) ($d->proceso_id ?? 0) === (int) ($cola->proceso_id ?? 0))
                    ->values();
            }

            if ($detallesParaEstaCola->isEmpty() && $sourceProcesoId > 0) {
                $detallesParaEstaCola = $produccion->detalles
                    ->filter(fn ($d) => (int) ($d->proceso_id ?? 0) === $sourceProcesoId)
                    ->values();
            }

            if ($detallesParaEstaCola->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La cola seleccionada no forma parte de este pallet mixto',
                ], 422);
            }

            if ($cola->trashed()) {
                $cola->restore();
                $cola->refresh();
            }

            $createdBy = (int) (auth()->id() ?: $produccion->created_by);
            $nextEntrada = (int) ($cola->detalles()->max('numero_entrada') ?? 0) + 1;

            foreach ($detallesParaEstaCola as $detalle) {
                $cola->detalles()->create([
                    'numero_entrada' => $nextEntrada++,
                    'proceso_id' => $detalle->proceso_id,
                    'recipe_id' => $detalle->recipe_id,
                    'tipo_empaque' => $detalle->tipo_empaque,
                    'marca' => $detalle->marca,
                    'presentacion' => $detalle->presentacion,
                    'etiqueta' => $detalle->etiqueta,
                    'calibre' => $detalle->calibre,
                    'categoria' => $detalle->categoria,
                    'fecha_produccion' => $detalle->fecha_produccion,
                    'total_cajas' => (int) ($detalle->total_cajas ?? 0),
                    'peso_neto_kg' => (float) ($detalle->peso_neto_kg ?? 0),
                    'turno' => $detalle->turno,
                    'observaciones' => $this->stripMixSourceTag($detalle->observaciones),
                    'created_by' => $createdBy,
                ]);
            }

            $cola->update([
                'total_cajas' => (int) $cola->detalles()->sum('total_cajas'),
                'peso_neto_kg' => round((float) $cola->detalles()->sum('peso_neto_kg'), 2),
                'is_cola' => true,
                'is_mixto' => false,
            ]);

            ProduccionEmpaqueDetalle::whereIn('id', $detallesParaEstaCola->pluck('id')->all())->delete();

            $produccion->refresh()->load('detalles');

            if ($produccion->detalles->isEmpty()) {
                $mixtoEliminado = $produccion->numero_pallet;
                $produccion->delete();

                return response()->json([
                    'success' => true,
                    'message' => "Se extrajo la cola {$cola->numero_pallet} y el pallet mixto '{$mixtoEliminado}' quedó vacío, por lo que se eliminó",
                    'data' => [
                        'pallet_mixto_eliminado' => $mixtoEliminado,
                        'cola_restaurada' => [
                            'id' => $cola->id,
                            'numero_pallet' => $cola->numero_pallet,
                            'total_cajas' => $cola->total_cajas,
                            'peso_neto_kg' => $cola->peso_neto_kg,
                        ],
                    ],
                ]);
            }

            $aggregateFields = $this->buildAggregateFieldsFromDetalles($produccion);
            $recipeIds = $produccion->detalles->pluck('recipe_id')->unique()->filter()->values();
            $calibres = $produccion->detalles->pluck('calibre')->unique()->filter()->values();
            $tiposEmpaque = $produccion->detalles->pluck('tipo_empaque')->unique()->filter()->values();

            $isMixto = $recipeIds->count() > 1
                || $calibres->count() > 1
                || $tiposEmpaque->count() > 1;

            $sourceColas = collect($this->extractSourceColasFromMixture($produccion->observaciones))
                ->filter(fn ($source) => (int) ($source['id'] ?? 0) !== $sourceColaId)
                ->values()
                ->all();

            $remainingDetalles = $produccion->detalles
                ->map(fn ($d) => [
                    'calibre' => $d->calibre,
                    'total_cajas' => (int) ($d->total_cajas ?? 0),
                ])
                ->all();

            $produccion->update([
                'total_cajas' => $aggregateFields['total_cajas'],
                'peso_neto_kg' => $aggregateFields['peso_neto_kg'],
                'is_mixto' => $isMixto,
                'observaciones' => $this->buildMixtureStructure(
                    $sourceColas,
                    $this->buildCalibreBreakdownFromDetalles($remainingDetalles),
                ),
            ]);

            $produccion->load($this->eagerLoad);

            return response()->json([
                'success' => true,
                'message' => "Se extrajo la cola {$cola->numero_pallet} del pallet mixto",
                'data' => [
                    'produccion' => $produccion,
                    'cola_restaurada' => [
                        'id' => $cola->id,
                        'numero_pallet' => $cola->numero_pallet,
                        'total_cajas' => $cola->total_cajas,
                        'peso_neto_kg' => $cola->peso_neto_kg,
                    ],
                ],
            ]);
        });
    }

    /**
     * Revertir mixteo usando la estructura JSON guardada
     */
    private function revertirMixteoDesdeJSON(ProduccionEmpaque $produccion, array $sourceColasFromJSON): JsonResponse
    {
        $createdBy = (int) (auth()->id() ?: $produccion->created_by);
        $colasRestauradas = [];

        foreach ($sourceColasFromJSON as $colaPlan) {
            $sourceColaId = (int) ($colaPlan['id'] ?? 0);
            $cajasPlanificadas = (int) ($colaPlan['cajas'] ?? 0);
            $pesoPlanificado = (float) ($colaPlan['peso_neto_kg'] ?? 0);

            if ($sourceColaId <= 0) {
                continue;
            }

            // Buscar o restaurar cola origen
            $cola = ProduccionEmpaque::withTrashed()->find($sourceColaId);
            if (! $cola) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No se encontró la cola origen #{$sourceColaId}",
                ], 422);
            }

            if ($cola->trashed()) {
                $cola->restore();
                $cola->refresh();
            }

            // Recolectar detalles de este pallet mixto que pertenecen a esta cola
            $detallesParaEstaCola = $produccion->detalles
                ->filter(fn ($d) => $this->extractMixSourceColaId($d->observaciones) == $sourceColaId)
                ->values();

            $nextEntrada = (int) ($cola->detalles()->max('numero_entrada') ?? 0) + 1;

            foreach ($detallesParaEstaCola as $detalle) {
                $cola->detalles()->create([
                    'numero_entrada' => $nextEntrada++,
                    'proceso_id' => $detalle->proceso_id,
                    'recipe_id' => $detalle->recipe_id,
                    'tipo_empaque' => $detalle->tipo_empaque,
                    'marca' => $detalle->marca,
                    'presentacion' => $detalle->presentacion,
                    'etiqueta' => $detalle->etiqueta,
                    'calibre' => $detalle->calibre,
                    'categoria' => $detalle->categoria,
                    'fecha_produccion' => $detalle->fecha_produccion,
                    'total_cajas' => (int) ($detalle->total_cajas ?? 0),
                    'peso_neto_kg' => (float) ($detalle->peso_neto_kg ?? 0),
                    'turno' => $detalle->turno,
                    'observaciones' => $this->stripMixSourceTag($detalle->observaciones),
                    'created_by' => $createdBy,
                ]);
            }

            $cola->update([
                'total_cajas' => (int) $cola->detalles()->sum('total_cajas'),
                'peso_neto_kg' => round((float) $cola->detalles()->sum('peso_neto_kg'), 2),
                'is_cola' => true,
                'is_mixto' => false,
            ]);

            $colasRestauradas[] = [
                'id' => $cola->id,
                'numero_pallet' => $cola->numero_pallet,
                'total_cajas' => $cola->total_cajas,
                'peso_neto_kg' => $cola->peso_neto_kg,
            ];
        }

        $mixtoEliminado = $produccion->numero_pallet;
        $produccion->delete();

        return response()->json([
            'success' => true,
            'message' => "Pallet mixto '{$mixtoEliminado}' revertido. Se restauraron " . count($colasRestauradas) . ' cola(s)',
            'data' => [
                'pallet_mixto_eliminado' => $mixtoEliminado,
                'colas_restauradas' => $colasRestauradas,
            ],
        ]);
    }

    /**
     * Revertir mixteo desde detalles (fallback legacy)
     */
    private function revertirMixteoDesdeDetalles(ProduccionEmpaque $produccion): JsonResponse
    {
        $colasFallback = collect();
        $numerosCola = $this->extractMixSourceNumeroPallets($produccion->observaciones);
        if (! empty($numerosCola)) {
            $colasFallback = ProduccionEmpaque::withTrashed()
                ->where('entity_id', $produccion->entity_id)
                ->where('temporada_id', $produccion->temporada_id)
                ->whereIn('numero_pallet', $numerosCola)
                ->get();
        }

        $detallesPorCola = [];

        foreach ($produccion->detalles as $detalle) {
            $sourceColaId = $this->extractMixSourceColaId($detalle->observaciones);

            if (! $sourceColaId && $colasFallback->isNotEmpty()) {
                $matchByProceso = $colasFallback->first(
                    fn (ProduccionEmpaque $cola) => (int) ($cola->proceso_id ?? 0) > 0
                        && (int) ($cola->proceso_id ?? 0) === (int) ($detalle->proceso_id ?? 0)
                );

                $sourceColaId = $matchByProceso?->id ?? $colasFallback->first()?->id;
            }

            if (! $sourceColaId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No fue posible identificar la cola origen para revertir este pallet mixto',
                ], 422);
            }

            $detallesPorCola[$sourceColaId][] = $detalle;
        }

        $createdBy = (int) (auth()->id() ?: $produccion->created_by);
        $colasRestauradas = [];

        foreach ($detallesPorCola as $sourceColaId => $detalles) {
            $cola = ProduccionEmpaque::withTrashed()->find($sourceColaId);

            if (! $cola) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No se encontró la cola origen #{$sourceColaId} para revertir el mixteo",
                ], 422);
            }

            if ($cola->trashed()) {
                $cola->restore();
                $cola->refresh();
            }

            $nextEntrada = (int) ($cola->detalles()->max('numero_entrada') ?? 0) + 1;

            foreach ($detalles as $detalle) {
                $cola->detalles()->create([
                    'numero_entrada' => $nextEntrada++,
                    'proceso_id' => $detalle->proceso_id,
                    'recipe_id' => $detalle->recipe_id,
                    'tipo_empaque' => $detalle->tipo_empaque,
                    'marca' => $detalle->marca,
                    'presentacion' => $detalle->presentacion,
                    'etiqueta' => $detalle->etiqueta,
                    'calibre' => $detalle->calibre,
                    'categoria' => $detalle->categoria,
                    'fecha_produccion' => $detalle->fecha_produccion,
                    'total_cajas' => (int) ($detalle->total_cajas ?? 0),
                    'peso_neto_kg' => (float) ($detalle->peso_neto_kg ?? 0),
                    'turno' => $detalle->turno,
                    'observaciones' => $this->stripMixSourceTag($detalle->observaciones),
                    'created_by' => $createdBy,
                ]);
            }

            $cola->update([
                'total_cajas' => (int) $cola->detalles()->sum('total_cajas'),
                'peso_neto_kg' => round((float) $cola->detalles()->sum('peso_neto_kg'), 2),
                'is_cola' => true,
                'is_mixto' => false,
            ]);

            $colasRestauradas[] = [
                'id' => $cola->id,
                'numero_pallet' => $cola->numero_pallet,
                'total_cajas' => $cola->total_cajas,
                'peso_neto_kg' => $cola->peso_neto_kg,
            ];
        }

        $mixtoEliminado = $produccion->numero_pallet;
        $produccion->delete();

        return response()->json([
            'success' => true,
            'message' => "Pallet mixto '{$mixtoEliminado}' revertido. Se restauraron " . count($colasRestauradas) . ' cola(s)',
            'data' => [
                'pallet_mixto_eliminado' => $mixtoEliminado,
                'colas_restauradas' => $colasRestauradas,
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
                'recipe.items:id,recipe_id,group_key,quantity',
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

        // Transformar para asegurar que items se incluyan en JSON
        $data = $pallets->map(function (ProduccionEmpaque $produccion) {
            return [
                'id' => $produccion->id,
                'numero_pallet' => $produccion->numero_pallet,
                'total_cajas' => $produccion->total_cajas,
                'cajas_objetivo' => $produccion->cajas_objetivo,
                'peso_neto_kg' => $produccion->peso_neto_kg,
                'en_cuarto_frio' => $produccion->en_cuarto_frio,
                'is_cola' => $produccion->is_cola,
                'is_mixto' => $produccion->is_mixto,
                'observaciones' => $produccion->observaciones,
                'fecha_produccion' => $produccion->fecha_produccion,
                'turno' => $produccion->turno,
                'recipe' => $produccion->recipe ? [
                    'id' => $produccion->recipe->id,
                    'name' => $produccion->recipe->name,
                    'code' => $produccion->recipe->code,
                    'items' => $produccion->recipe->items->map(fn($item) => [
                        'id' => $item->id,
                        'group_key' => $item->group_key,
                        'quantity' => $item->quantity,
                    ])->values()->all(),
                ] : null,
                'entity' => $produccion->entity ? [
                    'id' => $produccion->entity->id,
                    'name' => $produccion->entity->name,
                    'code' => $produccion->entity->code,
                ] : null,
                'proceso' => $produccion->proceso,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
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
     * Mixtear múltiples colas en un solo pallet mixto dentro de cuarto frío.
     */
    public function mixtearColas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mix_items' => 'required|array|min:2',
            'mix_items.*.cola_id' => 'required|integer|distinct|exists:produccion_empaque,id',
            'mix_items.*.cajas' => 'required|integer|min:1',
            'numero_pallet' => 'nullable|string|max:100',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $mixItems = collect($validated['mix_items'])->keyBy('cola_id');
            $colaIds = $mixItems->keys()->values()->all();

            $colas = ProduccionEmpaque::with(['detalles'])
                ->whereIn('id', $colaIds)
                ->lockForUpdate()
                ->get();

            if ($colas->count() < 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Debes seleccionar al menos 2 colas para mixtear',
                ], 422);
            }

            if ($colas->contains(fn (ProduccionEmpaque $cola) => ! $cola->is_cola)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solo se pueden mixtear pallets cola',
                ], 422);
            }

            if ($colas->pluck('entity_id')->unique()->count() > 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Las colas deben pertenecer a la misma planta',
                ], 422);
            }

            if ($colas->pluck('temporada_id')->unique()->count() > 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Las colas deben pertenecer a la misma temporada',
                ], 422);
            }

            $base = $colas->first();
            $totalCajas = 0;

            $totalPeso = 0.0;
            $pesoBascula = 0.0;
            $variedadUnica = $colas->pluck('variedad_id')->filter()->unique();
            $allEnCuartoFrio = $colas->every(fn (ProduccionEmpaque $cola) => (bool) $cola->en_cuarto_frio);

            $detallesParaNuevoPallet = collect();
            $colasMixteadas = []; // Array para guardar info de cada cola mixteada

            foreach ($colas as $cola) {
                $mixItem = $mixItems->get($cola->id);
                $cajasSolicitadas = (int) ($mixItem['cajas'] ?? 0);

                if ($cajasSolicitadas <= 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Cantidad inválida para la cola {$cola->numero_pallet}",
                    ], 422);
                }

                $cajasDisponiblesPrevias = $cola->detalles->isEmpty()
                    ? (int) ($cola->total_cajas ?? 0)
                    : (int) $cola->detalles->sum('total_cajas');

                if ($cajasDisponiblesPrevias <= 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "La cola {$cola->numero_pallet} no tiene cajas disponibles para mixtear",
                    ], 422);
                }

                $cola->loadMissing([
                    'recipe.items:id,recipe_id,group_key,quantity',
                    'detalles.recipe.items:id,recipe_id,group_key,quantity',
                ]);

                $cajasObjetivoReceta = $this->resolveCajasObjetivo($cola);
                $maxPermitidoCola = $cajasObjetivoReceta > 0
                    ? min($cajasDisponiblesPrevias, $cajasObjetivoReceta)
                    : $cajasDisponiblesPrevias;

                if ($cajasSolicitadas > $maxPermitidoCola) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "La cola {$cola->numero_pallet} permite máximo {$maxPermitidoCola} cajas por receta/disponible",
                    ], 422);
                }
            }

            // Validar si todas las colas comparten la misma receta
            $recetasUnicas = $colas->pluck('recipe_id')->filter()->unique();
            if ($recetasUnicas->count() === 1) {
                // Todas las colas tienen la MISMA receta: validar total contra el límite de esa receta
                $primeraReceta = $colas->first()->recipe;
                if ($primeraReceta) {
                    $primeraReceta->loadMissing('items:id,recipe_id,group_key,quantity');
                    
                    // Buscar el item con group_key = "Caja" que contiene el límite
                    $cajaItem = $primeraReceta->items
                        ->where('group_key', 'Caja')
                        ->first();
                    
                    if ($cajaItem && (float) $cajaItem->quantity > 0) {
                        $cajasLimiteReceta = (int) $cajaItem->quantity;
                        
                        $totalSolicitado = 0;
                        foreach ($colas as $cola) {
                            $mixItem = $mixItems->get($cola->id);
                            $totalSolicitado += (int) ($mixItem['cajas'] ?? 0);
                        }

                        if ($totalSolicitado > $cajasLimiteReceta) {
                            return response()->json([
                                'status' => 'error',
                                'message' => "El pallet mixto excede el límite permitido ({$cajasLimiteReceta} cajas)",
                            ], 422);
                        }
                    }
                }
            }

            foreach ($colas as $cola) {
                $mixItem = $mixItems->get($cola->id);
                $cajasSolicitadas = (int) ($mixItem['cajas'] ?? 0);

                if ($cajasSolicitadas <= 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Cantidad inválida para la cola {$cola->numero_pallet}",
                    ], 422);
                }

                if ($cola->detalles->isEmpty()) {
                    $cola->detalles()->create([
                        'numero_entrada' => 1,
                        'proceso_id' => $cola->proceso_id,
                        'recipe_id' => $cola->recipe_id,
                        'tipo_empaque' => $cola->tipo_empaque,
                        'marca' => $cola->marca,
                        'presentacion' => $cola->presentacion,
                        'etiqueta' => $cola->etiqueta,
                        'calibre' => $cola->calibre,
                        'categoria' => $cola->categoria,
                        'fecha_produccion' => $cola->fecha_produccion,
                        'total_cajas' => (int) ($cola->total_cajas ?? 0),
                        'peso_neto_kg' => (float) ($cola->peso_neto_kg ?? 0),
                        'turno' => $cola->turno,
                        'observaciones' => $cola->observaciones,
                        'created_by' => $request->user()->id,
                    ]);

                    $cola->load('detalles');
                }

                $cajasDisponibles = (int) $cola->detalles()->sum('total_cajas');
                if ($cajasDisponibles <= 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "La cola {$cola->numero_pallet} no tiene cajas disponibles para mixtear",
                    ], 422);
                }

                $cola->loadMissing([
                    'recipe.items:id,recipe_id,group_key,quantity',
                    'detalles.recipe.items:id,recipe_id,group_key,quantity',
                ]);
                $cajasObjetivoReceta = $this->resolveCajasObjetivo($cola);
                $maxPermitidoCola = $cajasObjetivoReceta > 0
                    ? min($cajasDisponibles, $cajasObjetivoReceta)
                    : $cajasDisponibles;

                if ($cajasSolicitadas > $maxPermitidoCola) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "La cola {$cola->numero_pallet} permite máximo {$maxPermitidoCola} cajas por receta/disponible",
                    ], 422);
                }

                $cajasRestantesPorTomar = $cajasSolicitadas;
                $detallesCola = $cola->detalles()->orderBy('numero_entrada')->get();

                foreach ($detallesCola as $detalle) {
                    if ($cajasRestantesPorTomar <= 0) {
                        break;
                    }

                    $detalleCajas = (int) ($detalle->total_cajas ?? 0);
                    if ($detalleCajas <= 0) {
                        continue;
                    }

                    $cajasTomadas = min($detalleCajas, $cajasRestantesPorTomar);
                    $pesoDetalle = (float) ($detalle->peso_neto_kg ?? 0);
                    $pesoTomado = $detalleCajas > 0
                        ? round($pesoDetalle * ($cajasTomadas / $detalleCajas), 2)
                        : 0.0;

                    $detallesParaNuevoPallet->push([
                        'proceso_id' => $detalle->proceso_id,
                        'recipe_id' => $detalle->recipe_id,
                        'tipo_empaque' => $detalle->tipo_empaque ?: $cola->tipo_empaque,
                        'marca' => $detalle->marca ?: $cola->marca,
                        'presentacion' => $detalle->presentacion ?: $cola->presentacion,
                        'etiqueta' => $detalle->etiqueta ?: $cola->etiqueta,
                        'calibre' => $detalle->calibre ?: $cola->calibre,
                        'categoria' => $detalle->categoria ?: $cola->categoria,
                        'fecha_produccion' => $detalle->fecha_produccion,
                        'total_cajas' => $cajasTomadas,
                        'peso_neto_kg' => $pesoTomado,
                        'turno' => $detalle->turno,
                        'observaciones' => $this->appendMixSourceTag($detalle->observaciones, (int) $cola->id),
                    ]);

                    $nuevoTotalDetalle = $detalleCajas - $cajasTomadas;
                    if ($nuevoTotalDetalle <= 0) {
                        $detalle->delete();
                    } else {
                        $detalle->update([
                            'total_cajas' => $nuevoTotalDetalle,
                            'peso_neto_kg' => max(round($pesoDetalle - $pesoTomado, 2), 0),
                        ]);
                    }

                    $cajasRestantesPorTomar -= $cajasTomadas;
                    $totalCajas += $cajasTomadas;
                    $totalPeso += $pesoTomado;
                }

                if ($cajasRestantesPorTomar > 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "No fue posible completar la extracción solicitada de la cola {$cola->numero_pallet}",
                    ], 422);
                }

                $pesoBasculaCola = (float) ($cola->peso_bascula_kg ?? 0);
                if ($pesoBasculaCola > 0 && $cajasDisponibles > 0) {
                    $pesoBascula += round($pesoBasculaCola * ($cajasSolicitadas / $cajasDisponibles), 2);
                }

                $cajasRestantesCola = (int) $cola->detalles()->sum('total_cajas');
                $pesoRestanteCola = round((float) $cola->detalles()->sum('peso_neto_kg'), 2);

                if ($cajasRestantesCola <= 0) {
                    $cola->delete();
                    continue;
                }

                $pesoBasculaRestante = null;
                if ($pesoBasculaCola > 0 && $cajasDisponibles > 0) {
                    $pesoBasculaRestante = round($pesoBasculaCola * ($cajasRestantesCola / $cajasDisponibles), 2);
                }

                $cola->update([
                    'total_cajas' => $cajasRestantesCola,
                    'peso_neto_kg' => $pesoRestanteCola,
                    'peso_bascula_kg' => $pesoBasculaRestante,
                    'is_cola' => true,
                ]);

                // Guardar info de esta cola en array para reversibilidad
                $colasMixteadas[] = [
                    'id' => (int) $cola->id,
                    'numero_pallet' => $cola->numero_pallet,
                    'cajas' => $cajasSolicitadas,
                    'peso_neto_kg' => round($totalCajas > 0 ? ($totalPeso * $cajasSolicitadas / $totalCajas) : 0, 2),
                    'calibre' => $cola->calibre,
                    'marca' => $cola->marca,
                    'tipo_empaque' => $cola->tipo_empaque,
                    'presentacion' => $cola->presentacion,
                    'lote_producto_terminado' => $cola->lote_producto_terminado,
                ];
            }

            if ($totalCajas <= 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No hay cajas válidas para crear el pallet mixto',
                ], 422);
            }

            $detallesCollection = collect($detallesParaNuevoPallet->all());
            $calibresUnicos = $detallesCollection->pluck('calibre')->filter(fn ($v) => filled($v))->unique()->values();
            $marcasUnicas = $detallesCollection->pluck('marca')->filter(fn ($v) => filled($v))->unique()->values();
            $empaquesUnicos = $detallesCollection->pluck('tipo_empaque')->filter(fn ($v) => filled($v))->unique()->values();
            $lotesPtUnicos = $colas->pluck('lote_producto_terminado')->filter(fn ($v) => filled($v))->unique()->values();
            $numeroPalletManual = $this->normalizePalletName($validated['numero_pallet'] ?? null);
            $numeroPalletFinal = $numeroPalletManual !== ''
                ? $numeroPalletManual
                : $this->generarNumeroPallet($base->entity_id);

            $palletExiste = ProduccionEmpaque::withTrashed()
                ->where('entity_id', $base->entity_id)
                ->get(['numero_pallet'])
                ->contains(fn (ProduccionEmpaque $pallet) => $this->normalizePalletName($pallet->numero_pallet) === $numeroPalletFinal);

            if ($palletExiste) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El número de pallet {$numeroPalletFinal} ya existe. Captura uno diferente.",
                ], 422);
            }

            $nuevoPallet = ProduccionEmpaque::create([
                'temporada_id' => $base->temporada_id,
                'entity_id' => $base->entity_id,
                'proceso_id' => null,
                'recipe_id' => null,
                'folio_produccion' => $this->generarFolio([
                    'entity_id' => $base->entity_id,
                ]),
                'fecha_produccion' => now()->toDateString(),
                'turno' => $base->turno,
                'variedad_id' => $variedadUnica->count() === 1 ? $variedadUnica->first() : null,
                'numero_pallet' => $numeroPalletFinal,
                'pallet_qr_id' => (string) Str::uuid(),
                'total_cajas' => $totalCajas,
                'peso_neto_kg' => round($totalPeso, 2),
                'peso_bascula_kg' => $allEnCuartoFrio && $pesoBascula > 0 ? $pesoBascula : null,
                'tipo_empaque' => $empaquesUnicos->count() === 1 ? $empaquesUnicos->first() : null,
                'marca' => $marcasUnicas->count() === 1 ? $marcasUnicas->first() : null,
                'presentacion' => $base->presentacion,
                'etiqueta' => $base->etiqueta,
                'calibre' => $calibresUnicos->count() === 1 ? $calibresUnicos->first() : null,
                'lote_producto_terminado' => $lotesPtUnicos->count() === 1 ? $lotesPtUnicos->first() : null,
                'categoria' => $base->categoria,
                'status' => 'en_almacen',
                'en_cuarto_frio' => $allEnCuartoFrio,
                'is_cola' => false,
                'is_mixto' => true,
                'observaciones' => $this->buildMixtureStructure(
                    $colasMixteadas,
                    $this->buildCalibreBreakdownFromDetalles($detallesCollection->all()),
                ),
                'created_by' => $request->user()->id,
            ]);

            $numeroEntrada = 1;

            foreach ($detallesParaNuevoPallet as $detalleNuevo) {
                $nuevoPallet->detalles()->create([
                    'numero_entrada' => $numeroEntrada++,
                    'proceso_id' => $detalleNuevo['proceso_id'],
                    'recipe_id' => $detalleNuevo['recipe_id'],
                    'tipo_empaque' => $detalleNuevo['tipo_empaque'],
                    'marca' => $detalleNuevo['marca'],
                    'presentacion' => $detalleNuevo['presentacion'],
                    'etiqueta' => $detalleNuevo['etiqueta'],
                    'calibre' => $detalleNuevo['calibre'],
                    'categoria' => $detalleNuevo['categoria'],
                    'fecha_produccion' => $detalleNuevo['fecha_produccion'],
                    'total_cajas' => $detalleNuevo['total_cajas'],
                    'peso_neto_kg' => $detalleNuevo['peso_neto_kg'],
                    'turno' => $detalleNuevo['turno'],
                    'observaciones' => $detalleNuevo['observaciones'],
                    'created_by' => $request->user()->id,
                ]);
            }

            $nuevoPallet->load($this->eagerLoad);
            $this->syncAggregateFieldsFromDetalles($nuevoPallet);

            return response()->json([
                'success' => true,
                'message' => "Se mixtearon {$colas->count()} colas en el pallet {$nuevoPallet->numero_pallet}",
                'data' => $nuevoPallet,
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

    private function generarNumeroPallet(int $entityId): string
    {
        $entity = Entity::find($entityId);
        $abbreviation = $entity?->abbreviation ?: 'PLT';

        $lastPallet = ProduccionEmpaque::withTrashed()
            ->where('entity_id', $entityId)
            ->where('numero_pallet', 'like', $abbreviation . '-%')
            ->selectRaw("MAX(CAST(SUBSTRING(numero_pallet, ?) AS UNSIGNED)) as max_num", [strlen($abbreviation) + 2])
            ->value('max_num');

        $nextNum = ($lastPallet ?? 0) + 1;

        return $abbreviation . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }

    private function appendMixSourceTag(?string $observaciones, int $sourceColaId): string
    {
        $base = trim((string) ($observaciones ?? ''));
        $tag = "MIXSRC:{$sourceColaId}";

        if ($base === '') {
            return $tag;
        }

        if (str_contains($base, $tag)) {
            return $base;
        }

        return $base . ' | ' . $tag;
    }

    private function extractMixSourceColaId(?string $observaciones): ?int
    {
        if (! $observaciones) {
            return null;
        }

        if (! preg_match('/MIXSRC:(\d+)/', $observaciones, $matches)) {
            return null;
        }

        $id = (int) ($matches[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function stripMixSourceTag(?string $observaciones): ?string
    {
        if ($observaciones === null) {
            return null;
        }

        $clean = preg_replace('/\s*\|?\s*MIXSRC:\d+\s*/', ' ', $observaciones);
        $clean = trim((string) $clean);

        return $clean === '' ? null : $clean;
    }

    /**
     * Construir estructura JSON de mixteo con todas las colas origen
     * @param array $colasMixteadas Array de colas con metadatos de origen
     * @param array $calibreBreakdown Resumen de cajas por calibre
     * @return string JSON con estructura de mixteo
     */
    private function buildMixtureStructure(array $colasMixteadas, array $calibreBreakdown = []): string
    {
        $structure = [
            'type' => 'mixture',
            'created_at' => now()->toIso8601String(),
            'source_colas' => $colasMixteadas,
            'calibre_breakdown' => $calibreBreakdown,
        ];
        return json_encode($structure, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Construye el resumen de cajas por calibre a partir de los detalles del nuevo pallet mixto.
     *
     * @param array $detalles
     * @return array<int,array{calibre:string,cajas:int}>
     */
    private function buildCalibreBreakdownFromDetalles(array $detalles): array
    {
        $breakdown = [];

        foreach ($detalles as $detalle) {
            $calibre = trim((string) ($detalle['calibre'] ?? ''));
            $key = $calibre !== '' ? $calibre : 'SIN_CALIBRE';
            $cajas = (int) ($detalle['total_cajas'] ?? 0);

            if (! isset($breakdown[$key])) {
                $breakdown[$key] = 0;
            }

            $breakdown[$key] += $cajas;
        }

        return collect($breakdown)
            ->map(fn ($cajas, $calibre) => [
                'calibre' => (string) $calibre,
                'cajas' => (int) $cajas,
            ])
            ->values()
            ->all();
    }

    private function normalizePalletName(?string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) ($value ?? '')));

        return trim((string) $normalized);
    }

    /**
     * Extraer estructura JSON de mixteo desde observaciones
     * @param ?string $observaciones Campo observaciones del pallet mixto
     * @return ?array Array con estructura de mixteo o null
     */
    private function extractMixtureStructure(?string $observaciones): ?array
    {
        if (!$observaciones || !str_contains($observaciones, '"type":"mixture"')) {
            return null;
        }

        // Buscar JSON entre llaves
        if (preg_match('/\{.*"type"\s*:\s*"mixture".*\}/', $observaciones, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['source_colas'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extraer colas origen desde observaciones del pallet mixto
     * @param ?string $observaciones Campo observaciones
     * @return array Array de colas [['id' => int, 'numero_pallet' => string, 'cajas' => int, 'peso_neto_kg' => float], ...]
     */
    private function extractSourceColasFromMixture(?string $observaciones): array
    {
        $mixture = $this->extractMixtureStructure($observaciones);
        if (!$mixture || !isset($mixture['source_colas'])) {
            return [];
        }
        return $mixture['source_colas'];
    }

    private function extractMixSourceNumeroPallets(?string $observaciones): array
    {
        if (! $observaciones) {
            return [];
        }

        if (! str_contains($observaciones, 'mixteo de colas:')) {
            return [];
        }

        $parts = explode('mixteo de colas:', $observaciones, 2);
        $raw = trim($parts[1] ?? '');

        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Actualizar un detalle específico de una producción (entrada).
     */
    public function updateDetalle(Request $request, ProduccionEmpaque $produccion, ProduccionEmpaqueDetalle $detalle): JsonResponse
    {
        // Verificar que el detalle pertenece a esta producción
        if ((int) $detalle->produccion_id !== (int) $produccion->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'El detalle no pertenece a esta producción',
            ], 404);
        }

        $validated = $request->validate([
            'fecha_produccion' => 'sometimes|date',
            'total_cajas' => 'sometimes|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'turno' => 'nullable|string|max:50',
            'calibre' => 'nullable|string|max:50',
            'observaciones' => 'nullable|string',
        ]);

        $detalle->update($validated);

        // Recalcular totales del pallet padre si es necesario
        $produccion->loadMissing('detalles');
        $totalCajasDetalles = $produccion->detalles->sum('total_cajas');
        $totalPesoDetalles = $produccion->detalles->sum('peso_neto_kg');

        // Si el pallet tiene detalles, actualizar sus totales también
        if ($produccion->detalles->isNotEmpty()) {
            $produccion->update([
                'total_cajas' => $totalCajasDetalles,
                'peso_neto_kg' => $totalPesoDetalles,
            ]);
        }

        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Detalle actualizado',
            'data' => $produccion,
        ]);
    }

    /**
     * Eliminar un detalle específico de una producción sin eliminar el pallet completo.
     */
    public function destroyDetalle(ProduccionEmpaque $produccion, ProduccionEmpaqueDetalle $detalle): JsonResponse
    {
        if ((int) $detalle->produccion_id !== (int) $produccion->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'El detalle no pertenece a esta producción',
            ], 404);
        }

        return DB::transaction(function () use ($produccion, $detalle) {
            $produccion->loadMissing('detalles');

            $sumDetallesCajas = (int) $produccion->detalles->sum('total_cajas');
            $sumDetallesPeso = (float) $produccion->detalles->sum('peso_neto_kg');

            // El registro base del pallet es lo que no está representado en detalles.
            $baseCajas = max(((int) $produccion->total_cajas) - $sumDetallesCajas, 0);
            $basePeso = max(((float) $produccion->peso_neto_kg) - $sumDetallesPeso, 0);

            $detalle->delete();

            $remainingCajas = (int) $produccion->detalles()->sum('total_cajas');
            $remainingPeso = (float) $produccion->detalles()->sum('peso_neto_kg');

            $produccion->update([
                'total_cajas' => $baseCajas + $remainingCajas,
                'peso_neto_kg' => round($basePeso + $remainingPeso, 2),
            ]);

            $produccion->load($this->eagerLoad);

            return response()->json([
                'success' => true,
                'message' => 'Registro de cola eliminado',
                'data' => $produccion,
            ]);
        });
    }
}

