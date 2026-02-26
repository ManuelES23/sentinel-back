<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ModuleController extends Controller
{
    /**
     * Obtener todos los módulos
     */
    public function index(Request $request): JsonResponse
    {
        $query = Module::with(['application', 'submodules'])
            ->withCount('submodules');

        // Filtrar por aplicación si se especifica
        if ($request->has('application_id')) {
            $query->where('application_id', $request->application_id);
        }

        $modules = $query->orderBy('order')->get();

        return response()->json([
            'status' => 'success',
            'data' => $modules
        ]);
    }

    /**
     * Crear un nuevo módulo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => 'required|exists:applications,id',
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'path' => 'nullable|string|max:100',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean'
        ]);

        // Generar slug si no se proporciona
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Verificar que no exista el slug en la misma aplicación
        $exists = Module::where('application_id', $validated['application_id'])
            ->where('slug', $validated['slug'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ya existe un módulo con ese nombre/slug en esta aplicación'
            ], 422);
        }

        // Obtener el orden máximo actual para esta aplicación
        if (!isset($validated['order'])) {
            $maxOrder = Module::where('application_id', $validated['application_id'])->max('order') ?? 0;
            $validated['order'] = $maxOrder + 1;
        }

        // Valores por defecto
        $validated['icon'] = $validated['icon'] ?? 'Package';
        $validated['is_active'] = $validated['is_active'] ?? true;

        $module = Module::create($validated);
        $module->load(['application', 'submodules']);
        $module->loadCount('submodules');

        return response()->json([
            'status' => 'success',
            'message' => 'Módulo creado exitosamente',
            'data' => $module
        ], 201);
    }

    /**
     * Mostrar un módulo específico
     */
    public function show($id): JsonResponse
    {
        $module = Module::with(['application', 'submodules'])
            ->withCount('submodules')
            ->find($id);

        if (!$module) {
            return response()->json([
                'status' => 'error',
                'message' => 'Módulo no encontrado'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $module
        ]);
    }

    /**
     * Actualizar un módulo
     */
    public function update(Request $request, $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json([
                'status' => 'error',
                'message' => 'Módulo no encontrado'
            ], 404);
        }

        $validated = $request->validate([
            'application_id' => 'sometimes|exists:applications,id',
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:50',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'path' => 'nullable|string|max:100',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean'
        ]);

        $module->update($validated);
        $module->load(['application', 'submodules']);
        $module->loadCount('submodules');

        return response()->json([
            'status' => 'success',
            'message' => 'Módulo actualizado exitosamente',
            'data' => $module
        ]);
    }

    /**
     * Eliminar un módulo
     */
    public function destroy($id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json([
                'status' => 'error',
                'message' => 'Módulo no encontrado'
            ], 404);
        }

        // Verificar si tiene submódulos
        if ($module->submodules()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el módulo porque tiene submódulos asociados'
            ], 422);
        }

        $module->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Módulo eliminado exitosamente'
        ]);
    }

    /**
     * Reordenar módulos
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'modules' => 'required|array',
            'modules.*.id' => 'required|exists:modules,id',
            'modules.*.order' => 'required|integer'
        ]);

        foreach ($validated['modules'] as $item) {
            Module::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Orden actualizado exitosamente'
        ]);
    }

    /**
     * Obtener submódulos de un módulo
     */
    public function getSubmodules($moduleId): JsonResponse
    {
        $module = Module::find($moduleId);

        if (!$module) {
            return response()->json([
                'status' => 'error',
                'message' => 'Módulo no encontrado'
            ], 404);
        }

        $submodules = $module->submodules()
            ->orderBy('order')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $submodules
        ]);
    }

    /**
     * Obtener módulos de una aplicación específica
     */
    public function byApplication($applicationId): JsonResponse
    {
        $application = Application::find($applicationId);

        if (!$application) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aplicación no encontrada'
            ], 404);
        }

        $modules = Module::where('application_id', $applicationId)
            ->with(['submodules'])
            ->withCount('submodules')
            ->orderBy('order')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $modules
        ]);
    }
}
