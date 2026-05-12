<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\EmbarqueEmpaqueDetalle;
use App\Models\Entity;
use App\Models\PreEmbarqueEmpaqueDetalle;
use App\Models\ProcesoEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use App\Models\SalidaRezagaEmpaqueDetalle;
use App\Models\Submodule;
use App\Models\UserSubmodulePermission;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcesoEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'recepcion:id,folio_recepcion,fecha_recepcion,cantidad_recibida,peso_recibido_kg,salida_campo_id,tipo_carga_id',
        'recepcion.salidaCampo:id,folio_salida,variedad_id',
        'recepcion.salidaCampo.variedad:id,nombre',
        'recepcion.tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
        'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
        'productor:id,nombre,apellido',
        'lote:id,nombre,numero_lote,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'etapa:id,nombre,variedad_id',
        'etapa.variedad:id,nombre',
        'creador:id,name',
    ];

    private array $recepcionEagerLoad = [
        'entity:id,name,code',
        'salidaCampo:id,folio_salida,variedad_id',
        'salidaCampo.variedad:id,nombre',
        'productor:id,nombre,apellido',
        'lote:id,nombre,numero_lote,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'etapa:id,nombre,variedad_id',
        'etapa.variedad:id,nombre',
        'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
    ];

    /**
     * GET /proceso — Returns procesos en_proceso + procesado
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProcesoEmpaque::with([...$this->eagerLoad, 'producciones', 'rezagas.ventaDetalles:id,rezaga_id,peso_kg']);

        $asOf = null;
        if ($request->filled('as_of_date')) {
            $asOf = Carbon::parse($request->input('as_of_date'), 'America/Mexico_City')->endOfDay();
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

        $procesos = $query->orderByDesc('fecha_entrada')->orderByDesc('id')->get();

        if ($asOf && $procesos->isNotEmpty()) {
            $ids = $procesos->pluck('id')->filter()->values();
            $logsByModel = ActivityLog::query()
                ->where('model', 'ProcesoEmpaque')
                ->whereIn('model_id', $ids)
                ->where('created_at', '<=', $asOf)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['model_id', 'action', 'new_values'])
                ->groupBy('model_id');

            $snapshotFields = [
                'status', 'cantidad_disponible', 'peso_disponible_kg', 'modo_kilos',
                'fecha_entrada', 'fecha_proceso', 'fecha_lavado', 'fecha_hidrotermico',
                'fecha_enfriamiento', 'fecha_listo_produccion',
            ];

            $procesos->each(function (ProcesoEmpaque $proceso) use ($logsByModel, $snapshotFields) {
                $logs = $logsByModel->get($proceso->id, collect());
                if ($logs->isEmpty()) {
                    return;
                }

                $state = [];
                foreach ($logs as $log) {
                    if (!is_array($log->new_values)) {
                        continue;
                    }
                    foreach ($snapshotFields as $field) {
                        if (array_key_exists($field, $log->new_values)) {
                            $state[$field] = $log->new_values[$field];
                        }
                    }
                }

                foreach ($state as $field => $value) {
                    $proceso->setAttribute($field, $value);
                }
            });
        }

        // Anotar cantidad_historica_kg en cada rezaga (= cantidad_kg + vendido en salidas)
        $procesos->each(function ($proceso) {
            $proceso->rezagas->each(function ($rezaga) {
                $vendido = (float) $rezaga->ventaDetalles->sum('peso_kg');
                $rezaga->setAttribute('cantidad_historica_kg', round((float) $rezaga->cantidad_kg + $vendido, 2));
            });
        });

        return response()->json(['success' => true, 'data' => $procesos]);
    }

    /**
     * GET /proceso/en-proceso — Procesos en procesando (for select dropdowns in produccion/rezaga/calidad)
     */
    public function enProceso(Request $request): JsonResponse
    {
        $query = ProcesoEmpaque::with([
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote,zona_cultivo_id',
            'lote.zonaCultivo:id,nombre',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre',
            'recepcion:id,salida_campo_id,tipo_carga_id',
            'recepcion.salidaCampo:id,variedad_id',
            'recepcion.salidaCampo.variedad:id,nombre',
            'recepcion.tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
            'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
        ])->where('status', 'en_proceso');

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $procesos = $query->orderByDesc('fecha_proceso')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $procesos]);
    }

    /**
     * GET /proceso/piso — Piso = items disponibles para procesar
     * Si la entidad usa_hidrotermico → muestra ProcesoEmpaque con status listo_produccion (vienen de lavado)
     * Si no → muestra recepciones con cantidad disponible (flujo directo)
     */
    public function piso(Request $request): JsonResponse
    {
        // Determine if entity uses hydrothermal
        $usaHidrotermico = false;
        if ($request->filled('entity_id')) {
            $entity = Entity::find($request->entity_id);
            $usaHidrotermico = $entity && $entity->usa_hidrotermico;
        }

        if ($usaHidrotermico) {
            return $this->pisoFromLavado($request);
        }

        return $this->pisoFromRecepciones($request);
    }

    /**
     * Piso directo: recepciones con cantidad disponible (sin lavado)
     */
    private function pisoFromRecepciones(Request $request): JsonResponse
    {
        $query = RecepcionEmpaque::with($this->recepcionEagerLoad)
            ->where('status', '!=', 'rechazada');

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->byEntity($request->entity_id);
        }

        $recepciones = $query->orderByDesc('fecha_recepcion')->orderByDesc('id')->get();

        $piso = [];
        foreach ($recepciones as $rec) {
            // Sum quantities actively being processed
            $enProceso = ProcesoEmpaque::where('recepcion_id', $rec->id)
                ->whereIn('status', ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso'])
                ->sum('cantidad_entrada');

            // For procesado folios: sum what was actually used (cantidad_entrada - cantidad_disponible)
            // This ensures remainder stays available in piso
            $procesados = ProcesoEmpaque::where('recepcion_id', $rec->id)
                ->where('status', 'procesado')
                ->get();

            foreach ($procesados as $proc) {
                $usado = $proc->cantidad_entrada - $proc->cantidad_disponible;
                $enProceso += $usado;
            }

            $disponible = $rec->cantidad_recibida - $enProceso;

            if ($disponible > 0) {
                $pesoUnitario = $rec->tipoCarga ? (float) $rec->tipoCarga->peso_estimado_kg : 0;
                $variedad = $rec->etapa?->variedad ?? $rec->salidaCampo?->variedad;

                $piso[] = [
                    'recepcion_id' => $rec->id,
                    'folio' => $rec->folio_recepcion,
                    'fecha_recepcion' => $rec->fecha_recepcion,
                    'productor' => $rec->productor,
                    'lote' => $rec->lote,
                    'etapa' => $rec->etapa,
                    'salida_campo' => $rec->salidaCampo,
                    'variedad' => $variedad,
                    'variedad_nombre' => $variedad?->nombre,
                    'tipo_carga' => $rec->tipoCarga,
                    'entity' => $rec->entity,
                    'cantidad_recibida' => $rec->cantidad_recibida,
                    'cantidad_en_proceso' => (int) $enProceso,
                    'cantidad_disponible' => $disponible,
                    'peso_disponible_kg' => round($disponible * $pesoUnitario, 2),
                    'source' => 'recepcion',
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $piso]);
    }

    /**
     * Piso desde lavado: ProcesoEmpaque con status listo_produccion
     */
    private function pisoFromLavado(Request $request): JsonResponse
    {
        // Include both 'listo_produccion' (fresh from lavado) AND 'procesado' with remainder > 0
        $query = ProcesoEmpaque::with($this->eagerLoad)
            ->where(function ($q) {
                $q->where('status', 'listo_produccion')
                  ->orWhere(function ($sub) {
                      $sub->where('status', 'procesado')
                          ->where('cantidad_disponible', '>', 0);
                  });
            });

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $procesos = $query->orderByDesc('fecha_listo_produccion')->orderByDesc('id')->get();

        $piso = [];
        foreach ($procesos as $p) {
            $pesoUnitario = $p->tipoCarga ? (float) $p->tipoCarga->peso_estimado_kg : 0;
            $variedad = $p->etapa?->variedad ?? $p->recepcion?->salidaCampo?->variedad;
            $modoKilos = (bool) $p->modo_kilos;
            // Use cantidad_disponible (may be remainder if status=procesado, or full if listo_produccion)
            $cantidadDisponible = (int) $p->cantidad_disponible;
            $pesoDisponibleKg = $modoKilos
                ? (float) $p->peso_disponible_kg
                : round($cantidadDisponible * $pesoUnitario, 2);

            $piso[] = [
                'proceso_id' => $p->id,
                'recepcion_id' => $p->recepcion_id,
                'folio' => $p->folio_proceso,
                'fecha_recepcion' => $p->fecha_entrada,
                'productor' => $p->productor,
                'lote' => $p->lote,
                'etapa' => $p->etapa,
                'recepcion' => $p->recepcion,
                'variedad' => $variedad,
                'variedad_nombre' => $variedad?->nombre,
                'tipo_carga' => $p->tipoCarga,
                'entity' => $p->entity,
                'cantidad_recibida' => $p->cantidad_entrada,
                'cantidad_en_proceso' => 0,
                'cantidad_disponible' => $cantidadDisponible,
                'peso_disponible_kg' => $pesoDisponibleKg,
                'modo_kilos' => $modoKilos,
                'source' => $p->status === 'procesado' ? 'lavado_remainder' : 'lavado',
            ];
        }

        return response()->json(['success' => true, 'data' => $piso]);
    }

    /**
     * POST /proceso/mover-a-proceso — Move to procesando
     * Supports two flows:
     * - proceso_id: promote from listo_produccion to en_proceso (hydrothermal flow)
     * - recepcion_id + cantidad: create new proceso from recepcion (direct flow)
     */
    public function store(Request $request): JsonResponse
    {
        // Flow from lavado: promote existing proceso
        if ($request->filled('proceso_id')) {
            return $this->promoverFromLavado($request);
        }

        // Flow directo: create new proceso from recepcion
        return $this->crearFromRecepcion($request);
    }

    /**
     * Promote a listo_produccion or procesado-with-remainder proceso to en_proceso
     */
    private function promoverFromLavado(Request $request): JsonResponse
    {
        $request->validate([
            'proceso_id' => 'required|exists:proceso_empaque,id',
        ]);

        $proceso = ProcesoEmpaque::findOrFail($request->proceso_id);

        // Allow both fresh-from-lavado and reopened-with-remainder flows
        $isListoProduccion = $proceso->status === 'listo_produccion';
        $isProcesadoConRemainder = $proceso->status === 'procesado'
            && (int) $proceso->cantidad_disponible > 0;

        if (! $isListoProduccion && ! $isProcesadoConRemainder) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden mover folios listos para producción o procesados con remanente en piso',
            ], 422);
        }

        $proceso->update([
            'status' => 'en_proceso',
            'fecha_proceso' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso->load($this->eagerLoad);

        $unidadesParaProcesar = $isProcesadoConRemainder
            ? (int) $proceso->cantidad_disponible
            : (int) $proceso->cantidad_entrada;

        return response()->json([
            'success' => true,
            'message' => "Folio movido a procesando ({$unidadesParaProcesar} cajas)",
            'data' => $proceso,
        ], 200);
    }

    /**
     * Create new proceso from recepcion (direct flow)
     */
    private function crearFromRecepcion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'recepcion_id' => 'required|exists:recepciones_empaque,id',
            'cantidad' => 'required|integer|min:1',
        ]);

        $recepcion = RecepcionEmpaque::with('tipoCarga')->findOrFail($validated['recepcion_id']);

        // Calculate available with the same rule used by pisoFromRecepciones.
        // Activos: se resta cantidad_entrada completa.
        // Procesados: solo se resta lo realmente consumido (entrada - disponible).
        $enProcesoActivo = ProcesoEmpaque::where('recepcion_id', $recepcion->id)
            ->whereIn('status', ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso'])
            ->sum('cantidad_entrada');

        $procesados = ProcesoEmpaque::where('recepcion_id', $recepcion->id)
            ->where('status', 'procesado')
            ->get(['cantidad_entrada', 'cantidad_disponible']);

        $consumidoProcesado = 0;
        foreach ($procesados as $proc) {
            $consumidoProcesado += max(0, ((int) $proc->cantidad_entrada - (int) $proc->cantidad_disponible));
        }

        $disponible = (int) $recepcion->cantidad_recibida - (int) $enProcesoActivo - (int) $consumidoProcesado;

        if ($validated['cantidad'] > $disponible) {
            return response()->json([
                'status' => 'error',
                'message' => "Cantidad solicitada ({$validated['cantidad']}) excede disponible en piso ($disponible)",
            ], 422);
        }

        $pesoUnitario = $recepcion->tipoCarga ? (float) $recepcion->tipoCarga->peso_estimado_kg : 0;
        $cantidad = $validated['cantidad'];

        // Use recepcion folio as proceso folio
        $folioProceso = $recepcion->folio_recepcion;

        $proceso = ProcesoEmpaque::create([
            'temporada_id' => $validated['temporada_id'],
            'entity_id' => $recepcion->entity_id,
            'recepcion_id' => $recepcion->id,
            'folio_proceso' => $folioProceso,
            'tipo_carga_id' => $recepcion->tipo_carga_id,
            'productor_id' => $recepcion->productor_id,
            'lote_id' => $recepcion->lote_id,
            'etapa_id' => $recepcion->etapa_id,
            'cantidad_entrada' => $cantidad,
            'peso_entrada_kg' => $cantidad * $pesoUnitario,
            'cantidad_disponible' => $cantidad,
            'peso_disponible_kg' => $cantidad * $pesoUnitario,
            'fecha_entrada' => now('America/Mexico_City')->toDateString(),
            'fecha_proceso' => now('America/Mexico_City')->toDateString(),
            'status' => 'en_proceso',
            'created_by' => $request->user()->id,
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => "Folio movido a procesando ($cantidad uds)",
            'data' => $proceso,
        ], 201);
    }

    public function show(ProcesoEmpaque $proceso): JsonResponse
    {
        $proceso->load($this->eagerLoad);
        return response()->json(['success' => true, 'data' => $proceso]);
    }

    public function update(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Use acciones específicas'], 405);
    }

    /**
     * DELETE — Remove proceso entry (devolver a piso)
     * If the proceso came from lavado (has fecha_lavado), revert to listo_produccion
     * If direct flow, forceDelete the record
     */
    public function destroy(ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'en_proceso') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden devolver folios en proceso',
            ], 422);
        }

        // If came from lavado pipeline, revert to listo_produccion
        if ($proceso->fecha_lavado) {
            $proceso->update([
                'status' => 'listo_produccion',
                'fecha_proceso' => null,
            ]);
            return response()->json(['success' => true, 'message' => 'Folio devuelto a lavado (listo para producción)']);
        }

        $proceso->forceDelete();

        return response()->json(['success' => true, 'message' => 'Folio devuelto a piso']);
    }

    /**
     * DELETE /proceso/{proceso}/eliminar-consumido
     * Elimina un folio consumido (procesado). Requiere permiso delete_procesado.
     */
    public function eliminarConsumido(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'procesado') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden eliminar folios consumidos (procesado)',
            ], 422);
        }

        // Verificar permiso delete_procesado
        $user = $request->user();
        $submodule = Submodule::where('slug', 'proceso')->first();

        if (!$submodule) {
            return response()->json(['status' => 'error', 'message' => 'Submódulo no encontrado'], 500);
        }

        $hasPermission = UserSubmodulePermission::where('user_id', $user->id)
            ->where('submodule_id', $submodule->id)
            ->whereHas('permissionType', fn($q) => $q->where('slug', 'delete_procesado'))
            ->where('is_granted', true)
            ->exists();

        if (!$hasPermission) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes permiso para eliminar folios consumidos',
            ], 403);
        }

        // Verificar dependencias activas y soft-deleted
        $produccionesActivas = $proceso->producciones()->select('id', 'folio_produccion')->get();
        $produccionesEliminadas = $proceso->producciones()->onlyTrashed()->select('id', 'folio_produccion')->get();
        $rezagasActivas = $proceso->rezagas()->select('id', 'folio_rezaga')->get();
        $rezagasEliminadas = $proceso->rezagas()->onlyTrashed()->select('id', 'folio_rezaga')->get();

        $embarqueDetallesSobreProduccionesEliminadas = collect();
        if ($produccionesEliminadas->isNotEmpty()) {
            $embarqueDetallesSobreProduccionesEliminadas = EmbarqueEmpaqueDetalle::query()
                ->whereIn('produccion_id', $produccionesEliminadas->pluck('id'))
                ->select('id', 'embarque_id', 'produccion_id')
                ->get();
        }

        $preEmbarqueDetallesSobreProduccionesEliminadas = collect();
        if ($produccionesEliminadas->isNotEmpty()) {
            $preEmbarqueDetallesSobreProduccionesEliminadas = PreEmbarqueEmpaqueDetalle::query()
                ->whereIn('produccion_id', $produccionesEliminadas->pluck('id'))
                ->select('id', 'pre_embarque_id', 'produccion_id')
                ->get();
        }

        $ventaDetallesSobreRezagasEliminadas = collect();
        if ($rezagasEliminadas->isNotEmpty()) {
            $ventaDetallesSobreRezagasEliminadas = SalidaRezagaEmpaqueDetalle::query()
                ->whereIn('rezaga_id', $rezagasEliminadas->pluck('id'))
                ->select('id', 'venta_rezaga_id', 'rezaga_id')
                ->get();
        }

        if (
            $produccionesActivas->isNotEmpty() ||
            $rezagasActivas->isNotEmpty() ||
            $embarqueDetallesSobreProduccionesEliminadas->isNotEmpty() ||
            $ventaDetallesSobreRezagasEliminadas->isNotEmpty()
        ) {
            $deps = [];
            if ($produccionesActivas->isNotEmpty()) {
                $deps[] = $produccionesActivas->count() . ' producción(es)';
            }
            if ($rezagasActivas->isNotEmpty()) {
                $deps[] = $rezagasActivas->count() . ' rezaga(s)';
            }
            if ($embarqueDetallesSobreProduccionesEliminadas->isNotEmpty()) {
                $deps[] = $embarqueDetallesSobreProduccionesEliminadas->count() . ' detalle(s) de embarque sobre producciones eliminadas';
            }
            if ($ventaDetallesSobreRezagasEliminadas->isNotEmpty()) {
                $deps[] = $ventaDetallesSobreRezagasEliminadas->count() . ' detalle(s) de venta sobre rezagas eliminadas';
            }

            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar: el folio tiene ' . implode(' y ', $deps) . ' asociadas',
                'details' => [
                    'producciones_activas' => $produccionesActivas->map(fn($p) => [
                        'id' => $p->id,
                        'folio' => $p->folio_produccion,
                    ])->values(),
                    'producciones_eliminadas' => $produccionesEliminadas->map(fn($p) => [
                        'id' => $p->id,
                        'folio' => $p->folio_produccion,
                    ])->values(),
                    'rezagas_activas' => $rezagasActivas->map(fn($r) => [
                        'id' => $r->id,
                        'folio' => $r->folio_rezaga,
                    ])->values(),
                    'rezagas_eliminadas' => $rezagasEliminadas->map(fn($r) => [
                        'id' => $r->id,
                        'folio' => $r->folio_rezaga,
                    ])->values(),
                    'embarque_detalles_sobre_producciones_eliminadas' => $embarqueDetallesSobreProduccionesEliminadas->map(fn($d) => [
                        'id' => $d->id,
                        'embarque_id' => $d->embarque_id,
                        'produccion_id' => $d->produccion_id,
                    ])->values(),
                    'pre_embarque_detalles_sobre_producciones_eliminadas' => $preEmbarqueDetallesSobreProduccionesEliminadas->map(fn($d) => [
                        'id' => $d->id,
                        'pre_embarque_id' => $d->pre_embarque_id,
                        'produccion_id' => $d->produccion_id,
                    ])->values(),
                    'venta_detalles_sobre_rezagas_eliminadas' => $ventaDetallesSobreRezagasEliminadas->map(fn($d) => [
                        'id' => $d->id,
                        'venta_rezaga_id' => $d->venta_rezaga_id,
                        'rezaga_id' => $d->rezaga_id,
                    ])->values(),
                ],
            ], 422);
        }

        $folio = $proceso->folio_proceso;

        try {
            DB::transaction(function () use ($proceso, $rezagasEliminadas, $preEmbarqueDetallesSobreProduccionesEliminadas) {
                // Si existen rezagas soft-deleted, purgarlas para liberar FK antes de eliminar el proceso
                if ($rezagasEliminadas->isNotEmpty()) {
                    RezagaEmpaque::onlyTrashed()
                        ->where('proceso_id', $proceso->id)
                        ->forceDelete();
                }

                // Limpiar referencias de pre-embarque hacia producciones soft-deleted
                if ($preEmbarqueDetallesSobreProduccionesEliminadas->isNotEmpty()) {
                    PreEmbarqueEmpaqueDetalle::query()
                        ->whereIn('id', $preEmbarqueDetallesSobreProduccionesEliminadas->pluck('id'))
                        ->delete();
                }

                // Purgar producciones soft-deleted para liberar FK antes de eliminar el proceso
                $proceso->producciones()
                    ->onlyTrashed()
                    ->get()
                    ->each
                    ->forceDelete();

                $proceso->forceDelete();
            });
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo eliminar el folio consumido por dependencias asociadas',
                'details' => [
                    'exception' => class_basename($e),
                    'error' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Folio consumido {$folio} eliminado correctamente",
        ]);
    }

    /**
     * POST /proceso/{id}/cerrar
     * cuarto_frio + fresco. If sum < cantidad → remainder stays in piso automatically
     * Accepts optional production rezaga
     */
    public function cerrar(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'en_proceso') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden cerrar folios en proceso',
            ], 422);
        }

        $validated = $request->validate([
            'cantidad_cuarto_frio' => 'required|integer|min:0',
            'cantidad_fresco' => 'required|integer|min:0',
            'rezaga_kg' => 'nullable|numeric|min:0.01',
            'subtipo_rezaga' => 'required_with:rezaga_kg|in:hoja,producto',
            'rezaga_observaciones' => 'nullable|string|max:1000',
        ]);

        $cuartoFrio = $validated['cantidad_cuarto_frio'];
        $fresco = $validated['cantidad_fresco'];
        $totalProcesado = $cuartoFrio + $fresco;

        if ($totalProcesado > $proceso->cantidad_disponible) {
            return response()->json([
                'status' => 'error',
                'message' => "La suma ($totalProcesado) excede las unidades disponibles ({$proceso->cantidad_disponible})",
            ], 422);
        }

        if ($totalProcesado === 0) {
            // Permite destrabar folios reabiertos incorrectamente con disponible = 0.
            if ((int) $proceso->cantidad_disponible === 0) {
                $proceso->update([
                    'status' => 'procesado',
                ]);

                $proceso->load($this->eagerLoad);

                return response()->json([
                    'success' => true,
                    'message' => 'Folio cerrado sin cambios (sin remanente disponible)',
                    'data' => $proceso,
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Debe ingresar al menos 1 unidad procesada',
            ], 422);
        }

        $remainder = $proceso->cantidad_disponible - $totalProcesado;

        // Register production rezaga if provided
        if ($request->filled('rezaga_kg')) {
            $folioRezaga = $this->generarFolioRezaga($proceso->entity_id);

            RezagaEmpaque::create([
                'temporada_id' => $proceso->temporada_id,
                'entity_id' => $proceso->entity_id,
                'proceso_id' => $proceso->id,
                'folio_rezaga' => $folioRezaga,
                'tipo_rezaga' => 'produccion',
                'subtipo_rezaga' => $validated['subtipo_rezaga'],
                'fecha' => now('America/Mexico_City')->toDateString(),
                'cantidad_kg' => $validated['rezaga_kg'],
                'motivo' => null,
                'status' => 'pendiente',
                'observaciones' => $validated['rezaga_observaciones'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        }

        // peso_entrada_kg and cantidad_entrada stay original (full folio entry).
        // Calculate the weight of the remainder proportionally for piso display.
        $pesoEntradaKgOriginal = (float) $proceso->peso_entrada_kg;
        $cantidadEntradaOriginal = (int) $proceso->cantidad_entrada;

        $pesoRemainder = 0;
        if ($cantidadEntradaOriginal > 0 && $remainder > 0) {
            $pesoUnitario = $pesoEntradaKgOriginal / $cantidadEntradaOriginal;
            $pesoRemainder = round($pesoUnitario * $remainder, 2);
        }

        // Accumulate cuarto_frio/fresco so reopen+close cycles add up
        // (e.g. first closure 540 + second closure of remaining 120 = 660 total)
        $nuevoCuartoFrio = (int) $proceso->cantidad_cuarto_frio + $cuartoFrio;
        $nuevoFresco = (int) $proceso->cantidad_fresco + $fresco;

        // Update the proceso:
        // - cantidad_entrada stays original (660)
        // - cantidad_disponible = what goes back to piso
        // - peso_disponible_kg = weight of remainder
        // - cantidad_cuarto_frio + cantidad_fresco accumulate across multiple closures
        $proceso->update([
            'cantidad_cuarto_frio' => $nuevoCuartoFrio,
            'cantidad_fresco' => $nuevoFresco,
            'cantidad_disponible' => $remainder,
            'peso_disponible_kg' => $pesoRemainder,
            'status' => 'procesado',
        ]);

        // Remainder automatically goes back to piso (recepcion's available quantity)
        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => $remainder > 0
                ? "Cerrado. $totalProcesado procesadas, $remainder devueltas a piso"
                : 'Folio cerrado completamente',
            'data' => $proceso,
        ]);
    }

    /**
     * POST /proceso/{proceso}/reabrir — Reopen a closed (procesado) folio
     */
    public function reabrir(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'procesado') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden reabrir folios con status "procesado"',
            ], 422);
        }

        $cantidadDisponible = (int) ($proceso->cantidad_disponible ?? 0);

        // Si todavía tiene remanente, solo volver a en_proceso.
        if ($cantidadDisponible > 0) {
            $proceso->update([
                'status' => 'en_proceso',
            ]);

            $proceso->load($this->eagerLoad);

            return response()->json([
                'success' => true,
                'message' => "Folio {$proceso->folio_proceso} reabierto",
                'data' => $proceso,
            ]);
        }

        // Si está consumido (remanente 0), revisar si ya tiene movimientos activos asociados.
        $produccionesActivas = $proceso->producciones()->count();
        $rezagasActivas = $proceso->rezagas()->count();

        if ($produccionesActivas > 0 || $rezagasActivas > 0) {
            // Reapertura segura: permitir volver a en_proceso sin resetear disponibilidad,
            // para evitar duplicar cantidades cuando ya existen movimientos ligados al folio.
            $proceso->update([
                'status' => 'en_proceso',
            ]);

            $proceso->load($this->eagerLoad);

            return response()->json([
                'success' => true,
                'message' => "Folio {$proceso->folio_proceso} reabierto (con movimientos asociados, disponibilidad sin cambios)",
                'data' => $proceso,
                'details' => [
                    'producciones_activas' => $produccionesActivas,
                    'rezagas_activas' => $rezagasActivas,
                ],
            ]);
        }

        // Reapertura total: restaurar disponibilidad original para permitir nueva producción.
        $cantidadEntrada = (int) ($proceso->cantidad_entrada ?? 0);
        $pesoEntradaKg = (float) ($proceso->peso_entrada_kg ?? 0);

        $proceso->update([
            'status' => 'en_proceso',
            'cantidad_disponible' => $cantidadEntrada,
            'peso_disponible_kg' => $pesoEntradaKg,
            'cantidad_cuarto_frio' => 0,
            'cantidad_fresco' => 0,
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => "Folio {$proceso->folio_proceso} reabierto con disponibilidad restaurada ({$cantidadEntrada} cajas)",
            'data' => $proceso,
        ]);
    }

    private function generarFolioRezaga(int $entityId): string
    {
        $entityPad = str_pad((string) $entityId, 2, '0', STR_PAD_LEFT);
        $prefix = "REZ-{$entityPad}-";

        // Constraint unique global: el consecutivo se calcula por entity_id.
        $lastFolio = RezagaEmpaque::withTrashed()
            ->where('entity_id', $entityId)
            ->where('folio_rezaga', 'like', "{$prefix}%")
            ->orderByDesc('folio_rezaga')
            ->value('folio_rezaga');

        $nextNum = 1;
        if ($lastFolio) {
            $nextNum = (int) str_replace($prefix, '', $lastFolio) + 1;
        }

        // Evita colisiones si hay inserciones casi simultáneas.
        for ($i = 0; $i < 5; $i++) {
            $candidate = $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
            $exists = RezagaEmpaque::withTrashed()->where('folio_rezaga', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
            $nextNum++;
        }

        return $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }
}
