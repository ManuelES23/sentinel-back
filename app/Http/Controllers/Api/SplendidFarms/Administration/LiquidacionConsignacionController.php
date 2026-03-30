<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\LiquidacionConsignacionUpdated;
use App\Http\Controllers\Controller;
use App\Models\ConvenioCompra;
use App\Models\LiquidacionConsignacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LiquidacionConsignacionController extends Controller
{
    private array $eagerLoad = [
        'convenioCompra:id,folio_convenio,modalidad,status',
        'convenioCompra.cultivo:id,nombre',
        'convenioCompra.variedad:id,nombre',
        'temporada:id,nombre',
        'productor:id,nombre,apellido,tipo',
        'creador:id,name',
        'detalles.salidaCampo:id,folio_salida,fecha,cantidad,peso_neto_kg',
        'detalles.tipoCarga:id,nombre',
    ];

    /**
     * Listar liquidaciones con filtros
     */
    public function index(Request $request): JsonResponse
    {
        $query = LiquidacionConsignacion::with($this->eagerLoad)
            ->withCount('detalles');

        if ($request->filled('convenio_compra_id')) {
            $query->porConvenio($request->convenio_compra_id);
        }
        if ($request->filled('temporada_id')) {
            $query->porTemporada($request->temporada_id);
        }
        if ($request->filled('productor_id')) {
            $query->porProductor($request->productor_id);
        }
        if ($request->filled('status')) {
            $query->porStatus($request->status);
        }

        $liquidaciones = $query->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $liquidaciones,
        ]);
    }

    /**
     * Crear liquidación de consignación
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'convenio_compra_id' => 'required|exists:convenios_compra,id',
            'periodo_inicio' => 'required|date',
            'periodo_fin' => 'required|date|after_or_equal:periodo_inicio',
            'monto_ajustado' => 'nullable|numeric|min:0',
            'motivo_ajuste' => 'nullable|required_with:monto_ajustado|string|max:1000',
            'notas' => 'nullable|string|max:2000',
            'status' => 'sometimes|in:borrador,revisada',
        ]);

        $convenio = ConvenioCompra::with('productor')->findOrFail($validated['convenio_compra_id']);

        // Verificar que el convenio es de consignación y está activo
        if ($convenio->modalidad !== ConvenioCompra::MODALIDAD_CONSIGNACION) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden liquidar convenios de consignación',
            ], 422);
        }

        if ($convenio->status !== ConvenioCompra::STATUS_ACTIVO) {
            return response()->json([
                'status' => 'error',
                'message' => 'El convenio debe estar activo para generar liquidaciones',
            ], 422);
        }

        // Crear la liquidación
        $liquidacion = LiquidacionConsignacion::create([
            'folio_liquidacion' => $this->generarFolio(),
            'convenio_compra_id' => $convenio->id,
            'temporada_id' => $convenio->temporada_id,
            'productor_id' => $convenio->productor_id,
            'periodo_inicio' => $validated['periodo_inicio'],
            'periodo_fin' => $validated['periodo_fin'],
            'notas' => $validated['notas'] ?? null,
            'status' => $validated['status'] ?? 'borrador',
            'created_by' => Auth::id(),
        ]);

        // Calcular desde las salidas vinculadas al convenio
        $liquidacion->calcularDesdeSalidas();

        // Aplicar ajuste manual si se proporcionó
        if (isset($validated['monto_ajustado'])) {
            $liquidacion->aplicarAjuste($validated['monto_ajustado'], $validated['motivo_ajuste'] ?? null);
        }

        $liquidacion->save();
        $liquidacion->load($this->eagerLoad);
        $liquidacion->loadCount('detalles');

        broadcast(new LiquidacionConsignacionUpdated('created', $liquidacion->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Liquidación generada exitosamente',
            'data' => $liquidacion,
        ], 201);
    }

    /**
     * Mostrar detalle de liquidación
     */
    public function show(LiquidacionConsignacion $liquidacion): JsonResponse
    {
        $liquidacion->load($this->eagerLoad);
        $liquidacion->loadCount('detalles');

        return response()->json([
            'success' => true,
            'data' => $liquidacion,
        ]);
    }

    /**
     * Actualizar liquidación (solo borrador/revisada)
     */
    public function update(Request $request, LiquidacionConsignacion $liquidacion): JsonResponse
    {
        if (!in_array($liquidacion->status, ['borrador', 'revisada'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden editar liquidaciones en borrador o revisadas',
            ], 422);
        }

        $validated = $request->validate([
            'periodo_inicio' => 'sometimes|date',
            'periodo_fin' => 'sometimes|date|after_or_equal:periodo_inicio',
            'monto_ajustado' => 'nullable|numeric|min:0',
            'motivo_ajuste' => 'nullable|required_with:monto_ajustado|string|max:1000',
            'notas' => 'nullable|string|max:2000',
            'status' => 'sometimes|in:borrador,revisada,aprobada,cancelada',
        ]);

        // Si cambió el período, recalcular
        $recalcular = false;
        if (isset($validated['periodo_inicio']) || isset($validated['periodo_fin'])) {
            $liquidacion->fill($validated);
            $recalcular = true;
        }

        if ($recalcular) {
            $liquidacion->calcularDesdeSalidas();
        }

        // Aplicar ajuste manual
        if (array_key_exists('monto_ajustado', $validated)) {
            if ($validated['monto_ajustado'] !== null) {
                $liquidacion->aplicarAjuste($validated['monto_ajustado'], $validated['motivo_ajuste'] ?? null);
            } else {
                // Limpiar ajuste, volver al calculado
                $liquidacion->monto_ajustado = null;
                $liquidacion->motivo_ajuste = null;
                $liquidacion->monto_final = $liquidacion->monto_productor_calculado;
            }
        }

        if (isset($validated['status'])) {
            $liquidacion->status = $validated['status'];
        }
        if (isset($validated['notas'])) {
            $liquidacion->notas = $validated['notas'];
        }

        $liquidacion->save();
        $liquidacion = $liquidacion->fresh();
        $liquidacion->load($this->eagerLoad);
        $liquidacion->loadCount('detalles');

        broadcast(new LiquidacionConsignacionUpdated('updated', $liquidacion->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Liquidación actualizada exitosamente',
            'data' => $liquidacion,
        ]);
    }

    /**
     * Eliminar liquidación (solo borrador)
     */
    public function destroy(LiquidacionConsignacion $liquidacion): JsonResponse
    {
        if ($liquidacion->status !== 'borrador') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden eliminar liquidaciones en borrador',
            ], 422);
        }

        $data = $liquidacion->toArray();
        $liquidacion->delete();

        broadcast(new LiquidacionConsignacionUpdated('deleted', $data));

        return response()->json([
            'success' => true,
            'message' => 'Liquidación eliminada exitosamente',
        ]);
    }

    /**
     * Recalcular liquidación desde salidas vigentes
     */
    public function recalcular(LiquidacionConsignacion $liquidacion): JsonResponse
    {
        if (!in_array($liquidacion->status, ['borrador', 'revisada'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden recalcular liquidaciones en borrador o revisadas',
            ], 422);
        }

        $liquidacion->calcularDesdeSalidas();

        // Mantener monto_ajustado si existía
        if ($liquidacion->monto_ajustado !== null) {
            $liquidacion->monto_final = $liquidacion->monto_ajustado;
        }

        $liquidacion->save();
        $liquidacion->load($this->eagerLoad);
        $liquidacion->loadCount('detalles');

        broadcast(new LiquidacionConsignacionUpdated('updated', $liquidacion->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Liquidación recalculada exitosamente',
            'data' => $liquidacion,
        ]);
    }

    /**
     * Lista simplificada para selects
     */
    public function list(Request $request): JsonResponse
    {
        $query = LiquidacionConsignacion::select('id', 'folio_liquidacion', 'convenio_compra_id', 'productor_id', 'status', 'monto_final')
            ->with(['productor:id,nombre,apellido', 'convenioCompra:id,folio_convenio']);

        if ($request->filled('convenio_compra_id')) {
            $query->porConvenio($request->convenio_compra_id);
        }
        if ($request->filled('temporada_id')) {
            $query->porTemporada($request->temporada_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('created_at')->get(),
        ]);
    }

    /**
     * Generar folio único incremental: LIQ-XXXXX
     */
    private function generarFolio(): string
    {
        $lastFolio = LiquidacionConsignacion::withTrashed()
            ->where('folio_liquidacion', 'like', 'LIQ-%')
            ->orderByDesc('folio_liquidacion')
            ->value('folio_liquidacion');

        $nextNumber = $lastFolio ? (int) substr($lastFolio, 4) + 1 : 1;

        return 'LIQ-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
