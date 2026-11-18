<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Entity;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueConsumo;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Services\Empaque\CalculoConsumoReceta;
use App\Services\Empaque\ConsumoProduccion;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProduccionEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'temporada:id,nombre,cultivo_id',
        'temporada.cultivo:id,nombre',
        'entity:id,name,code,abbreviation',
        'proceso:id,folio_proceso,productor_id,lote_id,recepcion_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'proceso.recepcion:id,temporada_id,salida_campo_id,lote_id,variedad_id,folio_recepcion,lote_producto_terminado',
        'proceso.recepcion.lote:id,nombre,numero_lote,codigo',
        'proceso.recepcion.temporada:id,nombre,cultivo_id',
        'proceso.recepcion.temporada.cultivo:id,nombre',
        'proceso.recepcion.variedad:id,nombre',
        'proceso.recepcion.salidaCampo:id,variedad_id,lote_id',
        'proceso.recepcion.salidaCampo.lote:id,nombre,numero_lote,codigo',
        'proceso.recepcion.salidaCampo.variedad:id,nombre',
        'variedad:id,nombre',
        'recipe:id,name,code,recipe_type,output_quantity,output_product_id',
        'recipe.agroDetails:id,recipe_id,peso_pieza',
        'recipe.items:id,recipe_id,group_key,quantity',
        'recipe.outputProduct:id,name,brand_id',
        'recipe.outputProduct.brand:id,name,code',
        'recipe.recipeCalibres:id,recipe_id,calibre_id',
        'recipe.recipeCalibres.calibre:id,nombre,valor',
        'recipe.recipeCalibres.plus:id,recipe_calibre_id,product_id,is_organic,notes',
        'recipe.recipeCalibres.plus.product:id,code,name,brand_id',
        'recipe.recipeCalibres.plus.product.brand:id,name,code',
        'creador:id,name',
        'detalles',
        'detalles.proceso:id,folio_proceso,productor_id,lote_id,recepcion_id',
        'detalles.proceso.productor:id,nombre,apellido',
        'detalles.proceso.lote:id,nombre,numero_lote',
        'detalles.proceso.recepcion:id,temporada_id,salida_campo_id,lote_id,variedad_id,folio_recepcion,lote_producto_terminado',
        'detalles.proceso.recepcion.lote:id,nombre,numero_lote,codigo',
        'detalles.proceso.recepcion.temporada:id,nombre,cultivo_id',
        'detalles.proceso.recepcion.temporada.cultivo:id,nombre',
        'detalles.proceso.recepcion.variedad:id,nombre',
        'detalles.proceso.recepcion.salidaCampo:id,variedad_id,lote_id',
        'detalles.proceso.recepcion.salidaCampo.lote:id,nombre,numero_lote,codigo',
        'detalles.proceso.recepcion.salidaCampo.variedad:id,nombre',
        'detalles.recipe:id,name,code,output_quantity,output_product_id',
        'detalles.recipe.recipeCalibres:id,recipe_id,calibre_id',
        'detalles.recipe.recipeCalibres.calibre:id,nombre,valor',
        'detalles.recipe.recipeCalibres.plus:id,recipe_calibre_id,product_id,is_organic,notes',
        'detalles.recipe.recipeCalibres.plus.product:id,code,name,brand_id',
        'detalles.recipe.recipeCalibres.plus.product.brand:id,name,code',
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
        if ($request->filled('productor_id')) {
            $productorId = $request->productor_id;
            $query->where(function ($q) use ($productorId) {
                $q->whereHas('proceso', fn ($sub) => $sub->where('productor_id', $productorId))
                  ->orWhereHas('detalles.proceso', fn ($sub) => $sub->where('productor_id', $productorId));
            });
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
            $historicoByModelId = [];

            try {
                ActivityLog::query()
                    ->where('model', 'ProduccionEmpaque')
                    ->whereIn('model_id', $ids)
                    ->where('created_at', '<=', $asOf)
                    ->orderBy('model_id')
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->chunk(1000, function ($logs) use (&$historicoByModelId) {
                        foreach ($logs as $log) {
                            $newValues = $log->new_values;

                            if (! is_array($newValues) || ! array_key_exists('en_cuarto_frio', $newValues)) {
                                continue;
                            }

                            $historicoByModelId[$log->model_id] = filter_var($newValues['en_cuarto_frio'], FILTER_VALIDATE_BOOLEAN);
                        }
                    });
            } catch (QueryException $exception) {
                // Si falla la consulta histórica (por datos legacy o alta carga), conservar estado actual para no romper el endpoint.
                report($exception);
            }

            if (! empty($historicoByModelId)) {
                $producciones->each(function (ProduccionEmpaque $produccion) use ($historicoByModelId) {
                    if (! array_key_exists($produccion->id, $historicoByModelId)) {
                        return;
                    }

                    $historicoEnCuartoFrio = (bool) $historicoByModelId[$produccion->id];
                    $produccion->setAttribute('en_cuarto_frio', $historicoEnCuartoFrio);
                    $produccion->setAttribute('en_cuarto_frio_historico', $historicoEnCuartoFrio);
                });
            }
        }

        $producciones->each(function (ProduccionEmpaque $produccion) {
            $this->syncAggregateFieldsFromDetalles($produccion);
            $this->hydrateDetalleLoteProductoTerminado($produccion);
            $this->hydrateResolvedLoteProductoTerminado($produccion);

            if (! $produccion->is_cola) {
                return;
            }

            $produccion->cajas_objetivo = $this->resolveCajasObjetivo($produccion);
        });

        $this->exponerAlternativasListado($producciones);

        return response()->json(['success' => true, 'data' => $producciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'recipe_id' => 'required|exists:recipes,id',
            'alternativas' => 'nullable|array',
            'alternativas.*.grupo' => 'required|string|max:50',
            'alternativas.*.recipe_item_id' => 'required|integer',
            'fecha_produccion' => 'nullable|date',
            'turno' => 'nullable|string|max:50',
            'variedad_id' => 'nullable|exists:variedades,id',
            'linea_empaque' => 'nullable|string|max:100',
            'numero_pallet' => 'nullable|string|max:100',
            'lote_producto_terminado' => 'nullable|string|max:100',
            'pallet_qr_id' => 'nullable|string|max:100',
            'total_cajas' => 'required|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'peso_bascula_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'marca' => 'nullable|string|max:150',
            'presentacion' => 'nullable|string|max:150',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'clasificacion' => 'nullable|in:convencional,organico',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'is_cola' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        $receta = $this->recetaValida((int) $validated['recipe_id']);
        $alternativas = $this->alternativasPedidas($validated) ?? [];
        $this->validarAlternativas($receta, $alternativas);

        if (array_key_exists('proceso_id', $validated) && filled($validated['proceso_id']) && empty($validated['variedad_id'])) {
            $proceso = ProcesoEmpaque::with([
                'etapa:id,variedad_id',
                'recepcion:id,salida_campo_id,variedad_id',
                'recepcion.salidaCampo:id,variedad_id',
            ])->find($validated['proceso_id']);

            $variedadId = $proceso?->etapa?->variedad_id
                ?? $proceso?->recepcion?->variedad_id
                ?? $proceso?->recepcion?->salidaCampo?->variedad_id;

            if ($variedadId) {
                $validated['variedad_id'] = $variedadId;
            }
        }

        $validated['fecha_produccion'] = $validated['fecha_produccion']
            ?? Carbon::now('America/Mexico_City')->toDateString();
        $validated['status'] = $validated['status'] ?? 'empacado';
        $validated['is_cola'] = (bool) ($validated['is_cola'] ?? false);

        if (empty($validated['peso_neto_kg']) && !empty($validated['recipe_id'])) {
            // peso_pieza vive en recipe_agro_details desde la generalización de Recetas.
            $pesoPieza = (float) Recipe::find($validated['recipe_id'])?->agroDetails?->peso_pieza;
            if ($pesoPieza > 0) {
                $validated['peso_neto_kg'] = round(((int) $validated['total_cajas']) * $pesoPieza, 2);
            }
        }

        return DB::transaction(function () use ($validated, $request, $receta, $alternativas) {
            $entity = Entity::findOrFail($validated['entity_id']);

            $numeroPalletManual = $this->normalizePalletName($validated['numero_pallet'] ?? null);
            $numeroPalletFinal = $numeroPalletManual !== ''
                ? $numeroPalletManual
                : $this->generarNumeroPallet((int) $validated['entity_id']);

            $palletExiste = ProduccionEmpaque::withTrashed()
                ->where('entity_id', $validated['entity_id'])
                ->get(['numero_pallet'])
                ->contains(fn (ProduccionEmpaque $pallet) => $this->normalizePalletName($pallet->numero_pallet) === $numeroPalletFinal);

            if ($palletExiste) {
                return response()->json([
                    'status' => 'error',
                    'message' => "El número de pallet {$numeroPalletFinal} ya existe. Captura uno diferente.",
                ], 422);
            }

            $produccion = ProduccionEmpaque::create([
                'temporada_id' => $validated['temporada_id'],
                'entity_id' => $validated['entity_id'],
                'proceso_id' => $validated['proceso_id'] ?? null,
                'recipe_id' => $validated['recipe_id'] ?? null,
                'folio_produccion' => $this->generarFolio([
                    'entity_id' => $validated['entity_id'],
                ]),
                'fecha_produccion' => $validated['fecha_produccion'],
                'turno' => $validated['turno'] ?? null,
                'variedad_id' => $validated['variedad_id'] ?? null,
                'linea_empaque' => $validated['linea_empaque'] ?? null,
                'numero_pallet' => $numeroPalletFinal,
                'lote_producto_terminado' => $validated['lote_producto_terminado'] ?? null,
                'pallet_qr_id' => $validated['pallet_qr_id'] ?? (string) Str::uuid(),
                'total_cajas' => $validated['total_cajas'],
                'peso_neto_kg' => isset($validated['peso_neto_kg']) ? round((float) $validated['peso_neto_kg'], 2) : null,
                'peso_bascula_kg' => isset($validated['peso_bascula_kg']) ? round((float) $validated['peso_bascula_kg'], 2) : null,
                'tipo_empaque' => $validated['tipo_empaque'] ?? null,
                'marca' => $validated['marca'] ?? null,
                'presentacion' => $validated['presentacion'] ?? null,
                'etiqueta' => $validated['etiqueta'] ?? null,
                'calibre' => $validated['calibre'] ?? null,
                'categoria' => $validated['categoria'] ?? null,
                'clasificacion' => $validated['clasificacion'] ?? null,
                'status' => $validated['status'],
                'is_cola' => $validated['is_cola'],
                'is_mixto' => false,
                'en_cuarto_frio' => false,
                'observaciones' => $validated['observaciones'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Pallet nuevo: por caja + tarima; si nace cerrado, también los insumos de cierre.
            $this->consumirPalletSinEntradas($produccion, $receta, $alternativas);

            $produccion->load($this->eagerLoad);
            $this->hydrateDetalleLoteProductoTerminado($produccion);
            $this->hydrateResolvedLoteProductoTerminado($produccion);

            return $this->respuestaEscritura($produccion, 'Producción creada correctamente', 201);
        });
    }

    public function update(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        if (($produccion->status ?? null) === 'embarcado') {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar un pallet que ya está embarcado',
            ], 422);
        }

        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'alternativas' => 'nullable|array',
            'alternativas.*.grupo' => 'required|string|max:50',
            'alternativas.*.recipe_item_id' => 'required|integer',
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
            'clasificacion' => 'nullable|in:convencional,organico',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'is_cola' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        // Re-resolver variedad_id cuando cambia el proceso
        if (array_key_exists('proceso_id', $validated) && !empty($validated['proceso_id'])) {
            if (empty($validated['variedad_id'])) {
                $proceso = ProcesoEmpaque::with([
                    'etapa:id,variedad_id',
                    'recepcion:id,salida_campo_id,variedad_id',
                    'recepcion.salidaCampo:id,variedad_id',
                ])->find($validated['proceso_id']);

                $variedadId = $proceso?->etapa?->variedad_id
                    ?? $proceso?->recepcion?->variedad_id
                    ?? $proceso?->recepcion?->salidaCampo?->variedad_id;

                if ($variedadId) {
                    $validated['variedad_id'] = $variedadId;
                }
            }
        }

        $pedidas = $this->alternativasPedidas($validated);
        unset($validated['alternativas']);

        $congelado = $produccion->congelado();
        $tieneEntradas = $produccion->detalles()->exists();
        $cambiaReceta = array_key_exists('recipe_id', $validated)
            && (int) ($validated['recipe_id'] ?? 0) !== (int) ($produccion->recipe_id ?? 0);
        $registradas = $this->alternativasRegistradas($produccion, null, $tieneEntradas);
        $cambianAlternativas = $this->cambianAlternativas($pedidas, $registradas);

        // Con entradas, receta y alternativas viven en cada entrada (updateDetalle).
        if ($tieneEntradas && ($cambiaReceta || $cambianAlternativas)) {
            throw ValidationException::withMessages(['recipe_id' => 'Cambia la receta en la entrada']);
        }

        $recetaNueva = null;
        if ($cambiaReceta) {
            $recetaNueva = $this->recetaValida((int) ($validated['recipe_id'] ?? 0));
        }
        if (! $tieneEntradas && ! empty($pedidas)) {
            $recetaPallet = $recetaNueva ?? ($produccion->recipe_id ? Recipe::with('items')->find($produccion->recipe_id) : null);
            if ($recetaPallet) {
                $this->validarAlternativas($recetaPallet, $pedidas);
            }
        }

        return DB::transaction(function () use ($produccion, $validated, $congelado, $tieneEntradas, $cambiaReceta, $recetaNueva, $pedidas, $registradas, $cambianAlternativas) {
            $previousEntityId = (int) $produccion->entity_id;
            $previousTotalCajas = (int) ($produccion->total_cajas ?? 0);
            $previousIsCola = (bool) ($produccion->is_cola ?? false);

            $produccion->update($validated);
            $produccion->loadMissing('detalles');

            if ($produccion->detalles->isNotEmpty()) {
                $produccion->update($this->buildAggregateFieldsFromDetalles($produccion));
            }

            $cambiaPlanta = $previousEntityId !== (int) $produccion->entity_id;
            $cambianCajas = $previousTotalCajas !== (int) ($produccion->total_cajas ?? 0);
            $cambiaCola = $previousIsCola !== (bool) ($produccion->is_cola ?? false);

            // Pallets anteriores al registro de consumos: se guardan sin mover inventario.
            if (! $congelado) {
                $consumo = $this->consumo();

                if ($tieneEntradas && $cambiaPlanta) {
                    $this->reconsumirPalletConEntradas($produccion);
                } elseif (! $tieneEntradas && ($cambiaPlanta || $cambiaReceta || $cambianCajas || $cambianAlternativas)) {
                    $receta = $recetaNueva ?? Recipe::with('items')->find($produccion->recipe_id);
                    $alternativas = $cambiaReceta
                        ? ($pedidas ?? [])
                        : $this->alternativasParaConsumir($registradas, $pedidas, $receta);

                    $consumo->devolverTodo($produccion);
                    if ($receta) {
                        $this->consumirPalletSinEntradas($produccion, $receta, $alternativas);
                    }
                } elseif ($cambiaCola) {
                    $this->ajustarCierre($produccion, $registradas, $pedidas);
                }
            }

            $produccion->load($this->eagerLoad);
            $this->hydrateResolvedLoteProductoTerminado($produccion);

            return $this->respuestaEscritura($produccion, 'Producción actualizada');
        });
    }

    public function destroy(ProduccionEmpaque $produccion): JsonResponse
    {
        if (($produccion->status ?? null) === 'embarcado') {
            return $this->rechazoEmbarcado();
        }

        return DB::transaction(function () use ($produccion) {
            $produccion->loadMissing('detalles');

            $esPalletMixto = (bool) $produccion->is_mixto && $produccion->detalles->isNotEmpty();

            if (! $esPalletMixto) {
                // Devuelve exactamente lo registrado; un pallet congelado no tiene registro.
                $this->consumo()->devolverTodo($produccion);

                $produccion->delete();

                return response()->json([
                    'success' => true,
                    'message' => 'Producción eliminada',
                    'faltantes' => $this->consumo()->faltantes(),
                ]);
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
                'marca' => $aggregateFields['marca'],
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

            // Borrar los detalles originales del mixto ya copiados a la cola restaurada,
            // para no dejarlos huérfanos (produccion_empaque_detalles no usa SoftDeletes).
            ProduccionEmpaqueDetalle::whereIn('id', $detallesParaEstaCola->pluck('id')->all())->delete();

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

            // Borrar los detalles originales del mixto ya copiados a la cola restaurada,
            // para no dejarlos huérfanos (produccion_empaque_detalles no usa SoftDeletes).
            ProduccionEmpaqueDetalle::whereIn('id', collect($detalles)->pluck('id')->all())->delete();

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
            // Sin receta propia del pallet, la entrada debe traerla.
            'recipe_id' => ($produccion->recipe_id ? 'nullable' : 'required').'|exists:recipes,id',
            'alternativas' => 'nullable|array',
            'alternativas.*.grupo' => 'required|string|max:50',
            'alternativas.*.recipe_item_id' => 'required|integer',
            'fecha_produccion' => 'required|date',
            'total_cajas' => 'required|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'marca' => 'nullable|string|max:150',
            'presentacion' => 'nullable|string|max:150',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'lote_producto_terminado' => 'nullable|string|max:100',
            'categoria' => 'nullable|string|max:50',
            'clasificacion' => 'nullable|in:convencional,organico',
            'turno' => 'nullable|string|max:50',
            'marcar_completo' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        $receta = filled($validated['recipe_id'] ?? null)
            ? $this->recetaValida((int) $validated['recipe_id'])
            : Recipe::with('items')->find($produccion->recipe_id);
        $alternativas = $this->alternativasPedidas($validated) ?? [];
        if ($receta) {
            $this->validarAlternativas($receta, $alternativas);
        }
        $congelado = $produccion->congelado();

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

        return DB::transaction(function () use ($produccion, $validated, $request, $receta, $alternativas, $congelado) {
            $base = $this->ensureBaseDetalleForColaCompletion($produccion, (int) $request->user()->id);
            if ($base) {
                // Lo consumido por caja de la cola sin desglose pasa a su entrada base.
                $this->consumo()->ligarBase($produccion, $base);
            }

            // Determinar número de entrada
            $maxEntrada = $produccion->detalles()->max('numero_entrada') ?? 0;
            $numeroEntrada = $maxEntrada + 1;

            // Auto-calcular peso si hay receta en esta entrada
            $recipeId = $validated['recipe_id'] ?? $produccion->recipe_id;
            if (empty($validated['peso_neto_kg']) && $recipeId) {
                $pesoPieza = (float) Recipe::find($recipeId)?->agroDetails?->peso_pieza;
                if ($pesoPieza > 0) {
                    $validated['peso_neto_kg'] = round($validated['total_cajas'] * $pesoPieza, 2);
                }
            }

            // Crear detalle con info completa de producto
            $detalle = $produccion->detalles()->create([
                'numero_entrada' => $numeroEntrada,
                'proceso_id' => $validated['proceso_id'],
                'recipe_id' => $validated['recipe_id'] ?? null,
                'tipo_empaque' => $validated['tipo_empaque'] ?? null,
                'marca' => $validated['marca'] ?? null,
                'presentacion' => $validated['presentacion'] ?? null,
                'etiqueta' => $validated['etiqueta'] ?? null,
                'calibre' => $validated['calibre'] ?? null,
                'lote_producto_terminado' => $validated['lote_producto_terminado'] ?? null,
                'categoria' => $validated['categoria'] ?? null,
                'fecha_produccion' => $validated['fecha_produccion'],
                'total_cajas' => $validated['total_cajas'],
                'peso_neto_kg' => $validated['peso_neto_kg'] ?? null,
                'turno' => $validated['turno'] ?? null,
                'observaciones' => $validated['observaciones'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // La entrada carga con sus insumos por caja (un pallet congelado no mueve inventario).
            if (! $congelado && $receta) {
                $this->consumo()->consumirPorCaja($produccion, $detalle, $receta, (int) $validated['total_cajas'], $alternativas);
            }

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

            if (array_key_exists('clasificacion', $validated)) {
                $updateData['clasificacion'] = $validated['clasificacion'] ?? 'convencional';
            }

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
            $produccion->loadMissing('detalles');

            if ($produccion->detalles->isNotEmpty()) {
                $produccion->update($this->buildAggregateFieldsFromDetalles($produccion));
            }

            // Con esta entrada el pallet quedó cerrado: insumos de cierre una sola vez.
            if (! $congelado && ! $produccion->is_cola) {
                $this->ajustarCierre($produccion, [], $alternativas);
            }

            $produccion->load($this->eagerLoad);

            $mixtoMsg = $isMixto ? ' (Pallet mixto)' : '';
            $message = $produccion->is_cola
                ? "Entrada #{$numeroEntrada} agregada{$mixtoMsg}. Cajas: {$totalCajas}" . ($produccion->cajas_objetivo ? "/{$produccion->cajas_objetivo}" : "")
                : "Cola completada{$mixtoMsg}. Pallet listo con {$totalCajas} cajas";

            return $this->respuestaEscritura($produccion, $message);
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
                        'lote_producto_terminado' => $detalle->lote_producto_terminado ?: $cola->lote_producto_terminado,
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

                // Guardar info de esta cola en array para reversibilidad. Debe hacerse
                // SIEMPRE, incluso si la cola quedó totalmente consumida y será eliminada
                // a continuación: si no, "Deshacer mixteo" no sabe que esta cola participó
                // y sus detalles quedan huérfanos sin poder reconstruirla.
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
                    'clasificacion' => $cola->clasificacion,
                ];

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

            // Clasificación del mixto derivada de las colas origen: si todas comparten
            // la misma clasificación se hereda tal cual; si combinan orgánico y
            // convencional, el mixto queda marcado como 'mixto'.
            $clasificacionesUnicas = collect($colasMixteadas)
                ->pluck('clasificacion')
                ->filter(fn ($v) => filled($v))
                ->unique()
                ->values();
            $clasificacionMixto = match (true) {
                $clasificacionesUnicas->count() === 1 => $clasificacionesUnicas->first(),
                $clasificacionesUnicas->count() > 1 => 'mixto',
                default => 'convencional',
            };

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
                'fecha_produccion' => Carbon::now('America/Mexico_City')->toDateString(),
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
                'clasificacion' => $clasificacionMixto,
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
                    'lote_producto_terminado' => $detalleNuevo['lote_producto_terminado'] ?? null,
                    'categoria' => $detalleNuevo['categoria'],
                    'fecha_produccion' => $detalleNuevo['fecha_produccion'],
                    'total_cajas' => $detalleNuevo['total_cajas'],
                    'peso_neto_kg' => $detalleNuevo['peso_neto_kg'],
                    'turno' => $detalleNuevo['turno'],
                    'observaciones' => $detalleNuevo['observaciones'],
                    'created_by' => $request->user()->id,
                ]);
            }

            $nuevoPallet->loadMissing('detalles');

            if ($nuevoPallet->detalles->isNotEmpty()) {
                $nuevoPallet->update($this->buildAggregateFieldsFromDetalles($nuevoPallet));
            }

            $nuevoPallet->load($this->eagerLoad);

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

    private function normalizePalletName(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return $normalized !== null ? strtoupper($normalized) : '';
    }

    private function resolveCajasObjetivo(ProduccionEmpaque $produccion): int
    {
        // Buscar la cantidad del grupo 'caja' en los items de la receta del pallet
        // Grupo «caja» sin distinguir mayúsculas, igual que el cálculo del consumo por caja.
        $esCaja = fn ($item) => mb_strtolower(trim((string) $item->group_key)) === 'caja';

        $cajaItem = $produccion->recipe?->items?->first($esCaja);
        if ($cajaItem && (int) $cajaItem->quantity > 0) {
            return (int) $cajaItem->quantity;
        }

        // Buscar en las recetas de los detalles (entradas)
        $cajaItemDetalle = $produccion->detalles
            ->map(fn($d) => $d->recipe?->items?->first($esCaja))
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
                'marca' => $produccion->marca ?? null,
            ];
        }

        $marcas = $detalles
            ->map(function (ProduccionEmpaqueDetalle $detalle) {
                $marca = trim((string) ($detalle->marca ?? ''));
                return $marca !== '' ? $marca : null;
            })
            ->filter()
            ->unique()
            ->values();

        return [
            'total_cajas' => (int) $detalles->sum(fn (ProduccionEmpaqueDetalle $detalle) => (int) ($detalle->total_cajas ?? 0)),
            'peso_neto_kg' => round(
                (float) $detalles->sum(fn (ProduccionEmpaqueDetalle $detalle) => (float) ($detalle->peso_neto_kg ?? 0)),
                2,
            ),
            'marca' => $marcas->count() === 1 ? $marcas->first() : ($produccion->marca ?? null),
        ];
    }

    /** Crea la entrada base (entrada 1) de una cola sin desglose; devuelve la creada o null si ya había entradas. */
    private function ensureBaseDetalleForColaCompletion(ProduccionEmpaque $produccion, int $fallbackUserId): ?ProduccionEmpaqueDetalle
    {
        if ($produccion->detalles()->exists()) {
            return null;
        }

        return $produccion->detalles()->create([
            'numero_entrada' => 1,
            'proceso_id' => $produccion->proceso_id,
            'recipe_id' => $produccion->recipe_id,
            'tipo_empaque' => $produccion->tipo_empaque,
            'marca' => $produccion->marca,
            'presentacion' => $produccion->presentacion,
            'etiqueta' => $produccion->etiqueta,
            'calibre' => $produccion->calibre,
            'lote_producto_terminado' => $produccion->lote_producto_terminado,
            'categoria' => $produccion->categoria,
            'fecha_produccion' => $produccion->fecha_produccion?->toDateString()
                ?? Carbon::now('America/Mexico_City')->toDateString(),
            'total_cajas' => (int) ($produccion->total_cajas ?? 0),
            'peso_neto_kg' => isset($produccion->peso_neto_kg)
                ? round((float) $produccion->peso_neto_kg, 2)
                : null,
            'turno' => $produccion->turno,
            'observaciones' => $produccion->observaciones,
            'created_by' => $produccion->created_by ?? $fallbackUserId,
        ]);
    }

    private function syncAggregateFieldsFromDetalles(ProduccionEmpaque $produccion): void
    {
        $aggregateFields = $this->buildAggregateFieldsFromDetalles($produccion);

        $produccion->forceFill([
            'total_cajas' => $aggregateFields['total_cajas'],
            'peso_neto_kg' => $aggregateFields['peso_neto_kg'],
            'marca' => $aggregateFields['marca'] ?? $produccion->marca,
        ]);
    }

    // ---- consumo de insumos (registro de consumos) ----

    /** Una instancia por petición: junta los faltantes y notifica tras confirmar la transacción. */
    private function consumo(): ConsumoProduccion
    {
        return app(ConsumoProduccion::class);
    }

    /** Receta con la que se puede descontar: existente, de empaque y activa. */
    private function recetaValida(int $recipeId): Recipe
    {
        $receta = Recipe::with('items')->find($recipeId);

        if (! $receta || $receta->recipe_type !== 'empaque' || ! $receta->is_active || $receta->status !== 'active') {
            throw ValidationException::withMessages(['recipe_id' => 'La receta no está activa o no es de empaque.']);
        }

        return $receta;
    }

    /**
     * `alternativas: [{grupo, recipe_item_id}]` → [grupo => recipe_item_id]; null si no se mandó
     * (front viejo: se usa la predeterminada o lo ya registrado).
     */
    private function alternativasPedidas(array $validated): ?array
    {
        if (! isset($validated['alternativas'])) {
            return null;
        }

        $mapa = [];
        foreach ($validated['alternativas'] as $alternativa) {
            $mapa[(string) $alternativa['grupo']] = (int) $alternativa['recipe_item_id'];
        }

        return $mapa;
    }

    private function validarAlternativas(Recipe $receta, array $alternativas): void
    {
        $errores = app(CalculoConsumoReceta::class)->validarAlternativas($receta, $alternativas);
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Alternativas registradas vigentes: grupo (minúsculas) => product_id elegidos. Por producto y no
     * por renglón: el editor de recetas borra y recrea los renglones, el producto se conserva.
     * Por entrada, del pallet sin entrada ($d = null) o de todo el pallet ($todoElPallet).
     *
     * @return array<string, int[]>
     */
    private function alternativasRegistradas(ProduccionEmpaque $p, ?ProduccionEmpaqueDetalle $d, bool $todoElPallet = false): array
    {
        $registradas = [];
        ProduccionEmpaqueConsumo::where('produccion_id', $p->id)
            ->when(! $todoElPallet, fn ($q) => $d ? $q->where('detalle_id', $d->id) : $q->whereNull('detalle_id'))
            ->whereNotNull('group_key')
            ->vigentes()
            ->orderBy('id')
            ->get(['group_key', 'product_id'])
            ->each(function (ProduccionEmpaqueConsumo $c) use (&$registradas) {
                $registradas[$this->claveGrupo($c->group_key)][] = (int) $c->product_id;
            });

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $registradas);
    }

    /**
     * Cambian si el producto de alguna elegida no es el registrado en un grupo que ya tiene consumo.
     * Un renglón pedido que ya no existe cuenta como cambio (la validación lo rechaza después).
     */
    private function cambianAlternativas(?array $pedidas, array $registradas): bool
    {
        if (empty($pedidas)) {
            return false;
        }

        $productos = RecipeItem::whereIn('id', array_values($pedidas))->pluck('product_id', 'id');

        foreach ($pedidas as $grupo => $itemId) {
            $registrados = $registradas[$this->claveGrupo($grupo)] ?? null;
            if ($registrados === null) {
                continue;
            }
            $producto = $productos->get($itemId);
            if ($producto === null || ! in_array((int) $producto, $registrados, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * [grupo => recipe_item_id] para el servicio: lo registrado traducido al renglón ACTUAL de la
     * receta con ese grupo y ese producto (si ya no está, queda la predeterminada), con lo pedido encima.
     */
    private function alternativasParaConsumir(array $registradas, ?array $pedidas, ?Recipe $receta): array
    {
        $alternativas = [];
        if ($receta) {
            foreach ($registradas as $grupo => $productos) {
                $item = $receta->items->first(fn (RecipeItem $i) => $this->claveGrupo($i->group_key) === $grupo
                    && (int) $i->product_id === $productos[0]);
                if ($item) {
                    $alternativas[$grupo] = (int) $item->id;
                }
            }
        }
        foreach ($pedidas ?? [] as $grupo => $itemId) {
            $alternativas[$this->claveGrupo($grupo)] = $itemId;
        }

        return $alternativas;
    }

    private function claveGrupo(?string $grupo): string
    {
        return mb_strtolower(trim((string) $grupo));
    }

    /** Pallet sin entradas: por caja + al armar; si está cerrado, también al cerrar. */
    private function consumirPalletSinEntradas(ProduccionEmpaque $p, Recipe $receta, array $alternativas): void
    {
        $consumo = $this->consumo();
        $consumo->consumirPorCaja($p, null, $receta, (int) $p->total_cajas, $alternativas);
        $consumo->consumirPorPallet($p, $receta, RecipeItem::CONSUMO_AL_ARMAR, $alternativas);
        if (! $p->is_cola) {
            $consumo->consumirPorPallet($p, $receta, RecipeItem::CONSUMO_AL_CERRAR, $alternativas);
        }
    }

    /**
     * Pallet cerrado: consume sus insumos de cierre (una vez) con la receta del pallet; cola abierta:
     * los devuelve. $registradas (por producto) y $pedidas se traducen a esa receta.
     */
    private function ajustarCierre(ProduccionEmpaque $p, array $registradas, ?array $pedidas): void
    {
        $consumo = $this->consumo();

        if ($p->is_cola) {
            $consumo->devolverMomento($p, RecipeItem::CONSUMO_AL_CERRAR);

            return;
        }

        $receta = $consumo->recetaDePallet($p);
        if ($receta) {
            $consumo->consumirPorPallet($p, $receta, RecipeItem::CONSUMO_AL_CERRAR,
                $this->alternativasParaConsumir($registradas, $pedidas, $receta));
        }
    }

    /** Cambio de planta de un pallet con entradas: devuelve todo y lo consume de nuevo en la planta nueva. */
    private function reconsumirPalletConEntradas(ProduccionEmpaque $p): void
    {
        $consumo = $this->consumo();
        $detalles = $p->detalles()->get();

        // Lo elegido se lee antes de devolver (después ya no está vigente).
        $porEntrada = $detalles->mapWithKeys(fn (ProduccionEmpaqueDetalle $d) => [$d->id => $this->alternativasRegistradas($p, $d)]);
        $delPallet = $this->alternativasRegistradas($p, null);

        $consumo->devolverTodo($p);

        foreach ($detalles as $d) {
            $receta = Recipe::with('items')->find($d->recipe_id ?? $p->recipe_id);
            if ($receta) {
                $consumo->consumirPorCaja($p, $d, $receta, (int) $d->total_cajas,
                    $this->alternativasParaConsumir($porEntrada[$d->id], null, $receta));
            }
        }

        $recetaPallet = $consumo->recetaDePallet($p);
        if ($recetaPallet) {
            $alternativas = $this->alternativasParaConsumir($delPallet, null, $recetaPallet);
            $consumo->consumirPorPallet($p, $recetaPallet, RecipeItem::CONSUMO_AL_ARMAR, $alternativas);
            if (! $p->is_cola) {
                $consumo->consumirPorPallet($p, $recetaPallet, RecipeItem::CONSUMO_AL_CERRAR, $alternativas);
            }
        }
    }

    /** Respuesta de escritura: el pallet con sus alternativas registradas y los faltantes de esta operación. */
    private function respuestaEscritura(ProduccionEmpaque $p, string $message, int $status = 200): JsonResponse
    {
        $consumo = $this->consumo();
        $p->setAttribute('alternativas', $consumo->alternativasDe($p, null));
        foreach ($p->detalles as $d) {
            $d->setAttribute('alternativas', $consumo->alternativasDe($p, $d));
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $p,
            'faltantes' => $consumo->faltantes(),
        ], $status);
    }

    /** Igual que alternativasDe() del servicio, con una consulta por bloque de pallets (listado). */
    private function exponerAlternativasListado(Collection $producciones): void
    {
        if ($producciones->isEmpty()) {
            return;
        }

        $renglones = collect();
        foreach ($producciones->pluck('id')->chunk(500) as $ids) {
            $renglones = $renglones->concat(
                ProduccionEmpaqueConsumo::whereIn('produccion_id', $ids->all())
                    ->whereNotNull('group_key')
                    ->vigentes()
                    ->orderBy('id')
                    ->get(['id', 'produccion_id', 'detalle_id', 'group_key', 'product_id'])
            );
        }
        $porDueno = $renglones->groupBy(fn (ProduccionEmpaqueConsumo $c) => $c->produccion_id.':'.($c->detalle_id ?? ''));

        $alternativas = fn (string $llave) => ($porDueno->get($llave) ?? collect())
            ->unique('group_key')
            ->map(fn (ProduccionEmpaqueConsumo $c) => ['grupo' => $c->group_key, 'product_id' => (int) $c->product_id])
            ->values()
            ->all();

        foreach ($producciones as $p) {
            $p->setAttribute('alternativas', $alternativas("{$p->id}:"));
            foreach ($p->detalles as $d) {
                $d->setAttribute('alternativas', $alternativas("{$p->id}:{$d->id}"));
            }
        }
    }

    private function rechazoEmbarcado(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'No se puede eliminar un pallet que ya está embarcado',
        ], 422);
    }

    private function hydrateResolvedLoteProductoTerminado(ProduccionEmpaque $produccion): void
    {
        if (! $produccion->is_mixto) {
            return;
        }

        $resolvedLoteProductoTerminado = $this->resolveLoteProductoTerminadoFromMixture($produccion);
        if (filled($resolvedLoteProductoTerminado)) {
            $produccion->setAttribute('lote_producto_terminado', $resolvedLoteProductoTerminado);
        }
    }

    private function hydrateDetalleLoteProductoTerminado(ProduccionEmpaque $produccion): void
    {
        if (! $produccion->is_mixto || $produccion->detalles->isEmpty()) {
            return;
        }

        $sourceColaIds = $produccion->detalles
            ->map(fn (ProduccionEmpaqueDetalle $detalle) => $this->extractMixSourceColaId($detalle->observaciones))
            ->filter()
            ->unique()
            ->values();

        if ($sourceColaIds->isEmpty()) {
            return;
        }

        $lotesByColaId = ProduccionEmpaque::withTrashed()
            ->whereIn('id', $sourceColaIds)
            ->get(['id', 'lote_producto_terminado'])
            ->mapWithKeys(fn (ProduccionEmpaque $cola) => [
                (int) $cola->id => trim((string) ($cola->lote_producto_terminado ?? '')),
            ]);

        $produccion->detalles->each(function (ProduccionEmpaqueDetalle $detalle) use ($lotesByColaId) {
            if (filled($detalle->lote_producto_terminado)) {
                return;
            }

            $sourceColaId = $this->extractMixSourceColaId($detalle->observaciones);
            if (! $sourceColaId) {
                return;
            }

            $resolvedLote = $lotesByColaId->get($sourceColaId, '');
            if (filled($resolvedLote)) {
                $detalle->setAttribute('lote_producto_terminado', $resolvedLote);
            }
        });
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

    private function extractSourceColasFromMixture(?string $observaciones): array
    {
        if (! $observaciones) {
            return [];
        }

        // Formato JSON (nuevo): {"mixteo_colas":[{"id":1,"numero_pallet":"...", ...}]}
        $decoded = json_decode($observaciones, true);
        if (is_array($decoded) && isset($decoded['mixteo_colas']) && is_array($decoded['mixteo_colas'])) {
            return $decoded['mixteo_colas'];
        }

        // Formato texto legacy: "mixteo de colas: COLA-001, COLA-002"
        if (! str_contains($observaciones, 'mixteo de colas:')) {
            return [];
        }

        $parts = explode('mixteo de colas:', $observaciones, 2);
        $raw = trim($parts[1] ?? '');

        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($item) => ['numero_pallet' => trim($item)])
            ->filter(fn ($item) => $item['numero_pallet'] !== '')
            ->values()
            ->all();
    }

    /**
     * Construye el string de observaciones para un pallet mixto, guardando las colas origen en JSON.
     *
     * @param array  $sourceColas       Array de colas con id, numero_pallet, cajas, etc.
     * @param string $calibreBreakdown  Resumen de cajas por calibre.
     */
    private function buildMixtureStructure(array $sourceColas, string $calibreBreakdown): string
    {
        return (string) json_encode([
            'mixteo_colas' => array_values($sourceColas),
            'calibre_breakdown' => $calibreBreakdown,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Construye un resumen legible de cajas por calibre a partir de un array de detalles.
     *
     * @param array $detalles  Array de arrays con claves 'calibre' y 'total_cajas'.
     */
    private function buildCalibreBreakdownFromDetalles(array $detalles): string
    {
        return collect($detalles)
            ->groupBy(fn (array $d) => trim((string) ($d['calibre'] ?? '')) ?: 'Sin calibre')
            ->map(fn ($items, string $calibre) => $calibre . ': ' . $items->sum('total_cajas') . ' cajas')
            ->values()
            ->implode(', ');
    }

    /**
     * Elimina el tag MIXSRC:{id} de las observaciones de un detalle al revertir el mixteo.
     */
    private function stripMixSourceTag(?string $observaciones): ?string
    {
        if (! $observaciones) {
            return $observaciones;
        }

        $cleaned = trim((string) preg_replace('/\s*MIXSRC:\d+/', '', $observaciones));

        return $cleaned !== '' ? $cleaned : null;
    }

    /**
     * Extrae los números de pallet de las colas origen guardadas en observaciones de un pallet mixto.
     * Soporta formato JSON (nuevo) y texto legacy "mixteo de colas: ...".
     */
    private function extractMixSourceNumeroPallets(?string $observaciones): array
    {
        if (! $observaciones) {
            return [];
        }

        // Formato JSON (nuevo)
        $decoded = json_decode($observaciones, true);
        if (is_array($decoded) && isset($decoded['mixteo_colas'])) {
            return collect($decoded['mixteo_colas'])
                ->pluck('numero_pallet')
                ->filter()
                ->values()
                ->all();
        }

        // Formato texto legacy: "mixteo de colas: COLA-001, COLA-002"
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

    private function resolveLoteProductoTerminadoFromMixture(ProduccionEmpaque $produccion): ?string
    {
        $directLote = trim((string) ($produccion->lote_producto_terminado ?? ''));
        if ($directLote !== '') {
            return $directLote;
        }

        $sourceColas = collect($this->extractSourceColasFromMixture($produccion->observaciones));

        if ($sourceColas->isEmpty()) {
            $sourceColaIds = $produccion->detalles
                ->map(fn (ProduccionEmpaqueDetalle $detalle) => $this->extractMixSourceColaId($detalle->observaciones))
                ->filter()
                ->unique()
                ->values();

            if ($sourceColaIds->isNotEmpty()) {
                $sourceColas = ProduccionEmpaque::withTrashed()
                    ->whereIn('id', $sourceColaIds)
                    ->get(['id', 'numero_pallet', 'lote_producto_terminado'])
                    ->map(fn (ProduccionEmpaque $cola) => [
                        'id' => (int) $cola->id,
                        'numero_pallet' => $cola->numero_pallet,
                        'lote_producto_terminado' => $cola->lote_producto_terminado,
                    ]);
            }
        }

        $sourceLotes = $sourceColas
            ->pluck('lote_producto_terminado')
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values();

        if ($sourceLotes->count() === 1) {
            return $sourceLotes->first();
        }

        $detalleLote = $produccion->detalles
            ->map(fn (ProduccionEmpaqueDetalle $detalle) => trim((string) (
                $detalle->loteProductoTerminado
                ?? $detalle->lote_producto_terminado
                ?? ''
            )))
            ->first(fn ($value) => $value !== '');

        return $detalleLote !== '' ? $detalleLote : null;
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

        if (($produccion->status ?? null) === 'embarcado') {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar un pallet que ya está embarcado',
            ], 422);
        }

        $validated = $request->validate([
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'sometimes|date',
            'total_cajas' => 'sometimes|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'turno' => 'nullable|string|max:50',
            'tipo_empaque' => 'nullable|string|max:100',
            'marca' => 'nullable|string|max:150',
            'presentacion' => 'nullable|string|max:150',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'observaciones' => 'nullable|string',
            'alternativas' => 'nullable|array',
            'alternativas.*.grupo' => 'required|string|max:50',
            'alternativas.*.recipe_item_id' => 'required|integer',
        ]);

        $pedidas = $this->alternativasPedidas($validated);
        unset($validated['alternativas']);

        $congelado = $produccion->congelado();
        // Receta efectiva: la de la entrada o, si no tiene, la del pallet.
        $recetaAntes = (int) ($detalle->recipe_id ?? $produccion->recipe_id ?? 0);
        $recetaDespues = array_key_exists('recipe_id', $validated)
            ? (int) ($validated['recipe_id'] ?? $produccion->recipe_id ?? 0)
            : $recetaAntes;
        $cambiaReceta = $recetaDespues !== $recetaAntes;
        $cambianCajas = array_key_exists('total_cajas', $validated)
            && (int) $validated['total_cajas'] !== (int) $detalle->total_cajas;
        $registradas = $this->alternativasRegistradas($produccion, $detalle);
        $cambianAlternativas = $this->cambianAlternativas($pedidas, $registradas);
        $mueveInventario = ! $congelado && ($cambiaReceta || $cambianCajas || $cambianAlternativas);

        $receta = null;
        if ($cambiaReceta) {
            $receta = $this->recetaValida($recetaDespues);
        } elseif ($mueveInventario && $recetaDespues) {
            $receta = Recipe::with('items')->find($recetaDespues);
        }
        if ($mueveInventario && $receta && ! empty($pedidas)) {
            $this->validarAlternativas($receta, $pedidas);
        }

        return DB::transaction(function () use ($produccion, $detalle, $validated, $mueveInventario, $receta, $cambiaReceta, $registradas, $pedidas) {
            $detalle->update($validated);

            // Recalcular totales y marca del pallet padre si es necesario
            $produccion->loadMissing('detalles');

            if ($produccion->detalles->isNotEmpty()) {
                $produccion->update($this->buildAggregateFieldsFromDetalles($produccion));
            }

            // La entrada devuelve exactamente lo suyo y consume con sus datos nuevos.
            if ($mueveInventario) {
                $alternativas = $cambiaReceta ? ($pedidas ?? []) : $this->alternativasParaConsumir($registradas, $pedidas, $receta);
                $this->consumo()->devolverEntrada($produccion, $detalle);
                if ($receta) {
                    $this->consumo()->consumirPorCaja($produccion, $detalle, $receta, (int) $detalle->total_cajas, $alternativas);
                }
            }

            $produccion->load($this->eagerLoad);

            return $this->respuestaEscritura($produccion, 'Detalle actualizado');
        });
    }

    /**
     * Añade el tag MIXSRC:{colaId} a las observaciones de un detalle para trazabilidad del mixteo.
     */
    private function appendMixSourceTag(?string $observaciones, int $colaId): string
    {
        $base = trim((string) $observaciones);
        $tag = "MIXSRC:{$colaId}";

        // No duplicar el tag si ya existe
        if (str_contains($base, $tag)) {
            return $base;
        }

        return $base !== '' ? "{$base} {$tag}" : $tag;
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

        if (($produccion->status ?? null) === 'embarcado') {
            return $this->rechazoEmbarcado();
        }

        return DB::transaction(function () use ($produccion, $detalle) {
            $produccion->loadMissing('detalles');

            // La entrada devuelve exactamente lo suyo (un pallet congelado no tiene registro).
            $this->consumo()->devolverEntrada($produccion, $detalle);

            $sumDetallesCajas = (int) $produccion->detalles->sum('total_cajas');
            $sumDetallesPeso = (float) $produccion->detalles->sum('peso_neto_kg');

            // El registro base del pallet es lo que no está representado en detalles.
            $baseCajas = max(((int) $produccion->total_cajas) - $sumDetallesCajas, 0);
            $basePeso = max(((float) $produccion->peso_neto_kg) - $sumDetallesPeso, 0);

            $detalle->delete();

            $produccion->unsetRelation('detalles');
            $produccion->load('detalles');

            $remainingCajas = (int) $produccion->detalles->sum('total_cajas');
            $remainingPeso = (float) $produccion->detalles->sum('peso_neto_kg');

            $produccion->update([
                'total_cajas' => $baseCajas + $remainingCajas,
                'peso_neto_kg' => round($basePeso + $remainingPeso, 2),
                'marca' => $this->buildAggregateFieldsFromDetalles($produccion)['marca'],
            ]);

            $produccion->load($this->eagerLoad);

            return $this->respuestaEscritura($produccion, 'Registro de cola eliminado');
        });
    }
}

