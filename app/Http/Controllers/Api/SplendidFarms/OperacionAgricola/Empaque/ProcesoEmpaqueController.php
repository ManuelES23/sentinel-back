<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\ProcesoEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\TipoCarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcesoEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'recepcion:id,folio_recepcion,fecha_recepcion,cantidad_recibida,peso_recibido_kg,salida_campo_id',
        'recepcion.salidaCampo:id,folio_salida',
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
            'tipoCarga:id,nombre,peso_estimado_kg',
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
     * GET /proceso/piso — Piso = recepciones con cantidad disponible (recibido - en_proceso - procesado)
     */
    public function piso(Request $request): JsonResponse
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
            // Sum quantities in active procesos for this recepcion
            $enProceso = ProcesoEmpaque::where('recepcion_id', $rec->id)
                ->whereIn('status', ['en_proceso', 'procesado'])
                ->sum('cantidad_entrada');

            $disponible = $rec->cantidad_recibida - $enProceso;

            if ($disponible > 0) {
                $pesoUnitario = $rec->tipoCarga ? (float) $rec->tipoCarga->peso_estimado_kg : 0;

                $piso[] = [
                    'recepcion_id' => $rec->id,
                    'folio' => $rec->folio_recepcion,
                    'fecha_recepcion' => $rec->fecha_recepcion,
                    'productor' => $rec->productor,
                    'lote' => $rec->lote,
                    'etapa' => $rec->etapa,
                    'tipo_carga' => $rec->tipoCarga,
                    'entity' => $rec->entity,
                    'cantidad_recibida' => $rec->cantidad_recibida,
                    'cantidad_en_proceso' => (int) $enProceso,
                    'cantidad_disponible' => $disponible,
                    'peso_disponible_kg' => round($disponible * $pesoUnitario, 2),
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $piso]);
    }

    /**
     * POST /proceso/mover-a-proceso — Move from piso (recepcion) to procesando
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'recepcion_id' => 'required|exists:recepciones_empaque,id',
            'cantidad' => 'required|integer|min:1',
        ]);

        $recepcion = RecepcionEmpaque::with('tipoCarga')->find($validated['recepcion_id']);
        if (!$recepcion) {
            return response()->json(['status' => 'error', 'message' => 'Recepción no encontrada'], 404);
        }

        // Calculate available
        $enProceso = ProcesoEmpaque::where('recepcion_id', $recepcion->id)
            ->whereIn('status', ['en_proceso', 'procesado'])
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

        // Generate unique folio: base folio for first entry, suffix for subsequent
        $baseFolio = $recepcion->folio_recepcion;
        if (!ProcesoEmpaque::where('folio_proceso', $baseFolio)->exists()) {
            $folioProceso = $baseFolio;
        } else {
            $suffix = 2;
            while (ProcesoEmpaque::where('folio_proceso', "{$baseFolio}-{$suffix}")->exists()) {
                $suffix++;
            }
            $folioProceso = "{$baseFolio}-{$suffix}";
        }

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
     */
    public function destroy(ProcesoEmpaque $proceso): JsonResponse
    {
        if ($proceso->status !== 'en_proceso') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden devolver folios en proceso',
            ], 422);
        }

        $proceso->forceDelete();

        return response()->json(['success' => true, 'message' => 'Folio devuelto a piso']);
    }

    /**
     * POST /proceso/{id}/cerrar
     * cuarto_frio + fresco. If sum < cantidad → remainder stays in piso automatically
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
}
