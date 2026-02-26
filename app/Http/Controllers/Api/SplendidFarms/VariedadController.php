<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Http\Controllers\Controller;
use App\Models\Variedad;
use App\Models\Cultivo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VariedadController extends Controller
{
    /**
     * Display a listing of variedades.
     */
    public function index(Request $request)
    {
        try {
            $query = Variedad::with(['cultivo', 'usuario']);

            // Filtros opcionales
            if ($request->filled('cultivo_id')) {
                $query->where('cultivo_id', $request->cultivo_id);
            }

            if ($request->filled('clasificacion')) {
                $query->where('clasificacion', $request->clasificacion);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre', 'like', '%' . $search . '%')
                      ->orWhere('descripcion', 'like', '%' . $search . '%');
                });
            }

            $variedades = $query->orderBy('created_at', 'desc')->get();

            // Agregar imagen_url de cultivo
            $variedades->each(function ($variedad) {
                if ($variedad->cultivo && $variedad->cultivo->imagen) {
                    $variedad->cultivo->imagen_url = asset('storage/' . $variedad->cultivo->imagen);
                }
            });

            return response()->json($variedades);
        } catch (\Exception $e) {
            Log::error('Error al listar variedades: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener las variedades',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created variedad.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'cultivo_id' => 'required|exists:cultivos,id',
                'nombre' => 'required|string|max:255',
                'descripcion' => 'nullable|string',
            ], [
                'cultivo_id.required' => 'El cultivo es requerido',
                'cultivo_id.exists' => 'El cultivo seleccionado no existe',
                'nombre.required' => 'El nombre de la variedad es requerido',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Crear la variedad
            $variedad = Variedad::create([
                'cultivo_id' => $request->cultivo_id,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
                'user_id' => auth()->id(),
            ]);

            // Cargar relaciones
            $variedad->load(['cultivo', 'usuario']);

            // Agregar imagen_url
            if ($variedad->cultivo && $variedad->cultivo->imagen) {
                $variedad->cultivo->imagen_url = asset('storage/' . $variedad->cultivo->imagen);
            }

            return response()->json([
                'message' => 'Variedad creada exitosamente',
                'variedad' => $variedad
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear variedad: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al crear la variedad',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified variedad.
     */
    public function show($id)
    {
        try {
            $variedad = Variedad::with(['cultivo', 'usuario'])->findOrFail($id);

            // Agregar imagen_url
            if ($variedad->cultivo && $variedad->cultivo->imagen) {
                $variedad->cultivo->imagen_url = asset('storage/' . $variedad->cultivo->imagen);
            }

            return response()->json($variedad);
        } catch (\Exception $e) {
            Log::error('Error al obtener variedad: ' . $e->getMessage());
            return response()->json([
                'message' => 'Variedad no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified variedad.
     */
    public function update(Request $request, $id)
    {
        try {
            $variedad = Variedad::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'cultivo_id' => 'required|exists:cultivos,id',
                'nombre' => 'required|string|max:255',
                'descripcion' => 'nullable|string',
            ], [
                'cultivo_id.required' => 'El cultivo es requerido',
                'cultivo_id.exists' => 'El cultivo seleccionado no existe',
                'nombre.required' => 'El nombre de la variedad es requerido',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Actualizar la variedad
            $variedad->update([
                'cultivo_id' => $request->cultivo_id,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
            ]);

            // Cargar relaciones
            $variedad->load(['cultivo', 'usuario']);

            // Agregar imagen_url
            if ($variedad->cultivo && $variedad->cultivo->imagen) {
                $variedad->cultivo->imagen_url = asset('storage/' . $variedad->cultivo->imagen);
            }

            return response()->json([
                'message' => 'Variedad actualizada exitosamente',
                'variedad' => $variedad
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar variedad: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al actualizar la variedad',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified variedad.
     */
    public function destroy($id)
    {
        try {
            $variedad = Variedad::findOrFail($id);
            $variedad->delete();

            return response()->json([
                'message' => 'Variedad eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar variedad: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al eliminar la variedad',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
