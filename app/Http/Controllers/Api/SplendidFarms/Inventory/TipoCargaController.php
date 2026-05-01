<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\TipoCarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TipoCargaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TipoCarga::with('cultivo:id,nombre');

        if ($request->filled('cultivo_id')) {
            $query->byCultivo($request->cultivo_id);
        }

        if (Schema::hasColumn('tipos_carga', 'is_active')) {
            if ($request->filled('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            } else {
                $query->active();
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('descripcion', 'like', "%{$search}%");
            });
        }

        $tipos = $query->orderBy('nombre')->get();

        return response()->json([
            'success' => true,
            'data' => $tipos,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cultivo_id' => 'required|exists:cultivos,id',
            'nombre' => 'required|string|max:100',
            'categoria_caja' => 'required|in:campo,empaque,hidrotermico',
            'peso_estimado_kg' => 'required|numeric|min:0.01',
            'descripcion' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $tipo = TipoCarga::create($validated);
        $tipo->load('cultivo:id,nombre');

        return response()->json([
            'success' => true,
            'message' => 'Tipo de carga creado exitosamente',
            'data' => $tipo,
        ], 201);
    }

    public function show(TipoCarga $tipoCarga): JsonResponse
    {
        $tipoCarga->load('cultivo:id,nombre');

        return response()->json([
            'success' => true,
            'data' => $tipoCarga,
        ]);
    }

    public function update(Request $request, TipoCarga $tipoCarga): JsonResponse
    {
        $validated = $request->validate([
            'cultivo_id' => 'sometimes|exists:cultivos,id',
            'nombre' => 'sometimes|string|max:100',
            'categoria_caja' => 'sometimes|in:campo,empaque,hidrotermico',
            'peso_estimado_kg' => 'sometimes|numeric|min:0.01',
            'descripcion' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $tipoCarga->update($validated);
        $tipoCarga->load('cultivo:id,nombre');

        return response()->json([
            'success' => true,
            'message' => 'Tipo de carga actualizado',
            'data' => $tipoCarga,
        ]);
    }

    public function destroy(TipoCarga $tipoCarga): JsonResponse
    {
        if ($tipoCarga->salidasCampo()->where('eliminado', false)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar: tiene salidas de campo asociadas',
            ], 422);
        }

        $tipoCarga->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipo de carga eliminado',
        ]);
    }
}
