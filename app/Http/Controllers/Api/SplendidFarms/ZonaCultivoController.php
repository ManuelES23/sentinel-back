<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Events\ZonaCultivoUpdated;
use App\Http\Controllers\Controller;
use App\Models\ZonaCultivo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ZonaCultivoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ZonaCultivo::with('lotes');

        // Filtrar por búsqueda si se especifica
        if ($request->has('search')) {
            $query->where('nombre', 'like', '%' . $request->search . '%');
        }

        $zonas = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'success' => true,
            'data' => $zonas
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'ubicacion' => 'nullable|string|max:500',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $zona = ZonaCultivo::create($validated);
        $zona->load('lotes');

        broadcast(new ZonaCultivoUpdated('created', $zona->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Zona de cultivo creada exitosamente',
            'data' => $zona
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ZonaCultivo $zonas_cultivo): JsonResponse
    {
        $zonas_cultivo->load('lotes');
        
        return response()->json([
            'success' => true,
            'data' => $zonas_cultivo
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ZonaCultivo $zonas_cultivo): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'ubicacion' => 'nullable|string|max:500',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $zonas_cultivo->update($validated);
        $zonas_cultivo->load('lotes');

        broadcast(new ZonaCultivoUpdated('updated', $zonas_cultivo->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Zona de cultivo actualizada exitosamente',
            'data' => $zonas_cultivo
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ZonaCultivo $zonas_cultivo): JsonResponse
    {
        $zonaData = $zonas_cultivo->toArray();
        /** @phpstan-ignore-next-line */
        $zonas_cultivo->delete();

        broadcast(new ZonaCultivoUpdated('deleted', $zonaData));

        return response()->json([
            'success' => true,
            'message' => 'Zona de cultivo eliminada exitosamente'
        ]);
    }
}
