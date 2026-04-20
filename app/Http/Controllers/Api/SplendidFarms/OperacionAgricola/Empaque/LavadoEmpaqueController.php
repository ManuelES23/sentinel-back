<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\ProcesoEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LavadoEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'recepcion:id,folio_recepcion,fecha_recepcion,cantidad_recibida,peso_recibido_kg,salida_campo_id',
        'recepcion.salidaCampo:id,variedad_id',
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
        'salidaCampo:id,variedad_id',
        'salidaCampo.variedad:id,nombre',
        'productor:id,nombre,apellido',
        'lote:id,nombre,numero_lote,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'etapa:id,nombre,variedad_id',
        'etapa.variedad:id,nombre',
        'tipoCarga:id,nombre,peso_estimado_kg',
    ];

    /**
     * GET /lavado/pendientes — Recepciones con cantidad disponible para lavar
     */
    public function pendientes(Request $request): JsonResponse
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

        // Lavado pipeline states that consume from piso
        $lavadoStates = ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso', 'procesado'];

        $pendientes = [];
        foreach ($recepciones as $rec) {
            $usadas = ProcesoEmpaque::where('recepcion_id', $rec->id)
                ->whereIn('status', $lavadoStates)
                ->sum('cantidad_entrada');

            $disponible = $rec->cantidad_recibida - $usadas;

            if ($disponible > 0) {
                $pesoUnitario = $rec->tipoCarga ? (float) $rec->tipoCarga->peso_estimado_kg : 0;

                $pendientes[] = [
                    'recepcion_id' => $rec->id,
                    'folio' => $rec->folio_recepcion,
                    'fecha_recepcion' => $rec->fecha_recepcion,
                    'productor' => $rec->productor,
                    'lote' => $rec->lote,
                    'etapa' => $rec->etapa,
                    'tipo_carga' => $rec->tipoCarga,
                    'entity' => $rec->entity,
                    'cantidad_recibida' => $rec->cantidad_recibida,
                    'cantidad_usada' => (int) $usadas,
                    'cantidad_disponible' => $disponible,
                    'peso_disponible_kg' => round($disponible * $pesoUnitario, 2),
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $pendientes]);
    }

    /**
     * POST /lavado/mover-a-lavado — Create proceso with status=lavando
     */
    public function moverALavado(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'recepcion_id' => 'required|exists:recepciones_empaque,id',
            'cantidad' => 'required|integer|min:1',
        ]);

        $recepcion = RecepcionEmpaque::with('tipoCarga')->findOrFail($validated['recepcion_id']);

        $lavadoStates = ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso', 'procesado'];
        $usadas = ProcesoEmpaque::where('recepcion_id', $recepcion->id)
            ->whereIn('status', $lavadoStates)
            ->sum('cantidad_entrada');
        $disponible = $recepcion->cantidad_recibida - $usadas;

        if ($validated['cantidad'] > $disponible) {
            return response()->json([
                'status' => 'error',
                'message' => "Cantidad solicitada ({$validated['cantidad']}) excede disponible ($disponible)",
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
            'fecha_lavado' => now('America/Mexico_City')->toDateString(),
            'status' => 'lavando',
            'created_by' => $request->user()->id,
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => "Folio movido a lavado ($cantidad uds)",
            'data' => $proceso,
        ], 201);
    }

    /**
     * GET /lavado/pipeline — Folios grouped by lavado pipeline status
     */
    public function pipeline(Request $request): JsonResponse
    {
        $query = ProcesoEmpaque::with($this->eagerLoad)
            ->whereIn('status', ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion']);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $procesos = $query->orderByDesc('id')->get();

        $grouped = [
            'lavando' => $procesos->where('status', 'lavando')->values(),
            'lavado' => $procesos->where('status', 'lavado')->values(),
            'hidrotermico' => $procesos->where('status', 'hidrotermico')->values(),
            'enfriando' => $procesos->where('status', 'enfriando')->values(),
            'listo_produccion' => $procesos->where('status', 'listo_produccion')->values(),
        ];

        // Check if entity uses hidrotermico
        $usaHidrotermico = false;
        if ($request->filled('entity_id')) {
            $entity = Entity::find($request->entity_id);
            $usaHidrotermico = $entity?->usa_hidrotermico ?? false;
        }

        return response()->json([
            'success' => true,
            'data' => $grouped,
            'usa_hidrotermico' => $usaHidrotermico,
        ]);
    }

    /**
     * POST /lavado/{proceso}/completar-lavado
     * Accepts optional rezaga data to register in the same step
     */
    public function completarLavado(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'lavando') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden completar folios en estado "lavando"',
            ], 422);
        }

        // Validate optional rezaga
        $request->validate([
            'rezaga_kg' => 'nullable|numeric|min:0.01',
            'rezaga_unidades' => 'nullable|integer|min:0',
            'subtipo_rezaga' => 'required_with:rezaga_kg|in:hoja,producto',
            'rezaga_motivo' => 'nullable|string|max:500',
            'rezaga_observaciones' => 'nullable|string|max:1000',
        ]);

        // Register rezaga if provided
        if ($request->filled('rezaga_kg')) {
            $this->crearRezagaInterna($proceso, 'lavado', $request);
        }

        // Check if entity uses hidrotermico
        $entity = Entity::find($proceso->entity_id);
        $usaHidrotermico = $entity?->usa_hidrotermico ?? false;

        if ($usaHidrotermico) {
            $proceso->update(['status' => 'lavado']);
            $mensaje = 'Lavado completado. Pendiente de hidrotérmico';
        } else {
            $proceso->update([
                'status' => 'listo_produccion',
                'fecha_listo_produccion' => now('America/Mexico_City')->toDateString(),
            ]);
            $mensaje = 'Lavado completado. Listo para producción';
        }

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'data' => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/iniciar-hidrotermico
     */
    public function iniciarHidrotermico(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'lavado') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede iniciar hidrotérmico en folios con status "lavado"',
            ], 422);
        }

        $proceso->update([
            'status' => 'hidrotermico',
            'fecha_hidrotermico' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Tratamiento hidrotérmico iniciado',
            'data' => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/completar-hidrotermico
     * Accepts optional rezaga data to register in the same step
     */
    public function completarHidrotermico(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'hidrotermico') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede completar hidrotérmico en folios con status "hidrotermico"',
            ], 422);
        }

        // Validate optional rezaga
        $request->validate([
            'rezaga_kg' => 'nullable|numeric|min:0.01',
            'rezaga_unidades' => 'nullable|integer|min:0',
            'subtipo_rezaga' => 'required_with:rezaga_kg|in:hoja,producto',
            'rezaga_motivo' => 'nullable|string|max:500',
            'rezaga_observaciones' => 'nullable|string|max:1000',
        ]);

        // Register rezaga if provided
        if ($request->filled('rezaga_kg')) {
            $this->crearRezagaInterna($proceso, 'hidrotermico', $request);
        }

        $proceso->update([
            'status' => 'enfriando',
            'fecha_enfriamiento' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Hidrotérmico completado. En enfriamiento',
            'data' => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/completar-enfriamiento
     */
    public function completarEnfriamiento(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'enfriando') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede completar enfriamiento en folios con status "enfriando"',
            ], 422);
        }

        $proceso->update([
            'status' => 'listo_produccion',
            'fecha_listo_produccion' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Enfriamiento completado. Listo para producción',
            'data' => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/rezaga — Register rezaga (informational, does NOT deduct)
     */
    public function registrarRezaga(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        if (!in_array($proceso->status, ['lavando', 'hidrotermico'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede registrar rezaga en etapas de lavado o hidrotérmico',
            ], 422);
        }

        $validated = $request->validate([
            'subtipo_rezaga' => 'required|in:hoja,producto',
            'cantidad_kg' => 'required|numeric|min:0.01',
            'cantidad_unidades' => 'nullable|integer|min:0',
            'motivo' => 'nullable|string|max:500',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $tipoRezaga = $proceso->status === 'lavando' ? 'lavado' : 'hidrotermico';

        // Generate folio
        $folioRezaga = $this->generarFolioRezaga($proceso->temporada_id, $proceso->entity_id);

        $rezaga = RezagaEmpaque::create([
            'temporada_id' => $proceso->temporada_id,
            'entity_id' => $proceso->entity_id,
            'proceso_id' => $proceso->id,
            'folio_rezaga' => $folioRezaga,
            'tipo_rezaga' => $tipoRezaga,
            'subtipo_rezaga' => $validated['subtipo_rezaga'],
            'fecha' => now('America/Mexico_City')->toDateString(),
            'cantidad_kg' => $validated['cantidad_kg'],
            'motivo' => $validated['motivo'] ?? null,
            'status' => 'pendiente',
            'observaciones' => $validated['observaciones'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Update informational accumulators on proceso (does NOT deduct cantidad_disponible)
        $campo_kg = "rezaga_{$tipoRezaga}_kg";
        $campo_cantidad = "rezaga_{$tipoRezaga}_cantidad";
        $proceso->increment($campo_kg, $validated['cantidad_kg']);
        if (!empty($validated['cantidad_unidades'])) {
            $proceso->increment($campo_cantidad, $validated['cantidad_unidades']);
        }

        $rezaga->load([
            'proceso:id,folio_proceso',
            'proceso.productor:id,nombre,apellido',
            'creador:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga registrada (informativa)',
            'data' => $rezaga,
        ], 201);
    }

    /**
     * POST /lavado/{proceso}/devolver-piso — Undo: remove from lavado pipeline
     */
    public function devolverAPiso(ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'lavando') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden devolver folios en estado "lavando"',
            ], 422);
        }

        $folio = $proceso->folio_proceso;
        $proceso->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "Folio $folio devuelto a piso",
        ]);
    }

    /**
     * Helper: create rezaga record during step completion
     */
    private function crearRezagaInterna(ProcesoEmpaque $proceso, string $tipoRezaga, Request $request): RezagaEmpaque
    {
        $folioRezaga = $this->generarFolioRezaga($proceso->temporada_id, $proceso->entity_id);

        $rezaga = RezagaEmpaque::create([
            'temporada_id' => $proceso->temporada_id,
            'entity_id' => $proceso->entity_id,
            'proceso_id' => $proceso->id,
            'folio_rezaga' => $folioRezaga,
            'tipo_rezaga' => $tipoRezaga,
            'subtipo_rezaga' => $request->input('subtipo_rezaga'),
            'fecha' => now('America/Mexico_City')->toDateString(),
            'cantidad_kg' => $request->input('rezaga_kg'),
            'motivo' => $request->input('rezaga_motivo'),
            'status' => 'pendiente',
            'observaciones' => $request->input('rezaga_observaciones'),
            'created_by' => $request->user()->id,
        ]);

        // Update informational accumulators
        $proceso->increment("rezaga_{$tipoRezaga}_kg", $request->input('rezaga_kg'));
        if ($request->filled('rezaga_unidades')) {
            $proceso->increment("rezaga_{$tipoRezaga}_cantidad", $request->input('rezaga_unidades'));
        }

        return $rezaga;
    }

    private function generarFolioRezaga(int $temporadaId, int $entityId): string
    {
        $entityPad = str_pad($entityId, 2, '0', STR_PAD_LEFT);
        $prefix = "REZ-{$entityPad}-";

        $lastFolio = RezagaEmpaque::withTrashed()
            ->where('temporada_id', $temporadaId)
            ->where('entity_id', $entityId)
            ->where('folio_rezaga', 'like', "{$prefix}%")
            ->orderByDesc('folio_rezaga')
            ->value('folio_rezaga');

        $nextNum = 1;
        if ($lastFolio) {
            $nextNum = (int) str_replace($prefix, '', $lastFolio) + 1;
        }

        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
