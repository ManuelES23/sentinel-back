<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\ConvenioCompraUpdated;
use App\Http\Controllers\Controller;
use App\Models\ConvenioCompra;
use App\Models\ConvenioCompraPrecio;
use App\Models\Temporada;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class ConvenioCompraController extends Controller
{
    private array $eagerLoad = [
        'temporada:id,nombre,cultivo_id',
        'temporada.cultivo:id,nombre',
        'productor:id,nombre,apellido,tipo',
        'cultivo:id,nombre',
        'variedad:id,nombre',
        'creador:id,name',
        'precios.tipoCarga:id,nombre',
    ];

    /**
     * Listar convenios de compra con filtros
     */
    public function index(Request $request): JsonResponse
    {
        $query = ConvenioCompra::with($this->eagerLoad)
            ->withCount('precios');

        if ($request->filled('temporada_id')) {
            $query->porTemporada($request->temporada_id);
        }
        if ($request->filled('productor_id')) {
            $query->porProductor($request->productor_id);
        }
        if ($request->filled('cultivo_id')) {
            $query->porCultivo($request->cultivo_id);
        }
        if ($request->filled('modalidad')) {
            $query->porModalidad($request->modalidad);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $convenios = $query->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $convenios,
        ]);
    }

    /**
     * Crear convenio de compra
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'productor_id' => 'required|exists:productores,id',
            'variedad_id' => 'nullable|exists:variedades,id',
            'modalidad' => 'required|in:compra_directa,consignacion',
            'calculo_por_kilos' => 'nullable|boolean',
            'status' => 'sometimes|in:borrador,activo,suspendido,finalizado',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'porcentaje_rezaga' => 'nullable|numeric|min:0|max:100',
            'notas' => 'nullable|string|max:2000',
            // Precios iniciales (opcional al crear)
            'precios' => 'nullable|array',
            'precios.*.tipo_carga_id' => 'nullable|exists:tipos_carga,id',
            'precios.*.precio_unitario' => 'nullable|numeric|min:0',
            'precios.*.precio_caja_empacada' => 'nullable|numeric|min:0',
            'precios.*.porcentaje_productor' => 'nullable|numeric|min:0|max:100',
            'precios.*.vigencia_inicio' => 'required_with:precios|date',
            'precios.*.vigencia_fin' => 'nullable|date',
            'precios.*.notas' => 'nullable|string|max:500',
        ]);

        // Auto-fill cultivo_id from temporada
        $temporada = Temporada::findOrFail($validated['temporada_id']);
        $validated['cultivo_id'] = $temporada->cultivo_id;

        $duplicado = $this->findDuplicateConvenio($validated);
        if ($duplicado) {
            if ($duplicado->deleted_at) {
                return $this->restoreDuplicateConvenio($duplicado, $validated);
            }

            return response()->json([
                'status' => 'error',
                'message' => "Ya existe un convenio con esta combinación y fecha de inicio: {$duplicado->folio_convenio}.",
            ], 422);
        }

        // Generar folio
        $validated['folio_convenio'] = $this->generarFolio();
        $validated['created_by'] = Auth::id();

        // Extraer precios antes de crear
        $preciosData = $validated['precios'] ?? [];
        unset($validated['precios']);

        try {
            $convenio = ConvenioCompra::create($validated);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $duplicado = $this->findDuplicateConvenio($validated);
                if ($duplicado && $duplicado->deleted_at) {
                    return $this->restoreDuplicateConvenio($duplicado, $validated);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => $duplicado
                        ? "Ya existe un convenio con esta combinación y fecha de inicio: {$duplicado->folio_convenio}."
                        : 'Ya existe un convenio con la misma combinación de productor, cultivo, variedad, modalidad y fecha de inicio.',
                ], 422);
            }

            throw $e;
        }

        // Crear precios iniciales
        foreach ($preciosData as $precio) {
            $convenio->precios()->create($precio);
        }

        $convenio->load($this->eagerLoad);
        $convenio->loadCount('precios');

        broadcast(new ConvenioCompraUpdated('created', $convenio->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Convenio de compra creado exitosamente',
            'data' => $convenio,
        ], 201);
    }

    /**
     * Mostrar detalle de convenio
     */
    public function show(ConvenioCompra $convenio): JsonResponse
    {
        $convenio->load($this->eagerLoad);
        $convenio->loadCount('precios');

        return response()->json([
            'success' => true,
            'data' => $convenio,
        ]);
    }

    /**
     * Actualizar convenio
     */
    public function update(Request $request, ConvenioCompra $convenio): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'sometimes|exists:temporadas,id',
            'productor_id' => 'sometimes|exists:productores,id',
            'variedad_id' => 'nullable|exists:variedades,id',
            'modalidad' => 'sometimes|in:compra_directa,consignacion',
            'calculo_por_kilos' => 'nullable|boolean',
            'status' => 'sometimes|in:borrador,activo,suspendido,finalizado',
            'fecha_inicio' => 'sometimes|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'porcentaje_rezaga' => 'nullable|numeric|min:0|max:100',
            'notas' => 'nullable|string|max:2000',
        ]);

        // Auto-fill cultivo_id if temporada changed
        if (isset($validated['temporada_id'])) {
            $temporada = Temporada::findOrFail($validated['temporada_id']);
            $validated['cultivo_id'] = $temporada->cultivo_id;
        }

        $candidate = array_merge([
            'temporada_id' => $convenio->temporada_id,
            'productor_id' => $convenio->productor_id,
            'variedad_id' => $convenio->variedad_id,
            'modalidad' => $convenio->modalidad,
            'fecha_inicio' => optional($convenio->fecha_inicio)->format('Y-m-d'),
            'cultivo_id' => $convenio->cultivo_id,
        ], $validated);

        $duplicado = $this->findDuplicateConvenio($candidate, $convenio->id);
        if ($duplicado) {
            return response()->json([
                'status' => 'error',
                'message' => "Ya existe un convenio con esta combinación y fecha de inicio: {$duplicado->folio_convenio}.",
            ], 422);
        }

        try {
            $convenio->update($validated);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $duplicado = $this->findDuplicateConvenio($candidate, $convenio->id);
                return response()->json([
                    'status' => 'error',
                    'message' => $duplicado
                        ? "Ya existe un convenio con esta combinación y fecha de inicio: {$duplicado->folio_convenio}."
                        : 'Ya existe un convenio con la misma combinación de productor, cultivo, variedad, modalidad y fecha de inicio.',
                ], 422);
            }

            throw $e;
        }
        $convenio = $convenio->fresh();
        $convenio->load($this->eagerLoad);
        $convenio->loadCount('precios');

        broadcast(new ConvenioCompraUpdated('updated', $convenio->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Convenio actualizado exitosamente',
            'data' => $convenio,
        ]);
    }

    /**
     * Eliminar convenio
     */
    public function destroy(ConvenioCompra $convenio): JsonResponse
    {
        $data = $convenio->toArray();
        $convenio->delete();

        broadcast(new ConvenioCompraUpdated('deleted', $data));

        return response()->json([
            'success' => true,
            'message' => 'Convenio eliminado exitosamente',
        ]);
    }

    /**
     * Lista simplificada para selects
     */
    public function list(Request $request): JsonResponse
    {
        $query = ConvenioCompra::activos()
            ->select('id', 'folio_convenio', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad')
            ->with([
                'productor:id,nombre,apellido',
                'cultivo:id,nombre',
                'variedad:id,nombre',
            ]);

        if ($request->filled('temporada_id')) {
            $query->porTemporada($request->temporada_id);
        }
        if ($request->filled('productor_id')) {
            $query->porProductor($request->productor_id);
        }
        if ($request->filled('cultivo_id')) {
            $query->porCultivo($request->cultivo_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('folio_convenio')->get(),
        ]);
    }

    // ─── Sub-recurso: Precios ─────────────────────────

    /**
     * Agregar precio a convenio
     */
    public function agregarPrecio(Request $request, ConvenioCompra $convenio): JsonResponse
    {
        $validated = $request->validate([
            'tipo_carga_id' => 'nullable|exists:tipos_carga,id',
            'precio_unitario' => 'nullable|numeric|min:0',
            'precio_caja_empacada' => 'nullable|numeric|min:0',
            'porcentaje_productor' => 'nullable|numeric|min:0|max:100',
            'vigencia_inicio' => 'required|date',
            'vigencia_fin' => 'nullable|date|after_or_equal:vigencia_inicio',
            'notas' => 'nullable|string|max:500',
        ]);

        $precio = $convenio->precios()->create($validated);
        $precio->load('tipoCarga:id,nombre');

        broadcast(new ConvenioCompraUpdated('updated', $convenio->fresh()->load($this->eagerLoad)->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Precio agregado exitosamente',
            'data' => $precio,
        ], 201);
    }

    /**
     * Actualizar precio
     */
    public function actualizarPrecio(Request $request, ConvenioCompra $convenio, ConvenioCompraPrecio $precio): JsonResponse
    {
        $validated = $request->validate([
            'tipo_carga_id' => 'nullable|exists:tipos_carga,id',
            'precio_unitario' => 'nullable|numeric|min:0',
            'precio_caja_empacada' => 'nullable|numeric|min:0',
            'porcentaje_productor' => 'nullable|numeric|min:0|max:100',
            'vigencia_inicio' => 'sometimes|date',
            'vigencia_fin' => 'nullable|date|after_or_equal:vigencia_inicio',
            'is_active' => 'sometimes|boolean',
            'notas' => 'nullable|string|max:500',
        ]);

        $precio->update($validated);
        $precio->load('tipoCarga:id,nombre');

        broadcast(new ConvenioCompraUpdated('updated', $convenio->fresh()->load($this->eagerLoad)->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Precio actualizado exitosamente',
            'data' => $precio,
        ]);
    }

    /**
     * Eliminar precio
     */
    public function eliminarPrecio(ConvenioCompra $convenio, ConvenioCompraPrecio $precio): JsonResponse
    {
        $precio->delete();

        broadcast(new ConvenioCompraUpdated('updated', $convenio->fresh()->load($this->eagerLoad)->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Precio eliminado exitosamente',
        ]);
    }

    /**
     * Consultar precio vigente de un convenio
     */
    public function precioVigente(Request $request, ConvenioCompra $convenio): JsonResponse
    {
        $tipoCargaId = $request->input('tipo_carga_id');
        $fecha = $request->input('fecha', now()->toDateString());

        $precio = $convenio->precioVigente($tipoCargaId, $fecha);

        return response()->json([
            'success' => true,
            'data' => $precio,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────

    private function generarFolio(): string
    {
        $lastCode = ConvenioCompra::withTrashed()
            ->where('folio_convenio', 'like', 'CON-%')
            ->orderByDesc('folio_convenio')
            ->value('folio_convenio');

        $nextNumber = $lastCode ? (int) substr($lastCode, 4) + 1 : 1;

        return 'CON-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    private function findDuplicateConvenio(array $data, ?int $ignoreId = null): ?ConvenioCompra
    {
        if (
            empty($data['temporada_id']) ||
            empty($data['productor_id']) ||
            empty($data['cultivo_id']) ||
            empty($data['modalidad']) ||
            empty($data['fecha_inicio'])
        ) {
            return null;
        }

        $fechaInicio = Carbon::parse($data['fecha_inicio'])->toDateString();

        $query = ConvenioCompra::withTrashed()
            ->where('temporada_id', $data['temporada_id'])
            ->where('productor_id', $data['productor_id'])
            ->where('cultivo_id', $data['cultivo_id'])
            ->where('modalidad', $data['modalidad'])
            ->whereDate('fecha_inicio', $fechaInicio)
            ->when(
                array_key_exists('variedad_id', $data) && !empty($data['variedad_id']),
                fn ($q) => $q->where('variedad_id', $data['variedad_id']),
                fn ($q) => $q->whereNull('variedad_id')
            );

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->first();
    }

    private function restoreDuplicateConvenio(ConvenioCompra $convenio, array $validated): JsonResponse
    {
        $preciosData = $validated['precios'] ?? [];
        unset($validated['precios']);

        $convenio->restore();
        $convenio->update($validated);

        $convenio->precios()->delete();
        foreach ($preciosData as $precio) {
            $convenio->precios()->create($precio);
        }

        $convenio = $convenio->fresh();
        $convenio->load($this->eagerLoad);
        $convenio->loadCount('precios');

        broadcast(new ConvenioCompraUpdated('updated', $convenio->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Se restauró un convenio existente con la misma combinación.',
            'data' => $convenio,
        ], 200);
    }
}
