<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Cosecha;

use App\Http\Controllers\Controller;
use App\Models\CalidadCosecha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalidadCosechaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CalidadCosecha::with([
            'etapa:id,nombre,lote_id',
            'lote:id,nombre,numero_lote',
            'salidaCampo:id,fecha,producto,cantidad,unidad_medida',
            'creador:id,name',
        ]);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }

        if ($request->filled('lote_id')) {
            $query->where('lote_id', $request->lote_id);
        }

        if ($request->filled('etapa_id')) {
            $query->where('etapa_id', $request->etapa_id);
        }

        if ($request->filled('resultado')) {
            $query->byResultado($request->resultado);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('inspector', 'like', "%{$search}%")
                  ->orWhere('observaciones', 'like', "%{$search}%")
                  ->orWhere('tipo_inspeccion', 'like', "%{$search}%");
            });
        }

        $inspecciones = $query->orderByDesc('fecha_inspeccion')->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'data' => $inspecciones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'salida_campo_cosecha_id' => 'nullable|exists:salidas_campo_cosecha,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'lote_id' => 'nullable|exists:lotes,id',
            'fecha_inspeccion' => 'required|date',
            'tipo_inspeccion' => 'required|in:visual,laboratorio,campo',
            'parametros' => 'nullable|array',
            'resultado' => 'required|in:aprobado,rechazado,condicional',
            'porcentaje_calidad' => 'nullable|numeric|min:0|max:100',
            'observaciones' => 'nullable|string',
            'inspector' => 'nullable|string|max:150',
        ]);

        $validated['created_by'] = $request->user()->id;

        $calidad = CalidadCosecha::create($validated);
        $calidad->load([
            'etapa:id,nombre,lote_id',
            'lote:id,nombre,numero_lote',
            'salidaCampo:id,fecha,producto,cantidad,unidad_medida',
            'creador:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inspección de calidad registrada exitosamente',
            'data' => $calidad,
        ], 201);
    }

    public function show(CalidadCosecha $calidad): JsonResponse
    {
        $calidad->load([
            'etapa:id,nombre,lote_id',
            'lote:id,nombre,numero_lote',
            'salidaCampo',
            'creador:id,name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $calidad,
        ]);
    }

    public function update(Request $request, CalidadCosecha $calidad): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'sometimes|exists:temporadas,id',
            'salida_campo_cosecha_id' => 'nullable|exists:salidas_campo_cosecha,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'lote_id' => 'nullable|exists:lotes,id',
            'fecha_inspeccion' => 'sometimes|date',
            'tipo_inspeccion' => 'sometimes|in:visual,laboratorio,campo',
            'parametros' => 'nullable|array',
            'resultado' => 'sometimes|in:aprobado,rechazado,condicional',
            'porcentaje_calidad' => 'nullable|numeric|min:0|max:100',
            'observaciones' => 'nullable|string',
            'inspector' => 'nullable|string|max:150',
        ]);

        $calidad->update($validated);
        $calidad->load([
            'etapa:id,nombre,lote_id',
            'lote:id,nombre,numero_lote',
            'salidaCampo:id,fecha,producto,cantidad,unidad_medida',
            'creador:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Inspección de calidad actualizada',
            'data' => $calidad,
        ]);
    }

    public function destroy(CalidadCosecha $calidad): JsonResponse
    {
        $calidad->delete();

        return response()->json([
            'success' => true,
            'message' => 'Inspección de calidad eliminada',
        ]);
    }
}
