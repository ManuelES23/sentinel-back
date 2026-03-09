<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Plaga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlagaController extends Controller
{
    /**
     * Listar plagas. Filtro opcional por tipo.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Plaga::query();

        if ($request->filled('tipo')) {
            $query->byTipo($request->tipo);
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $plagas = $query->orderBy('tipo')->orderBy('nombre')->get();

        return response()->json([
            'success' => true,
            'data' => $plagas,
        ]);
    }

    /**
     * Crear plaga.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:150',
            'nombre_cientifico' => 'nullable|string|max:200',
            'tipo' => 'required|in:insecto,hongo,bacteria,maleza,virus,nematodo,otro',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $plaga = Plaga::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plaga registrada exitosamente.',
            'data' => $plaga,
        ], 201);
    }

    /**
     * Ver detalle de plaga.
     */
    public function show(Plaga $plaga): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $plaga,
        ]);
    }

    /**
     * Actualizar plaga.
     */
    public function update(Request $request, Plaga $plaga): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:150',
            'nombre_cientifico' => 'nullable|string|max:200',
            'tipo' => 'sometimes|required|in:insecto,hongo,bacteria,maleza,virus,nematodo,otro',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $plaga->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plaga actualizada exitosamente.',
            'data' => $plaga->fresh(),
        ]);
    }

    /**
     * Eliminar plaga.
     */
    public function destroy(Plaga $plaga): JsonResponse
    {
        $plaga->delete();

        return response()->json([
            'success' => true,
            'message' => 'Plaga eliminada exitosamente.',
        ]);
    }
}
