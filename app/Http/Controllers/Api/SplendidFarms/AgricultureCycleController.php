<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Http\Controllers\Controller;
use App\Models\CicloAgricola;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgricultureCycleController extends Controller
{
    /**
     * Listar todos los ciclos agrícolas
     */
    public function index()
    {
        $ciclos = CicloAgricola::with(['usuario:id,name,email', 'cultivo:id,nombre,imagen'])
            ->orderBy('año', 'desc')
            ->orderBy('fecha_inicio', 'desc')
            ->get()
            ->map(function ($ciclo) {
                if ($ciclo->cultivo && $ciclo->cultivo->imagen) {
                    $ciclo->cultivo->imagen_url = asset('storage/' . $ciclo->cultivo->imagen);
                }
                return $ciclo;
            });

        return response()->json([
            'success' => true,
            'data' => $ciclos,
        ]);
    }

    /**
     * Crear un nuevo ciclo agrícola
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cultivo_id' => 'required|exists:cultivos,id',
            'periodo' => 'required|in:primavera-verano,otoño-invierno,todo-el-año',
            'año' => 'required|integer|min:2000|max:2100',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'estado' => 'required|in:planificado,activo,finalizado,cancelado',
        ], [
            'cultivo_id.required' => 'El cultivo es requerido',
            'cultivo_id.exists' => 'El cultivo seleccionado no existe',
            'periodo.required' => 'El período es requerido',
            'periodo.in' => 'El período debe ser: primavera-verano, otoño-invierno o todo-el-año',
            'año.required' => 'El año es requerido',
            'año.integer' => 'El año debe ser un número entero',
            'año.min' => 'El año debe ser mayor o igual a 2000',
            'año.max' => 'El año debe ser menor o igual a 2100',
            'fecha_inicio.required' => 'La fecha de inicio es requerida',
            'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida',
            'fecha_fin.date' => 'La fecha de fin debe ser una fecha válida',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio',
            'estado.required' => 'El estado es requerido',
            'estado.in' => 'El estado debe ser: planificado, activo, finalizado o cancelado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Construir nombre automáticamente
        $cultivo = \App\Models\Cultivo::find($request->cultivo_id);
        $nombre = $cultivo->nombre . ' ' . ucwords(str_replace('-', ' ', $request->periodo)) . ' ' . $request->año;

        $ciclo = CicloAgricola::create([
            'cultivo_id' => $request->cultivo_id,
            'periodo' => $request->periodo,
            'nombre' => $nombre,
            'año' => $request->año,
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'estado' => $request->estado,
            'user_id' => auth()->id(),
        ]);

        $ciclo->load(['usuario:id,name,email', 'cultivo:id,nombre,imagen']);
        
        if ($ciclo->cultivo && $ciclo->cultivo->imagen) {
            $ciclo->cultivo->imagen_url = asset('storage/' . $ciclo->cultivo->imagen);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ciclo agrícola creado exitosamente',
            'data' => $ciclo,
        ], 201);
    }

    /**
     * Mostrar un ciclo agrícola específico
     */
    public function show($id)
    {
        $ciclo = CicloAgricola::with(['usuario:id,name,email', 'cultivo:id,nombre,imagen'])->find($id);

        if (!$ciclo) {
            return response()->json([
                'success' => false,
                'message' => 'Ciclo agrícola no encontrado',
            ], 404);
        }

        if ($ciclo->cultivo && $ciclo->cultivo->imagen) {
            $ciclo->cultivo->imagen_url = asset('storage/' . $ciclo->cultivo->imagen);
        }

        return response()->json([
            'success' => true,
            'data' => $ciclo,
        ]);
    }

    /**
     * Actualizar un ciclo agrícola
     */
    public function update(Request $request, $id)
    {
        $ciclo = CicloAgricola::find($id);

        if (!$ciclo) {
            return response()->json([
                'success' => false,
                'message' => 'Ciclo agrícola no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'cultivo_id' => 'required|exists:cultivos,id',
            'periodo' => 'required|in:primavera-verano,otoño-invierno,todo-el-año',
            'año' => 'required|integer|min:2000|max:2100',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'estado' => 'required|in:planificado,activo,finalizado,cancelado',
        ], [
            'cultivo_id.required' => 'El cultivo es requerido',
            'cultivo_id.exists' => 'El cultivo seleccionado no existe',
            'periodo.required' => 'El período es requerido',
            'periodo.in' => 'El período debe ser: primavera-verano, otoño-invierno o todo-el-año',
            'año.required' => 'El año es requerido',
            'año.integer' => 'El año debe ser un número entero',
            'año.min' => 'El año debe ser mayor o igual a 2000',
            'año.max' => 'El año debe ser menor o igual a 2100',
            'fecha_inicio.required' => 'La fecha de inicio es requerida',
            'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida',
            'fecha_fin.date' => 'La fecha de fin debe ser una fecha válida',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio',
            'estado.required' => 'El estado es requerido',
            'estado.in' => 'El estado debe ser: planificado, activo, finalizado o cancelado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Construir nombre automáticamente
        $cultivo = \App\Models\Cultivo::find($request->cultivo_id);
        $nombre = $cultivo->nombre . ' ' . ucwords(str_replace('-', ' ', $request->periodo)) . ' ' . $request->año;

        $ciclo->update([
            'cultivo_id' => $request->cultivo_id,
            'periodo' => $request->periodo,
            'nombre' => $nombre,
            'año' => $request->año,
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'estado' => $request->estado,
        ]);

        $ciclo->load(['usuario:id,name,email', 'cultivo:id,nombre,imagen']);
        
        if ($ciclo->cultivo && $ciclo->cultivo->imagen) {
            $ciclo->cultivo->imagen_url = asset('storage/' . $ciclo->cultivo->imagen);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ciclo agrícola actualizado exitosamente',
            'data' => $ciclo,
        ]);
    }

    /**
     * Eliminar un ciclo agrícola (soft delete)
     */
    public function destroy($id)
    {
        $ciclo = CicloAgricola::find($id);

        if (!$ciclo) {
            return response()->json([
                'success' => false,
                'message' => 'Ciclo agrícola no encontrado',
            ], 404);
        }

        $ciclo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ciclo agrícola eliminado exitosamente',
        ]);
    }
}
