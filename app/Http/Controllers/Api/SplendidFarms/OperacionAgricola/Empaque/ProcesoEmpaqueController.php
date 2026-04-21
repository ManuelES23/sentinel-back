<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\EmbarqueEmpaqueDetalle;
use App\Models\Entity;
use App\Models\PreEmbarqueEmpaqueDetalle;
use App\Models\ProcesoEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use App\Models\VentaRezagaEmpaqueDetalle;
use App\Models\Submodule;
use App\Models\TipoCarga;
use App\Models\UserSubmodulePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcesoEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'recepcion:id,folio_recepcion,fecha_recepcion,cantidad_recibida,peso_recibido_kg,salida_campo_id',
        'recepcion.salidaCampo:id,folio_salida,variedad_id',
        'recepcion.salidaCampo.variedad:id,nombre',
        'tipoCarga:id,nombre,peso_estimado_kg',
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
        'tipoCarga:id,nombre,peso_estimado_kg',
    ];

    /**
     * GET /proceso — Returns procesos en_proceso + procesado
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProcesoEmpaque::with([...$this->eagerLoad, 'producciones', 'rezagas']);

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
            'recepcion:id,salida_campo_id',
            'recepcion.salidaCampo:id,variedad_id',
            'recepcion.salidaCampo.variedad:id,nombre',
            'tipoCarga:id,nombre,peso_estimado_kg',
        ])->whereIn('status', ['en_proceso', 'listo_produccion']);

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
            $enProceso = ProcesoEmpaque::where('recepcion_id', $rec->id)
                ->whereIn('status', ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso', 'procesado'])
                ->sum('cantidad_entrada');

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
        $query = ProcesoEmpaque::with($this->eagerLoad)
            ->where('status', 'listo_produccion');

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
                'cantidad_disponible' => $p->cantidad_entrada,
                'peso_disponible_kg' => round($p->cantidad_entrada * $pesoUnitario, 2),
                'source' => 'lavado',
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
     * Promote a listo_produccion proceso to en_proceso
     */
    private function promoverFromLavado(Request $request): JsonResponse
    {
        $request->validate([
            'proceso_id' => 'required|exists:proceso_empaque,id',
        ]);

        $proceso = ProcesoEmpaque::findOrFail($request->proceso_id);

        if ($proceso->status !== 'listo_produccion') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden mover folios con status "listo para producción"',
            ], 422);
        }

        $proceso->update([
            'status' => 'en_proceso',
            'fecha_proceso' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => "Folio movido a procesando ({$proceso->cantidad_entrada} uds)",
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

        // Calculate available (including lavado pipeline)
        $enProceso = ProcesoEmpaque::where('recepcion_id', $recepcion->id)
            ->whereIn('status', ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso', 'procesado'])
            ->sum('cantidad_entrada');
        $disponible = $recepcion->cantidad_recibida - $enProceso;

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
            $ventaDetallesSobreRezagasEliminadas = VentaRezagaEmpaqueDetalle::query()
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
            return response()->json([
                'status' => 'error',
                'message' => 'Debe ingresar al menos 1 unidad procesada',
            ], 422);
        }

        $remainder = $proceso->cantidad_disponible - $totalProcesado;

        // Register production rezaga if provided
        if ($request->filled('rezaga_kg')) {
            $count = RezagaEmpaque::where('temporada_id', $proceso->temporada_id)
                ->where('entity_id', $proceso->entity_id)
                ->count() + 1;
            $entityId = str_pad($proceso->entity_id, 2, '0', STR_PAD_LEFT);
            $folioRezaga = "REZ-{$entityId}-" . str_pad($count, 4, '0', STR_PAD_LEFT);

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

        // Update the proceso: cantidad_entrada reflects what was actually processed
        $proceso->update([
            'cantidad_cuarto_frio' => $cuartoFrio,
            'cantidad_fresco' => $fresco,
            'cantidad_entrada' => $totalProcesado,
            'cantidad_disponible' => $totalProcesado,
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
}
