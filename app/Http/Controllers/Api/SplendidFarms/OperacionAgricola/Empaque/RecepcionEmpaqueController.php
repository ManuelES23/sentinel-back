<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Events\SalidaCampoUpdated;
use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Models\Etapa;
use App\Models\RecepcionEmpaque;
use App\Models\SalidaCampoCosecha;
use App\Models\TipoCarga;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecepcionEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'salidaCampo:id,folio_salida,fecha,cantidad,peso_neto_kg,vehiculo,chofer,es_batanga,variedad_id',
        'salidaCampo.variedad:id,nombre',
        'productor:id,nombre,apellido',
        'lote:id,nombre,numero_lote,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'etapa:id,nombre,orden,variedad_id',
        'etapa.variedad:id,nombre',
        'variedad:id,nombre',
        'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
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
        if ($request->filled('productor_id')) {
            $query->where('productor_id', $request->productor_id);
        }

        $fechaInicio = $request->input('fecha_inicio', $request->input('from_date'));
        $fechaFin = $request->input('fecha_fin', $request->input('to_date'));

        if (!empty($fechaInicio)) {
            $query->whereDate('fecha_recepcion', '>=', $fechaInicio);
        }

        if (!empty($fechaFin)) {
            $query->whereDate('fecha_recepcion', '<=', $fechaFin);
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
            'variedad_id' => 'nullable|exists:variedades,id',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'tipo_carga_id' => 'nullable|exists:tipos_carga,id',
            'cantidad_recibida' => 'nullable|integer|min:1',
            'peso_recibido_kg' => 'nullable|numeric|min:0',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'clave_we' => 'nullable|string|max:100',
            'lote_origen' => 'nullable|string|max:100',
            'lote_producto_terminado' => 'nullable|string|max:100',
            'clasificacion' => 'nullable|in:convencional,organico',
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
                $validated['variedad_id'] = $salida->variedad_id;
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
            $missingBase = collect(['entity_id', 'productor_id', 'lote_id', 'tipo_carga_id', 'cantidad_recibida'])
                ->filter(fn($f) => empty($validated[$f]));
            $missingVariedad = empty($validated['etapa_id']) && empty($validated['variedad_id']);

            $missing = $missingBase;
            if ($missingVariedad) {
                $missing = $missing->push('variedad_id');
            }
            if ($missing->isNotEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Para entradas manuales se requieren: planta, productor, lote, variedad o etapa, tipo de carga y cantidad',
                ], 422);
            }
        }

        // Si tiene etapa, obtener variedad de la etapa automaticamente
        if (!empty($validated['etapa_id'])) {
            $etapa = Etapa::find($validated['etapa_id']);
            if ($etapa) {
                $validated['variedad_id'] = $etapa->variedad_id;
            }
        }

        // Fallback: si por algún motivo no llegó etapa válida, pero sí variedad desde salida/manual,
        // mantenemos variedad para evitar rechazos innecesarios en recepciones con salida vinculada.
        if (empty($validated['etapa_id']) && empty($validated['variedad_id']) && !empty($validated['salida_campo_id'])) {
            $salidaVariedad = SalidaCampoCosecha::query()
                ->whereKey($validated['salida_campo_id'])
                ->value('variedad_id');

            if (!empty($salidaVariedad)) {
                $validated['variedad_id'] = $salidaVariedad;
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

        // Folio: si viene de una salida ya trae folio_recepcion fijo (línea ~125,
        // copiado de folio_salida) y no tiene sentido regenerarlo en un reintento.
        // Si es entrada manual, se genera aquí y sí puede recalcularse ante colisión
        // por inserciones casi simultáneas (misma combinación capturada dos veces
        // a la vez desde Recepción, o a la vez que se crea una Salida nueva).
        $folioEsManual = empty($validated['folio_recepcion']);
        $recepcion = null;
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $recepcion = DB::transaction(function () use ($validated, $folioEsManual) {
                    $payload = $validated;
                    if ($folioEsManual) {
                        $payload['folio_recepcion'] = $this->generarFolio($payload);
                    }

                    return RecepcionEmpaque::create($payload);
                });

                break;
            } catch (QueryException $e) {
                if ($folioEsManual && $this->isFolioDuplicateError($e) && $attempt < $maxAttempts) {
                    continue;
                }
                throw $e;
            }
        }

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
                    'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
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
        if ($request->filled('etapa_id') && !Etapa::whereKey($request->input('etapa_id'))->exists()) {
            // En registros legacy puede venir etapa inexistente desde frontend; se normaliza a null para permitir edición.
            $request->merge(['etapa_id' => null]);
        }

        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'fecha_recepcion' => 'sometimes|date',
            'productor_id' => 'sometimes|exists:productores,id',
            'lote_id' => 'sometimes|exists:lotes,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'variedad_id' => 'nullable|exists:variedades,id',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'tipo_carga_id' => 'sometimes|exists:tipos_carga,id',
            'cantidad_recibida' => 'sometimes|integer|min:1',
            'peso_recibido_kg' => 'nullable|numeric|min:0',
            'peso_bascula' => 'nullable|numeric|min:0',
            'folio_ticket_bascula' => 'nullable|string|max:100',
            'clave_we' => 'nullable|string|max:100',
            'lote_origen' => 'nullable|string|max:100',
            'lote_producto_terminado' => 'nullable|string|max:100',
            'clasificacion' => 'nullable|in:convencional,organico',
            'vehiculo' => 'nullable|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'es_batanga' => 'nullable|boolean',
            'status' => 'nullable|in:pendiente,recibida,en_proceso,rechazada',
            'observaciones' => 'nullable|string',
        ]);

        // Si tiene etapa, obtener variedad de la etapa automaticamente
        if (array_key_exists('etapa_id', $validated) && !empty($validated['etapa_id'])) {
            $etapa = Etapa::find($validated['etapa_id']);
            if ($etapa) {
                $validated['variedad_id'] = $etapa->variedad_id;
            }
        }

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
                    'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
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
            'variedad:id,nombre',
            'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
            'destinoEntity:id,name,code',
        ])->where('eliminado', false)
          ->whereIn('status', ['en_transito', 'registrada', 'pendiente_descarga'])
          ->whereNotIn('id', $receivedIds);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('destino_entity_id', $request->entity_id);
        }

        return response()->json(['success' => true, 'data' => $query->orderByDesc('fecha')->get()]);
    }

    /**
     * Confirmar llegada de una salida de campo a la planta empacadora.
     * Cambia el status de en_transito/registrada → pendiente_descarga.
     */
    public function confirmarLlegada(Request $request, SalidaCampoCosecha $salida): JsonResponse
    {
        if ($salida->eliminado) {
            return response()->json(['status' => 'error', 'message' => 'Salida no encontrada'], 404);
        }

        if (!in_array($salida->status, ['en_transito', 'registrada'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden confirmar llegadas de salidas en tránsito o registradas',
            ], 422);
        }

        // Verificar que ya no tenga una recepción vinculada
        $tieneRecepcion = RecepcionEmpaque::where('salida_campo_id', $salida->id)->whereNull('deleted_at')->exists();
        if ($tieneRecepcion) {
            return response()->json([
                'status' => 'error',
                'message' => 'Esta salida ya tiene una recepción registrada',
            ], 422);
        }

        $salida->update(['status' => 'pendiente_descarga']);
        $salida->load([
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote,zona_cultivo_id',
            'lote.zonaCultivo:id,nombre',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre',
            'tipoCarga:id,nombre,categoria_caja,peso_estimado_kg',
            'destinoEntity:id,name,code',
        ]);

        broadcast(new SalidaCampoUpdated(
            'updated',
            $salida->toArray(),
            'splendidfarms',
            'operacion-agricola',
            'cosecha'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Llegada confirmada. Salida marcada como pendiente de descarga.',
            'data' => $salida,
        ]);
    }

    private function generarFolio(array $data): string
    {
        // Mismo formato que SalidaCampoCosechaController::generarFolio() (PP-ZZ-LL-EENN),
        // para que ambas tablas compartan el mismo espacio de numeración por combinación
        // productor/zona/lote/etapa dentro de la temporada. El segmento de lote usa
        // lote.numero_lote (no el lote_id crudo) y el de etapa usa etapa.orden (no el
        // etapa_id crudo) — igual que el lado de Salida — para que los prefijos calcen
        // entre los dos generadores y la búsqueda cruzada de abajo funcione.
        $productor = str_pad((string) ((int) ($data['productor_id'] ?? 0)), 2, '0', STR_PAD_LEFT);
        $zona = str_pad((string) ((int) ($data['zona_cultivo_id'] ?? 0)), 2, '0', STR_PAD_LEFT);

        $lote = !empty($data['lote_id']) ? Lote::find($data['lote_id']) : null;
        $loteNum = str_pad((string) ((int) ($lote?->numero_lote ?? 0)), 2, '0', STR_PAD_LEFT);

        $etapa = !empty($data['etapa_id']) ? Etapa::find($data['etapa_id']) : null;
        $etapaOrden = str_pad((string) ((int) ($etapa?->orden ?? 0)), 2, '0', STR_PAD_LEFT);

        $prefix = "{$productor}-{$zona}-{$loteNum}-{$etapaOrden}";

        // folio_recepcion es unique GLOBAL en la BD (no por entity_id), así que el
        // cálculo tampoco debe acotarse por entidad — de lo contrario dos plantas
        // recibiendo del mismo productor/lote podrían calcular el mismo consecutivo
        // y chocar contra ese unique al insertar.
        $lastFolioRecepcion = RecepcionEmpaque::withTrashed()
            ->where('temporada_id', $data['temporada_id'])
            ->where('folio_recepcion', 'like', "{$prefix}%")
            ->orderByDesc('folio_recepcion')
            ->value('folio_recepcion');

        // salidas_campo_cosecha comparte el mismo espacio de numeración: puede existir
        // una salida para esta combinación aún no recibida (folio ya "reservado" en esa
        // tabla). Si no se considera aquí, una recepción manual nueva puede recalcular
        // un número que esa salida ya usó y, al recibirla después (se copia folio_salida
        // tal cual a folio_recepcion), chocaría contra el folio manual ya insertado.
        $lastFolioSalida = SalidaCampoCosecha::where('temporada_id', $data['temporada_id'])
            ->where('productor_id', $data['productor_id'] ?? null)
            ->where('zona_cultivo_id', $data['zona_cultivo_id'] ?? null)
            ->where('lote_id', $data['lote_id'] ?? null)
            ->where('etapa_id', $data['etapa_id'] ?? null)
            ->where('folio_salida', 'like', "{$prefix}%")
            ->orderByDesc('folio_salida')
            ->value('folio_salida');

        $nextConsecutivo = 1;
        foreach ([$lastFolioRecepcion, $lastFolioSalida] as $folio) {
            if ($folio && preg_match('/(\d{2})$/', $folio, $matches)) {
                $nextConsecutivo = max($nextConsecutivo, ((int) $matches[1]) + 1);
            }
        }

        return $prefix . str_pad((string) $nextConsecutivo, 2, '0', STR_PAD_LEFT);
    }

    private function isFolioDuplicateError(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'recepciones_empaque_folio_recepcion_unique');
    }
}
