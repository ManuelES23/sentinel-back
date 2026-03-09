<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Temporada;
use App\Models\ZonaCultivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller simplificado de Zonas de Cultivo para Operación Agrícola.
 * Alta sencilla: nombre y ubicación.
 * Los datos completos se gestionan en Administración.
 */
class ZonaCultivoSimpleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ZonaCultivo::with('lotes:id,zona_cultivo_id,nombre,codigo,superficie');

        // Filtrar por temporada si se proporciona
        if ($request->filled('temporada_id')) {
            $temporada = Temporada::findOrFail($request->temporada_id);
            $zonaIds = $temporada->zonasCultivoActivas()->pluck('zonas_cultivo.id');
            $query->whereIn('id', $zonaIds);
        }

        if ($request->filled('search')) {
            $query->where('nombre', 'like', '%' . $request->search . '%');
        }

        $zonas = $query->orderBy('nombre')->get();

        return response()->json(['success' => true, 'data' => $zonas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'ubicacion' => 'nullable|string|max:500',
            'descripcion' => 'nullable|string',
            'temporada_id' => 'nullable|exists:temporadas,id',
        ]);

        $temporadaId = $validated['temporada_id'] ?? null;
        unset($validated['temporada_id']);

        $zona = ZonaCultivo::create($validated);
        $zona->load('lotes:id,zona_cultivo_id,nombre,codigo,superficie');

        // Vincular a la temporada si se proporcionó
        if ($temporadaId) {
            $temporada = Temporada::findOrFail($temporadaId);
            $temporada->asignarZonaCultivo($zona->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zona de cultivo registrada.',
            'data' => $zona,
        ], 201);
    }

    public function show(ZonaCultivo $zona): JsonResponse
    {
        $zona->load('lotes:id,zona_cultivo_id,nombre,codigo,superficie');

        return response()->json([
            'success' => true,
            'data' => $zona,
        ]);
    }

    public function update(Request $request, ZonaCultivo $zona): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'ubicacion' => 'nullable|string|max:500',
            'descripcion' => 'nullable|string',
        ]);

        $zona->update($validated);
        $zona->load('lotes:id,zona_cultivo_id,nombre,codigo,superficie');

        return response()->json([
            'success' => true,
            'message' => 'Zona de cultivo actualizada',
            'data' => $zona->fresh(),
        ]);
    }

    public function destroy(ZonaCultivo $zona): JsonResponse
    {
        $zona->delete();

        return response()->json([
            'success' => true,
            'message' => 'Zona de cultivo eliminada',
        ]);
    }
}
