<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ApplicationController extends Controller
{
    /**
     * Obtener todas las aplicaciones
     */
    public function index(): JsonResponse
    {
        $applications = Application::with('enterprise')
            ->get()
            ->map(function ($app) {
                return [
                    'id' => $app->id,
                    'slug' => $app->slug,
                    'name' => $app->name,
                    'description' => $app->description,
                    'icon' => $app->icon,
                    'path' => $app->path,
                    'is_active' => $app->is_active,
                    'enterprise' => $app->enterprise ? [
                        'id' => $app->enterprise->id,
                        'name' => $app->enterprise->name,
                        'slug' => $app->enterprise->slug,
                    ] : null
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $applications
        ]);
    }

    /**
     * Crear una nueva aplicación
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'path' => 'nullable|string|max:100',
            'config' => 'nullable|array',
            'is_active' => 'nullable|boolean'
        ]);

        // Generar slug si no se proporciona
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Verificar que no exista el slug en la misma empresa
        $exists = Application::where('enterprise_id', $validated['enterprise_id'])
            ->where('slug', $validated['slug'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ya existe una aplicación con ese nombre/slug en esta empresa'
            ], 422);
        }

        // Valores por defecto
        $validated['icon'] = $validated['icon'] ?? 'Package';
        $validated['is_active'] = $validated['is_active'] ?? true;

        $application = Application::create($validated);
        $application->load('enterprise');

        return response()->json([
            'status' => 'success',
            'message' => 'Aplicación creada exitosamente',
            'data' => [
                'id' => $application->id,
                'slug' => $application->slug,
                'name' => $application->name,
                'description' => $application->description,
                'icon' => $application->icon,
                'path' => $application->path,
                'is_active' => $application->is_active,
                'enterprise' => $application->enterprise ? [
                    'id' => $application->enterprise->id,
                    'name' => $application->enterprise->name,
                    'slug' => $application->enterprise->slug,
                ] : null
            ]
        ], 201);
    }

    /**
     * Obtener una aplicación específica
     */
    public function show($id): JsonResponse
    {
        $application = Application::with('enterprise')->find($id);

        if (!$application) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aplicación no encontrada'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $application->id,
                'slug' => $application->slug,
                'name' => $application->name,
                'description' => $application->description,
                'icon' => $application->icon,
                'path' => $application->path,
                'is_active' => $application->is_active,
                'enterprise' => $application->enterprise ? [
                    'id' => $application->enterprise->id,
                    'name' => $application->enterprise->name,
                    'slug' => $application->enterprise->slug,
                ] : null
            ]
        ]);
    }

    /**
     * Actualizar una aplicación
     */
    public function update(Request $request, $id): JsonResponse
    {
        $application = Application::find($id);

        if (!$application) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aplicación no encontrada'
            ], 404);
        }

        $validated = $request->validate([
            'enterprise_id' => 'sometimes|exists:enterprises,id',
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:50',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'path' => 'nullable|string|max:100',
            'config' => 'nullable|array',
            'is_active' => 'nullable|boolean'
        ]);

        $application->update($validated);
        $application->load('enterprise');

        return response()->json([
            'status' => 'success',
            'message' => 'Aplicación actualizada exitosamente',
            'data' => [
                'id' => $application->id,
                'slug' => $application->slug,
                'name' => $application->name,
                'description' => $application->description,
                'icon' => $application->icon,
                'path' => $application->path,
                'is_active' => $application->is_active,
                'enterprise' => $application->enterprise ? [
                    'id' => $application->enterprise->id,
                    'name' => $application->enterprise->name,
                    'slug' => $application->enterprise->slug,
                ] : null
            ]
        ]);
    }

    /**
     * Eliminar una aplicación
     */
    public function destroy($id): JsonResponse
    {
        $application = Application::find($id);

        if (!$application) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aplicación no encontrada'
            ], 404);
        }

        // Verificar si tiene módulos
        if ($application->modules()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar la aplicación porque tiene módulos asociados'
            ], 422);
        }

        $application->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Aplicación eliminada exitosamente'
        ]);
    }
}
