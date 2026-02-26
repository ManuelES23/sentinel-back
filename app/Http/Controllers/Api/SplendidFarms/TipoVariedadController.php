<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Http\Controllers\Controller;
use App\Models\TipoVariedad;
use App\Models\Variedad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TipoVariedadController extends Controller
{
    /**
     * Display a listing of tipos de variedad.
     */
    public function index(Request $request)
    {
        try {
            $query = TipoVariedad::with(['variedad.cultivo', 'usuario']);

            // Filtros opcionales
            if ($request->filled('variedad_id')) {
                $query->where('variedad_id', $request->variedad_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre', 'like', '%' . $search . '%')
                      ->orWhere('descripcion', 'like', '%' . $search . '%');
                });
            }

            $tiposVariedad = $query->orderBy('created_at', 'desc')->get();

            // Agregar imagen del cultivo a través de la variedad
            $tiposVariedad->each(function ($tipo) {
                if ($tipo->variedad && $tipo->variedad->cultivo) {
                    $tipo->variedad->cultivo->imagen_url;
                }
            });

            return response()->json([
                'success' => true,
                'data' => $tiposVariedad,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener tipos de variedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los tipos de variedad',
            ], 500);
        }
    }

    /**
     * Store a newly created tipo de variedad.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'variedad_id' => 'required|exists:variedades,id',
                'nombre' => 'required|string|max:255',
                'descripcion' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $tipoVariedad = TipoVariedad::create([
                'variedad_id' => $request->variedad_id,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
                'user_id' => auth()->id(),
            ]);

            $tipoVariedad->load(['variedad.cultivo', 'usuario']);

            return response()->json([
                'success' => true,
                'message' => 'Tipo de variedad creado exitosamente',
                'data' => $tipoVariedad,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear tipo de variedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el tipo de variedad',
            ], 500);
        }
    }

    /**
     * Display the specified tipo de variedad.
     */
    public function show($id)
    {
        try {
            $tipoVariedad = TipoVariedad::with(['variedad.cultivo', 'usuario'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $tipoVariedad,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener tipo de variedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Tipo de variedad no encontrado',
            ], 404);
        }
    }

    /**
     * Update the specified tipo de variedad.
     */
    public function update(Request $request, $id)
    {
        try {
            $tipoVariedad = TipoVariedad::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'variedad_id' => 'required|exists:variedades,id',
                'nombre' => 'required|string|max:255',
                'descripcion' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $tipoVariedad->update([
                'variedad_id' => $request->variedad_id,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
            ]);

            $tipoVariedad->load(['variedad.cultivo', 'usuario']);

            return response()->json([
                'success' => true,
                'message' => 'Tipo de variedad actualizado exitosamente',
                'data' => $tipoVariedad,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al actualizar tipo de variedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el tipo de variedad',
            ], 500);
        }
    }

    /**
     * Remove the specified tipo de variedad.
     */
    public function destroy($id)
    {
        try {
            $tipoVariedad = TipoVariedad::findOrFail($id);
            $tipoVariedad->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tipo de variedad eliminado exitosamente',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al eliminar tipo de variedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el tipo de variedad',
            ], 500);
        }
    }
}
