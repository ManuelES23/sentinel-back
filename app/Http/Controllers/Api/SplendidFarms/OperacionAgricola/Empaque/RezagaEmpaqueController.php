<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\RezagaEmpaque;
use App\Models\ProcesoEmpaque;
use App\Models\Temporada;
use App\Models\TipoCarga;
use App\Services\Empaque\RezagaEnBins;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RezagaEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'proceso:id,folio_proceso,productor_id,lote_id,etapa_id,recepcion_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'proceso.etapa:id,nombre,variedad_id',
        'proceso.etapa.variedad:id,nombre,cultivo_id',
        'proceso.etapa.variedad.cultivo:id,nombre',
        'proceso.recepcion:id,salida_campo_id,folio_recepcion,cantidad_recibida,lote_producto_terminado,variedad_id',
        'proceso.recepcion.variedad:id,nombre',
        'proceso.recepcion.salidaCampo:id,variedad_id,folio_salida,cantidad',
        'proceso.recepcion.salidaCampo.variedad:id,nombre',
        'tipoCarga:id,nombre,peso_estimado_kg,categoria_caja',
        'ventaDetalles:id,rezaga_id,peso_kg,bins',
        'creador:id,name',
    ];

    /**
     * GET /rezaga/procesos-del-dia — Procesos con rezaga, filtro de fecha opcional
     */
    public function procesosDelDia(Request $request): JsonResponse
    {
        $request->validate([
            'fecha' => 'nullable|date',
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $fecha = $request->fecha;
        $temporadaId = $request->temporada_id;
        $entityId = $request->entity_id;

        $eagerProceso = [
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre,cultivo_id',
            'etapa.variedad.cultivo:id,nombre',
            'recepcion:id,salida_campo_id,folio_recepcion,cantidad_recibida,lote_producto_terminado,variedad_id',
            'recepcion.variedad:id,nombre',
            'recepcion.salidaCampo:id,variedad_id,folio_salida,cantidad',
            'recepcion.salidaCampo.variedad:id,nombre',
            'tipoCarga:id,nombre',
        ];

        if ($fecha) {
            // Procesos processed on this date
            $procesosPorFecha = ProcesoEmpaque::with($eagerProceso)
                ->where('temporada_id', $temporadaId)
                ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
                ->whereDate('fecha_proceso', $fecha)
                ->orderByDesc('id')
                ->get();

            // Procesos that have rezagas registered on this date (may have been processed another day)
            $idsConRezagaEnFecha = RezagaEmpaque::whereDate('fecha', $fecha)
                ->whereHas('proceso', function ($q) use ($temporadaId, $entityId) {
                    $q->where('temporada_id', $temporadaId);
                    if ($entityId) $q->where('entity_id', $entityId);
                })
                ->pluck('proceso_id')
                ->unique();

            $procesosConRezaga = ProcesoEmpaque::with($eagerProceso)
                ->whereIn('id', $idsConRezagaEnFecha)
                ->whereNotIn('id', $procesosPorFecha->pluck('id'))
                ->orderByDesc('id')
                ->get();

            $procesos = $procesosPorFecha->merge($procesosConRezaga);

            // Attach rezagas filtered by date
            $procesoIds = $procesos->pluck('id');
            $rezagas = RezagaEmpaque::with($this->eagerLoad)
                ->whereIn('proceso_id', $procesoIds)
                ->whereDate('fecha', $fecha)
                ->orderByDesc('id')
                ->get()
                ->map(fn(RezagaEmpaque $r) => $this->appendCantidadHistorica($r))
                ->groupBy('proceso_id');
        } else {
            // No date filter: all procesos that have rezagas in this temporada
            $idsConRezaga = RezagaEmpaque::whereHas('proceso', function ($q) use ($temporadaId, $entityId) {
                    $q->where('temporada_id', $temporadaId);
                    if ($entityId) $q->where('entity_id', $entityId);
                })
                ->pluck('proceso_id')
                ->unique();

            $procesos = ProcesoEmpaque::with($eagerProceso)
                ->whereIn('id', $idsConRezaga)
                ->orderByDesc('fecha_proceso')
                ->orderByDesc('id')
                ->get();

            // Attach ALL rezagas for each proceso
            $procesoIds = $procesos->pluck('id');
            $rezagas = RezagaEmpaque::with($this->eagerLoad)
                ->whereIn('proceso_id', $procesoIds)
                ->orderByDesc('id')
                ->get()
                ->map(fn(RezagaEmpaque $r) => $this->appendCantidadHistorica($r))
                ->groupBy('proceso_id');
        }

        $result = $procesos->map(function (ProcesoEmpaque $p) use ($rezagas) {
            $data = $p->toArray();
            $data['rezagas_del_dia'] = $rezagas->get($p->id, collect())->values();
            return $data;
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = RezagaEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('tipo_rezaga')) {
            $query->where('tipo_rezaga', $request->tipo_rezaga);
        }
        if ($request->filled('productor_id')) {
            $query->whereHas('proceso', fn ($q) => $q->where('productor_id', $request->productor_id));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_rezaga', 'like', "%{$search}%")
                  ->orWhere('motivo', 'like', "%{$search}%");
            });
        }

        $rezagas = $query->orderByDesc('fecha')->orderByDesc('id')->get()
            ->map(fn(RezagaEmpaque $r) => $this->appendCantidadHistorica($r));

        return response()->json(['success' => true, 'data' => $rezagas]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->filled('temporada_id') && Temporada::esDeBins((int) $request->temporada_id)) {
            return $this->storeEnBins($request);
        }

        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'tipo_rezaga' => 'required|in:produccion,cuarto_frio,lavado,hidrotermico',
            'subtipo_rezaga' => 'required|in:hoja,producto',
            'fecha' => 'required|date',
            'cantidad_kg' => 'nullable|numeric|min:0.01',
            'modo_registro' => 'nullable|in:general,tarima',
            'tipo_carga_id' => 'nullable|exists:tipos_carga,id',
            'total_cajas' => 'nullable|integer|min:1',
            'peso_bascula_kg' => 'nullable|numeric|min:0.01',
            'cantidad_unidades_pequenas' => 'nullable|integer|min:0',
            'motivo' => 'nullable|string',
            'status' => 'nullable|in:pendiente,vendida,destruida',
            'observaciones' => 'nullable|string',
        ]);

        $modoRegistro = $validated['modo_registro'] ?? 'general';
        $validated['modo_registro'] = $modoRegistro;

        if ($modoRegistro === 'tarima') {
            if (empty($validated['tipo_carga_id']) || empty($validated['total_cajas']) || empty($validated['peso_bascula_kg'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Para registro por tarima se requiere tipo de carga, total de cajas y peso báscula.',
                ], 422);
            }

            $validated['cantidad_kg'] = (float) $validated['peso_bascula_kg'];
        }

        if (empty($validated['cantidad_kg'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'La cantidad en kg es obligatoria.',
            ], 422);
        }

        $validated['status'] = $validated['status'] ?? 'pendiente';
        $validated['created_by'] = $request->user()->id;
        $validated['folio_rezaga'] = $this->generarFolio($validated);

        $rezaga = RezagaEmpaque::create($validated);
        $rezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga registrada exitosamente',
            'data' => $rezaga,
        ], 201);
    }

    /**
     * Alta de rezaga en una temporada que opera en bins: el cliente manda los
     * bins y el tipo de bin; los kg estimados y el tipo/subtipo los calcula el
     * servidor (RezagaEnBins). Cualquier kg o tipo que mande el cliente se ignora.
     */
    private function storeEnBins(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'fecha' => 'required|date',
            'tipo_carga_id' => 'required|integer',
            'cantidad_bins' => 'required|integer|min:1',
            // Tipo de rezaga elegido aparte del bin (fresco = «Producto» en pantalla).
            // Sin él se usa la clase propia del bin (bins de rezaga dedicados).
            'clase_rezaga' => 'nullable|in:hoja,fresco,cuarto_frio',
            // 'tarima' = captura bin por bin de la pestaña Tarimas; solo cambia cómo se lista y se imprime.
            'modo_registro' => 'nullable|in:general,tarima',
            'motivo' => 'nullable|string',
            'observaciones' => 'nullable|string',
        ]);

        $temporada = Temporada::findOrFail($validated['temporada_id']);

        $this->validarProcesoDeLaTemporada($temporada->id, (int) $validated['proceso_id']);

        $datos = array_merge($validated, RezagaEnBins::atributos(
            $temporada,
            (int) $validated['tipo_carga_id'],
            (int) $validated['cantidad_bins'],
            TipoCarga::CLASES_REZAGA,
            $validated['clase_rezaga'] ?? null,
        ));
        unset($datos['clase_rezaga']);

        // RezagaEnBins::atributos devuelve 'general'; la captura por tarima lo conserva.
        $datos['modo_registro'] = $validated['modo_registro'] ?? 'general';
        $datos['status'] = 'pendiente';
        $datos['created_by'] = $request->user()->id;
        $datos['folio_rezaga'] = $this->generarFolio($datos);

        $rezaga = RezagaEmpaque::create($datos);
        $rezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga registrada exitosamente',
            'data' => $rezaga,
        ], 201);
    }

    /**
     * Edición de una rezaga capturada en bins: si cambian los bins o el tipo
     * de bin se recalculan los kg estimados; una rezaga que ya tiene ventas no
     * admite ese cambio porque descuadraría lo vendido.
     */
    private function updateEnBins(Request $request, RezagaEmpaque $rezaga): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'proceso_id' => 'sometimes|required|exists:proceso_empaque,id',
            'fecha' => 'sometimes|date',
            'tipo_carga_id' => 'sometimes|integer',
            'cantidad_bins' => 'sometimes|integer|min:1',
            'clase_rezaga' => 'sometimes|nullable|in:hoja,fresco,cuarto_frio',
            'motivo' => 'nullable|string',
            'status' => 'nullable|in:pendiente,vendida,destruida',
            'observaciones' => 'nullable|string',
        ]);

        if (! empty($validated['proceso_id'])) {
            $this->validarProcesoDeLaTemporada((int) $rezaga->temporada_id, (int) $validated['proceso_id']);
        }

        $bins = (int) ($validated['cantidad_bins'] ?? $rezaga->cantidad_bins);
        $tipoCargaId = (int) ($validated['tipo_carga_id'] ?? $rezaga->tipo_carga_id);

        // Clase de rezaga: la que mande el cliente (se elige aparte del bin) o,
        // si no manda ninguna y el bin del registro no cambia ni trae clase propia,
        // la que ya tiene el registro. En cualquier otro caso sin clase explícita
        // rige el camino legado (la clase del propio bin manda).
        $claseActual = $rezaga->claseRezagaEfectiva();
        $claseEnviada = $validated['clase_rezaga'] ?? null;
        $claseCambia = $claseEnviada !== null && $claseEnviada !== $claseActual;
        $clase = $claseEnviada;
        if ($clase === null && $tipoCargaId === (int) $rezaga->tipo_carga_id) {
            $tipoTieneClase = TipoCarga::query()->whereKey($tipoCargaId)->whereNotNull('clase_rezaga')->exists();
            $clase = $tipoTieneClase ? null : $claseActual;
        }
        unset($validated['clase_rezaga']);

        // Solo se recalcula cuando de verdad cambia algo: reenviar los mismos
        // valores (el formulario manda todo) no debe mover los kg guardados si
        // el peso del bin cambió después en el catálogo.
        $cambianBinsOTipo = $bins !== (int) $rezaga->cantidad_bins || $tipoCargaId !== (int) $rezaga->tipo_carga_id;

        if ($cambianBinsOTipo || $claseCambia) {
            if ($rezaga->ventaDetalles()->exists()) {
                throw ValidationException::withMessages($cambianBinsOTipo
                    ? ['cantidad_bins' => 'Esta rezaga ya tiene ventas; no se pueden cambiar sus bins.']
                    : ['clase_rezaga' => 'Esta rezaga ya tiene ventas; no se puede cambiar su tipo de rezaga.']);
            }

            $validated = array_merge($validated, RezagaEnBins::atributos(
                Temporada::findOrFail($rezaga->temporada_id),
                $tipoCargaId,
                $bins,
                TipoCarga::CLASES_REZAGA,
                $clase,
            ));
        } else {
            unset($validated['cantidad_bins'], $validated['tipo_carga_id']);
        }

        $rezaga->update($validated);
        $rezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga actualizada',
            'data' => $rezaga,
        ]);
    }

    /**
     * En una temporada en bins el proceso de la rezaga debe ser de esa misma
     * temporada (revisión barata contra procesos de otro cultivo).
     */
    private function validarProcesoDeLaTemporada(int $temporadaId, int $procesoId): void
    {
        $procesoTemporadaId = ProcesoEmpaque::query()->whereKey($procesoId)->value('temporada_id');

        if ((int) $procesoTemporadaId !== $temporadaId) {
            throw ValidationException::withMessages([
                'proceso_id' => 'El proceso no pertenece a esta temporada.',
            ]);
        }
    }
    public function show(RezagaEmpaque $rezaga): JsonResponse
    {
        $rezaga->load([...$this->eagerLoad, 'ventaDetalles']);

        return response()->json(['success' => true, 'data' => $rezaga]);
    }

    public function update(Request $request, RezagaEmpaque $rezaga): JsonResponse
    {
        if ($rezaga->cantidad_bins !== null && Temporada::esDeBins((int) $rezaga->temporada_id)) {
            return $this->updateEnBins($request, $rezaga);
        }

        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'tipo_rezaga' => 'sometimes|in:produccion,cuarto_frio,lavado,hidrotermico',
            'subtipo_rezaga' => 'sometimes|in:hoja,producto',
            'fecha' => 'sometimes|date',
            'cantidad_kg' => 'sometimes|numeric|min:0.01',
            'modo_registro' => 'sometimes|in:general,tarima',
            'tipo_carga_id' => 'nullable|exists:tipos_carga,id',
            'total_cajas' => 'nullable|integer|min:1',
            'peso_bascula_kg' => 'nullable|numeric|min:0.01',
            'cantidad_unidades_pequenas' => 'nullable|integer|min:0',
            'motivo' => 'nullable|string',
            'status' => 'nullable|in:pendiente,vendida,destruida',
            'observaciones' => 'nullable|string',
        ]);

        $modoRegistroUpdate = $validated['modo_registro'] ?? $rezaga->modo_registro;
        if ($modoRegistroUpdate === 'tarima' && array_key_exists('peso_bascula_kg', $validated) && !array_key_exists('cantidad_kg', $validated)) {
            $validated['cantidad_kg'] = $validated['peso_bascula_kg'];
        }

        $rezaga->update($validated);
        $rezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga actualizada',
            'data' => $rezaga,
        ]);
    }

    public function destroy(RezagaEmpaque $rezaga): JsonResponse
    {
        $rezaga->delete();

        return response()->json(['success' => true, 'message' => 'Rezaga eliminada']);
    }

    private function appendCantidadHistorica(RezagaEmpaque $rezaga): RezagaEmpaque
    {
        $consumidoEnSalidas = (float) $rezaga->ventaDetalles->sum('peso_kg');
        $cantidadActual = (float) $rezaga->cantidad_kg;

        // Histórico independiente de salidas: cantidad antes de descontar salidas.
        $rezaga->setAttribute('cantidad_historica_kg', round($cantidadActual + $consumidoEnSalidas, 2));
        $rezaga->anotarHistoricaBins();

        return $rezaga;
    }

    private function generarFolio(array $data): string
    {
        $entityId = (int) $data['entity_id'];
        $entityPad = str_pad((string) $entityId, 2, '0', STR_PAD_LEFT);
        $prefix = "REZ-{$entityPad}-";

        // Constraint unique global: el contador es por entity_id (no por temporada).
        $lastFolio = RezagaEmpaque::withTrashed()
            ->where('entity_id', $entityId)
            ->where('folio_rezaga', 'like', "{$prefix}%")
            ->orderByDesc('folio_rezaga')
            ->value('folio_rezaga');

        $nextNum = 1;
        if ($lastFolio) {
            $nextNum = (int) str_replace($prefix, '', $lastFolio) + 1;
        }

        // Retry hasta 5 veces ante race conditions
        for ($i = 0; $i < 5; $i++) {
            $candidate = $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
            $exists = RezagaEmpaque::withTrashed()->where('folio_rezaga', $candidate)->exists();
            if (!$exists) {
                return $candidate;
            }
            $nextNum++;
        }

        return $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }

    /**
     * GET /rezaga/pendientes — Folios que completaron etapas sin registrar rezaga
        * Returns procesos that went through lavado/produccion but have no rezaga for that stage
     */
    public function pendientesRezaga(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'nullable|exists:entities,id',
        ]);

        $temporadaId = $request->temporada_id;
        $entityId = $request->entity_id;

        $eagerLoad = [
            'productor:id,nombre,apellido',
            'lote:id,nombre,numero_lote',
            'etapa:id,nombre,variedad_id',
            'etapa.variedad:id,nombre,cultivo_id',
            'etapa.variedad.cultivo:id,nombre',
            'recepcion:id,salida_campo_id,folio_recepcion,cantidad_recibida,lote_producto_terminado',
            'recepcion.salidaCampo:id,variedad_id,folio_salida,cantidad',
            'recepcion.salidaCampo.variedad:id,nombre',
            'tipoCarga:id,nombre',
            'rezagas',
        ];

        // Procesos that passed through lavado (have fecha_lavado) but no rezaga tipo=lavado
        $sinRezagaLavado = ProcesoEmpaque::with($eagerLoad)
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->whereNotNull('fecha_lavado')
            ->whereDoesntHave('rezagas', fn($q) => $q->where('tipo_rezaga', 'lavado'))
            ->get()
            ->map(fn(ProcesoEmpaque $p) => array_merge($p->toArray(), ['rezaga_pendiente_tipo' => 'lavado']));

        // Procesos cerrados (procesado) without rezaga tipo=produccion
        $sinRezagaProduccion = ProcesoEmpaque::with($eagerLoad)
            ->where('temporada_id', $temporadaId)
            ->when($entityId, fn($q) => $q->where('entity_id', $entityId))
            ->where('status', 'procesado')
            ->whereDoesntHave('rezagas', fn($q) => $q->where('tipo_rezaga', 'produccion'))
            ->get()
            ->map(fn(ProcesoEmpaque $p) => array_merge($p->toArray(), ['rezaga_pendiente_tipo' => 'produccion']));

        $pendientes = $sinRezagaLavado->merge($sinRezagaProduccion)
            ->unique(fn($item) => $item['id'] . '-' . $item['rezaga_pendiente_tipo'])
            ->values();

        return response()->json(['success' => true, 'data' => $pendientes]);
    }
}
