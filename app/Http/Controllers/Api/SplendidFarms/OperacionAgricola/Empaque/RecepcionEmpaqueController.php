<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Events\SalidaCampoUpdated;
use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Models\RecepcionEmpaque;
use App\Models\SalidaCampoCosecha;
use App\Models\TipoCarga;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecepcionEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'salidaCampo:id,folio_salida,fecha,cantidad,peso_neto_kg,vehiculo,chofer,es_batanga',
        'productor:id,nombre,apellido',
        'lote:id,nombre,numero_lote,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'etapa:id,nombre,orden,variedad_id',
        'etapa.variedad:id,nombre',
        'tipoCarga:id,nombre,peso_estimado_kg',
        'recibidoPor:id,name',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = RecepcionEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->byEntity($request->entity_id);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_recepcion', 'like', "%{$search}%")
                  ->orWhere('vehiculo', 'like', "%{$search}%")
                  ->orWhere('chofer', 'like', "%{$search}%")
                  ->orWhereHas('productor', fn($sub) => $sub->where('nombre', 'like', "%{$search}%")->orWhere('apellido', 'like', "%{$search}%"))
                  ->orWhereHas('tipoCarga', fn($sub) => $sub->where('nombre', 'like', "%{$search}%"));
            });
        }

        $recepciones = $query->orderByDesc('fecha_recepcion')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $recepciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'nullable|exists:entities,id',
            'salida_campo_id' => [
                'nullable',
                'exists:salidas_campo_cosecha,id',
                Rule::unique('recepciones_empaque', 'salida_campo_id')->whereNull('deleted_at'),
            ],
            'fecha_recepcion' => 'required|date',
            'productor_id' => 'nullable|exists:productores,id',
            'lote_id' => 'nullable|exists:lotes,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'tipo_carga_id' => 'nullable|exists:tipos_carga,id',
            'cantidad_recibida' => 'nullable|integer|min:1',
            'peso_recibido_kg' => 'nullable|numeric|min:0',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'clave_we' => 'nullable|string|max:100',
            'lote_origen' => 'nullable|string|max:100',
            'vehiculo' => 'nullable|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'es_batanga' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        // Auto-fill data from salida de campo if linked
        if (!empty($validated['salida_campo_id'])) {
            $salida = SalidaCampoCosecha::find($validated['salida_campo_id']);
            if ($salida) {
                // Preserve user-provided cantidad/peso for physical validation
                $cantidadRecibida = $validated['cantidad_recibida'] ?? $salida->cantidad;
                $pesoRecibido = $validated['peso_recibido_kg'] ?? $salida->peso_neto_kg;

                $validated['entity_id'] = $salida->destino_entity_id;
                $validated['productor_id'] = $salida->productor_id;
                $validated['lote_id'] = $salida->lote_id;
                $validated['etapa_id'] = $salida->etapa_id;
                $validated['zona_cultivo_id'] = $salida->zona_cultivo_id;
                $validated['tipo_carga_id'] = $salida->tipo_carga_id;
                $validated['cantidad_recibida'] = $cantidadRecibida;
                $validated['peso_recibido_kg'] = $pesoRecibido;
                $validated['vehiculo'] = $salida->vehiculo;
                $validated['chofer'] = $salida->chofer;
                $validated['es_batanga'] = $salida->es_batanga;
                $validated['folio_recepcion'] = $salida->folio_salida;

                // Copiar datos de báscula de la salida si no se proporcionan
                if (empty($validated['peso_bascula']) && $salida->peso_bascula) {
                    $validated['peso_bascula'] = $salida->peso_bascula;
                }
                if (empty($validated['folio_ticket_bascula']) && $salida->folio_ticket_bascula) {
                    $validated['folio_ticket_bascula'] = $salida->folio_ticket_bascula;
                }

                // Remove soft-deleted recepcion with same folio to avoid unique constraint
                RecepcionEmpaque::onlyTrashed()
                    ->where('folio_recepcion', $salida->folio_salida)
                    ->forceDelete();
            }
        } else {
            // Manual entry: validate required fields
            $missing = collect(['entity_id', 'productor_id', 'lote_id', 'etapa_id', 'tipo_carga_id', 'cantidad_recibida'])
                ->filter(fn($f) => empty($validated[$f]));
            if ($missing->isNotEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Para entradas manuales se requieren: planta, productor, lote, etapa, tipo de carga y cantidad',
                ], 422);
            }
        }

        // Auto-fill zona_cultivo from lote if not set
        if (empty($validated['zona_cultivo_id']) && !empty($validated['lote_id'])) {
            $lote = Lote::find($validated['lote_id']);
            if ($lote) {
                $validated['zona_cultivo_id'] = $lote->zona_cultivo_id;
            }
        }

        // Auto-calc peso from tipo_carga × cantidad if not provided
        if (empty($validated['peso_recibido_kg']) && !empty($validated['tipo_carga_id']) && !empty($validated['cantidad_recibida'])) {
            $tipoCarga = TipoCarga::find($validated['tipo_carga_id']);
            if ($tipoCarga) {
                $validated['peso_recibido_kg'] = $validated['cantidad_recibida'] * $tipoCarga->peso_estimado_kg;
            }
        }

        $validated['hora_recepcion'] = now('America/Mexico_City')->format('H:i:s');
        $validated['status'] = 'recibida';
        $validated['es_batanga'] = $validated['es_batanga'] ?? false;
        $validated['created_by'] = $request->user()->id;
        $validated['recibido_por'] = $request->user()->id;

        // Folio: use salida's folio if linked, else generate REC-XX-NNNN
        if (empty($validated['folio_recepcion'])) {
            $validated['folio_recepcion'] = $this->generarFolio($validated);
        }

        $recepcion = RecepcionEmpaque::create($validated);
        $recepcion->load($this->eagerLoad);

        // Update salida status to "entregada" when received from a salida de campo
        if (!empty($validated['salida_campo_id'])) {
            $salida = SalidaCampoCosecha::find($validated['salida_campo_id']);
            if ($salida) {
                $salida->update(['status' => 'entregada']);
                $salida->load([
                    'productor:id,nombre,apellido',
                    'lote:id,nombre,numero_lote,zona_cultivo_id',
                    'lote.zonaCultivo:id,nombre',
                    'etapa:id,nombre,variedad_id',
                    'etapa.variedad:id,nombre',
                    'tipoCarga:id,nombre,peso_estimado_kg',
                    'destinoEntity:id,name,code',
                ]);
                broadcast(new SalidaCampoUpdated(
                    'updated',
                    $salida->toArray(),
                    'splendidfarms',
                    'operacion-agricola',
                    'cosecha'
                ))->toOthers();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Recepción registrada exitosamente',
            'data' => $recepcion,
        ], 201);
    }

    public function show(RecepcionEmpaque $recepcion): JsonResponse
    {
        $recepcion->load([...$this->eagerLoad, 'procesos', 'evaluacionesCalidad']);

        return response()->json(['success' => true, 'data' => $recepcion]);
    }

    public function update(Request $request, RecepcionEmpaque $recepcion): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'fecha_recepcion' => 'sometimes|date',
            'productor_id' => 'sometimes|exists:productores,id',
            'lote_id' => 'sometimes|exists:lotes,id',
            'etapa_id' => 'sometimes|exists:etapas,id',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'tipo_carga_id' => 'sometimes|exists:tipos_carga,id',
            'cantidad_recibida' => 'sometimes|integer|min:1',
            'peso_recibido_kg' => 'nullable|numeric|min:0',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'clave_we' => 'nullable|string|max:100',
            'lote_origen' => 'nullable|string|max:100',
            'vehiculo' => 'nullable|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'es_batanga' => 'nullable|boolean',
            'status' => 'nullable|in:pendiente,recibida,en_proceso,rechazada',
            'observaciones' => 'nullable|string',
        ]);

        // Auto-fill zona_cultivo from lote if lote changed
        if (!empty($validated['lote_id']) && empty($validated['zona_cultivo_id'])) {
            $lote = Lote::find($validated['lote_id']);
            if ($lote) {
                $validated['zona_cultivo_id'] = $lote->zona_cultivo_id;
            }
        }

        $recepcion->update($validated);
        $recepcion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Recepción actualizada',
            'data' => $recepcion,
        ]);
    }

    public function destroy(RecepcionEmpaque $recepcion): JsonResponse
    {
        // Revert salida status back to en_transito if linked
        if ($recepcion->salida_campo_id) {
            $salida = SalidaCampoCosecha::find($recepcion->salida_campo_id);
            if ($salida && $salida->status === 'entregada') {
                $salida->update(['status' => 'en_transito']);
                $salida->load([
                    'productor:id,nombre,apellido',
                    'lote:id,nombre,numero_lote,zona_cultivo_id',
                    'lote.zonaCultivo:id,nombre',
                    'etapa:id,nombre,variedad_id',
                    'etapa.variedad:id,nombre',
                    'tipoCarga:id,nombre,peso_estimado_kg',
                    'destinoEntity:id,name,code',
                ]);
                broadcast(new SalidaCampoUpdated(
                    'updated',
                    $salida->toArray(),
                    'splendidfarms',
                    'operacion-agricola',
                    'cosecha'
                ))->toOthers();
            }
        }

        $recepcion->delete();

        return response()->json(['success' => true, 'message' => 'Recepción eliminada']);
    }

    /**
     * Lista las salidas de campo disponibles para recepción (excluye ya recibidas).
     */
    public function salidasDisponibles(Request $request): JsonResponse
    {
        $receivedIds = RecepcionEmpaque::whereNotNull('salida_campo_id')
            ->pluck('salida_campo_id')
            ->toArray();

        $query = SalidaCampoCosecha::with([
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote,zona_cultivo_id',
            'lote.zonaCultivo:id,nombre',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre',
            'tipoCarga:id,nombre,peso_estimado_kg',
            'destinoEntity:id,name,code',
        ])->where('eliminado', false)
          ->whereIn('status', ['en_transito', 'registrada'])
          ->whereNotIn('id', $receivedIds);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('destino_entity_id', $request->entity_id);
        }

        return response()->json(['success' => true, 'data' => $query->orderByDesc('fecha')->get()]);
    }

    private function generarFolio(array $data): string
    {
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        $prefix = "REC-{$entityId}-";

        $lastFolio = RecepcionEmpaque::withTrashed()
            ->where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->where('folio_recepcion', 'like', "{$prefix}%")
            ->orderByDesc('folio_recepcion')
            ->value('folio_recepcion');

        $nextNum = 1;
        if ($lastFolio) {
            $lastNum = (int) str_replace($prefix, '', $lastFolio);
            $nextNum = $lastNum + 1;
        }

        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
