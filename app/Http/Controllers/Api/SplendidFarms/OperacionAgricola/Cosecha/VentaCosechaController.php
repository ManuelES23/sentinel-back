<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Cosecha;

use App\Http\Controllers\Controller;
use App\Models\VentaCosecha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VentaCosechaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = VentaCosecha::with(['cierreCosecha:id,lote_id,fecha_inicio,status', 'creador:id,name']);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }

        if ($request->filled('cierre_cosecha_id')) {
            $query->where('cierre_cosecha_id', $request->cierre_cosecha_id);
        }

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('cliente', 'like', "%{$search}%")
                  ->orWhere('producto', 'like', "%{$search}%")
                  ->orWhere('factura', 'like', "%{$search}%");
            });
        }

        $ventas = $query->orderByDesc('fecha_venta')->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'data' => $ventas,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'cierre_cosecha_id' => 'nullable|exists:cierres_cosecha,id',
            'fecha_venta' => 'required|date',
            'cliente' => 'required|string|max:200',
            'producto' => 'required|string|max:150',
            'cantidad' => 'required|numeric|min:0.01',
            'unidad_medida' => 'required|string|max:50',
            'precio_unitario' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'moneda' => 'nullable|string|max:10',
            'factura' => 'nullable|string|max:100',
            'observaciones' => 'nullable|string',
            'status' => 'nullable|in:pendiente,facturada,pagada,cancelada',
        ]);

        $validated['created_by'] = $request->user()->id;

        $venta = VentaCosecha::create($validated);
        $venta->load(['cierreCosecha:id,lote_id,fecha_inicio,status', 'creador:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Venta de cosecha registrada exitosamente',
            'data' => $venta,
        ], 201);
    }

    public function show(VentaCosecha $venta): JsonResponse
    {
        $venta->load(['cierreCosecha:id,lote_id,fecha_inicio,status', 'creador:id,name']);

        return response()->json([
            'success' => true,
            'data' => $venta,
        ]);
    }

    public function update(Request $request, VentaCosecha $venta): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'sometimes|exists:temporadas,id',
            'cierre_cosecha_id' => 'nullable|exists:cierres_cosecha,id',
            'fecha_venta' => 'sometimes|date',
            'cliente' => 'sometimes|string|max:200',
            'producto' => 'sometimes|string|max:150',
            'cantidad' => 'sometimes|numeric|min:0.01',
            'unidad_medida' => 'sometimes|string|max:50',
            'precio_unitario' => 'sometimes|numeric|min:0',
            'total' => 'sometimes|numeric|min:0',
            'moneda' => 'nullable|string|max:10',
            'factura' => 'nullable|string|max:100',
            'observaciones' => 'nullable|string',
            'status' => 'nullable|in:pendiente,facturada,pagada,cancelada',
        ]);

        $venta->update($validated);
        $venta->load(['cierreCosecha:id,lote_id,fecha_inicio,status', 'creador:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Venta actualizada',
            'data' => $venta,
        ]);
    }

    public function destroy(VentaCosecha $venta): JsonResponse
    {
        $venta->delete();

        return response()->json([
            'success' => true,
            'message' => 'Venta eliminada',
        ]);
    }
}
