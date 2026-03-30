<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Http\Controllers\Controller;
use App\Models\Calibre;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CalibreController extends Controller
{
    /**
     * Display a listing of calibres.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Calibre::with(['cultivo:id,nombre', 'variedad:id,nombre,cultivo_id']);

            if ($request->filled('cultivo_id')) {
                $query->byCultivo($request->cultivo_id);
            }

            if ($request->filled('variedad_id')) {
                $query->byVariedad($request->variedad_id);
            }

            if ($request->boolean('active_only')) {
                $query->active();
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                      ->orWhere('valor', 'like', "%{$search}%")
                      ->orWhere('descripcion', 'like', "%{$search}%");
                });
            }

            $calibres = $query->orderBy('order')->orderBy('valor')->get();

            return response()->json([
                'success' => true,
                'data' => $calibres,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener calibres: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los calibres',
            ], 500);
        }
    }

    /**
     * Lista simplificada para selects.
     */
    public function list(Request $request): JsonResponse
    {
        $query = Calibre::active()
            ->select('id', 'cultivo_id', 'variedad_id', 'nombre', 'valor');

        if ($request->filled('cultivo_id')) {
            $query->byCultivo($request->cultivo_id);
        }

        if ($request->filled('variedad_id')) {
            $query->byVariedad($request->variedad_id);
        }

        $calibres = $query->orderBy('order')->orderBy('valor')->get();

        return response()->json([
            'success' => true,
            'data' => $calibres,
        ]);
    }

    /**
     * Store a newly created calibre.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cultivo_id' => 'required|exists:cultivos,id',
                'variedad_id' => 'nullable|exists:variedades,id',
                'nombre' => 'required|string|max:50',
                'valor' => 'required|string|max:30',
                'descripcion' => 'nullable|string',
                'is_active' => 'boolean',
                'order' => 'nullable|integer|min:0',
            ]);

            $calibre = Calibre::create($validated);
            $calibre->load(['cultivo:id,nombre', 'variedad:id,nombre,cultivo_id']);

            return response()->json([
                'success' => true,
                'message' => 'Calibre creado exitosamente',
                'data' => $calibre,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al crear calibre: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el calibre',
            ], 500);
        }
    }

    /**
     * Display the specified calibre.
     */
    public function show(Calibre $calibre): JsonResponse
    {
        $calibre->load(['cultivo:id,nombre', 'variedad:id,nombre,cultivo_id']);

        return response()->json([
            'success' => true,
            'data' => $calibre,
        ]);
    }

    /**
     * Update the specified calibre.
     */
    public function update(Request $request, Calibre $calibre): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cultivo_id' => 'sometimes|exists:cultivos,id',
                'variedad_id' => 'nullable|exists:variedades,id',
                'nombre' => 'sometimes|string|max:50',
                'valor' => 'sometimes|string|max:30',
                'descripcion' => 'nullable|string',
                'is_active' => 'boolean',
                'order' => 'nullable|integer|min:0',
            ]);

            $calibre->update($validated);
            $calibre->load(['cultivo:id,nombre', 'variedad:id,nombre,cultivo_id']);

            return response()->json([
                'success' => true,
                'message' => 'Calibre actualizado exitosamente',
                'data' => $calibre,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al actualizar calibre: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el calibre',
            ], 500);
        }
    }

    /**
     * Remove the specified calibre.
     */
    public function destroy(Calibre $calibre): JsonResponse
    {
        try {
            $calibre->delete();

            return response()->json([
                'success' => true,
                'message' => 'Calibre eliminado exitosamente',
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar calibre: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el calibre',
            ], 500);
        }
    }
}
