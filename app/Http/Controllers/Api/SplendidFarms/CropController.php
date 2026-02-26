<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Events\CultivoUpdated;
use App\Http\Controllers\Controller;
use App\Models\Cultivo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CropController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $cultivos = Cultivo::orderBy('created_at', 'desc')->get();
        
        // Agregar URL completa de la imagen
        $cultivos->each(function ($cultivo) {
            if ($cultivo->imagen) {
                $cultivo->imagen_url = asset('storage/' . $cultivo->imagen);
            } else {
                $cultivo->imagen_url = null;
            }
        });
        
        return response()->json([
            'success' => true,
            'data' => $cultivos
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'imagen' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
        ]);

        $data = [
            'nombre' => $validated['nombre'],
        ];

        // Guardar imagen si existe
        if ($request->hasFile('imagen')) {
            $path = $request->file('imagen')->store('cultivos', 'public');
            $data['imagen'] = $path;
        }

        $cultivo = Cultivo::create($data);

        // Agregar URL completa
        if ($cultivo->imagen) {
            $cultivo->imagen_url = asset('storage/' . $cultivo->imagen);
        }

        // Broadcast evento en tiempo real (sin toOthers para que todos reciban el evento)
        broadcast(new CultivoUpdated('created', $cultivo->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Cultivo creado exitosamente',
            'data' => $cultivo
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Cultivo $cultivo): JsonResponse
    {
        if ($cultivo->imagen) {
            $cultivo->imagen_url = asset('storage/' . $cultivo->imagen);
        }
        
        return response()->json([
            'success' => true,
            'data' => $cultivo
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Cultivo $cultivo): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'imagen' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
        ]);

        $data = [];

        if (isset($validated['nombre'])) {
            $data['nombre'] = $validated['nombre'];
        }

        // Actualizar imagen si existe
        if ($request->hasFile('imagen')) {
            // Eliminar imagen anterior si existe
            if ($cultivo->imagen) {
                Storage::disk('public')->delete($cultivo->imagen);
            }
            
            $path = $request->file('imagen')->store('cultivos', 'public');
            $data['imagen'] = $path;
        }

        $cultivo->update($data);

        // Agregar URL completa
        if ($cultivo->imagen) {
            $cultivo->imagen_url = asset('storage/' . $cultivo->imagen);
        }

        // Broadcast evento en tiempo real
        broadcast(new CultivoUpdated('updated', $cultivo->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Cultivo actualizado exitosamente',
            'data' => $cultivo
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Cultivo $cultivo): JsonResponse
    {
        $cultivoData = $cultivo->toArray();
        
        // Eliminar imagen si existe
        if ($cultivo->imagen) {
            Storage::disk('public')->delete($cultivo->imagen);
        }
        
        $cultivo->delete();

        // Broadcast evento en tiempo real
        broadcast(new CultivoUpdated('deleted', $cultivoData));

        return response()->json([
            'success' => true,
            'message' => 'Cultivo eliminado exitosamente'
        ]);
    }
}
