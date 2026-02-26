<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Submodule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubmoduleController extends Controller
{
    /**
     * Obtener todos los submódulos (opcionalmente filtrar por módulo)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Submodule::with(['module.application.enterprise']);

        // Filtrar por módulo si se proporciona
        if ($request->has('module_id')) {
            $query->where('module_id', $request->module_id);
        }

        // Filtrar por aplicación si se proporciona
        if ($request->has('application_id')) {
            $query->whereHas('module', function ($q) use ($request) {
                $q->where('application_id', $request->application_id);
            });
        }

        $submodules = $query->ordered()->get()->map(function ($submodule) {
            return $this->formatSubmodule($submodule);
        });

        return response()->json([
            'status' => 'success',
            'data' => $submodules,
        ]);
    }

    /**
     * Obtener submódulos de un módulo específico
     */
    public function byModule($moduleId): JsonResponse
    {
        /** @var Module|null $module */
        $module = Module::with(['application.enterprise'])->find($moduleId);

        if (! $module) {
            return response()->json([
                'status' => 'error',
                'message' => 'Módulo no encontrado',
            ], 404);
        }

        $submodules = Submodule::where('module_id', $moduleId)
            ->ordered()
            ->get()
            ->map(function (Submodule $submodule) use ($module) {
                return $this->formatSubmodule($submodule, $module);
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'module' => [
                    'id' => $module->id,
                    'name' => $module->name,
                    'slug' => $module->slug,
                    'application' => [
                        'id' => $module->application->id,
                        'name' => $module->application->name,
                        'slug' => $module->application->slug,
                        'enterprise' => [
                            'id' => $module->application->enterprise->id,
                            'name' => $module->application->enterprise->name,
                            'slug' => $module->application->enterprise->slug,
                        ],
                    ],
                ],
                'submodules' => $submodules,
            ],
        ]);
    }

    /**
     * Crear un nuevo submódulo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module_id' => 'required|exists:modules,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'path' => 'nullable|string|max:255',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        // Generar slug si no se proporciona
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Verificar que no exista el slug en el mismo módulo
        $exists = Submodule::where('module_id', $validated['module_id'])
            ->where('slug', $validated['slug'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ya existe un submódulo con ese slug en este módulo',
            ], 422);
        }

        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['icon'] = $validated['icon'] ?? 'FileText';

        $submodule = Submodule::create($validated);
        $submodule->load(['module.application.enterprise']);

        return response()->json([
            'status' => 'success',
            'message' => 'Submódulo creado exitosamente',
            'data' => $this->formatSubmodule($submodule),
        ], 201);
    }

    /**
     * Obtener un submódulo específico
     */
    public function show($id): JsonResponse
    {
        /** @var Submodule|null $submodule */
        $submodule = Submodule::with(['module.application.enterprise'])->find($id);

        if (! $submodule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Submódulo no encontrado',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatSubmodule($submodule),
        ]);
    }

    /**
     * Actualizar un submódulo
     */
    public function update(Request $request, $id): JsonResponse
    {
        $submodule = Submodule::find($id);

        if (! $submodule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Submódulo no encontrado',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'path' => 'nullable|string|max:255',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        // Verificar slug único si se está cambiando
        if (isset($validated['slug']) && $validated['slug'] !== $submodule->slug) {
            $exists = Submodule::where('module_id', $submodule->module_id)
                ->where('slug', $validated['slug'])
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ya existe un submódulo con ese slug en este módulo',
                ], 422);
            }
        }

        $submodule->update($validated);
        $submodule->load(['module.application.enterprise']);

        return response()->json([
            'status' => 'success',
            'message' => 'Submódulo actualizado exitosamente',
            'data' => $this->formatSubmodule($submodule),
        ]);
    }

    /**
     * Eliminar un submódulo
     */
    public function destroy($id): JsonResponse
    {
        $submodule = Submodule::find($id);

        if (! $submodule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Submódulo no encontrado',
            ], 404);
        }

        $submodule->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Submódulo eliminado exitosamente',
        ]);
    }

    /**
     * Reordenar submódulos
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'submodules' => 'required|array',
            'submodules.*.id' => 'required|exists:submodules,id',
            'submodules.*.order' => 'required|integer',
        ]);

        foreach ($validated['submodules'] as $item) {
            Submodule::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Orden actualizado exitosamente',
        ]);
    }

    /**
     * Formatear submódulo para respuesta
     */
    private function formatSubmodule(Submodule $submodule, ?Module $module = null): array
    {
        $mod = $module ?? $submodule->module;

        return [
            'id' => $submodule->id,
            'slug' => $submodule->slug,
            'name' => $submodule->name,
            'description' => $submodule->description,
            'icon' => $submodule->icon,
            'path' => $submodule->path,
            'order' => $submodule->order,
            'is_active' => $submodule->is_active,
            'module' => $mod ? [
                'id' => $mod->id,
                'name' => $mod->name,
                'slug' => $mod->slug,
                'application' => $mod->application ? [
                    'id' => $mod->application->id,
                    'name' => $mod->application->name,
                    'slug' => $mod->application->slug,
                    'enterprise' => $mod->application->enterprise ? [
                        'id' => $mod->application->enterprise->id,
                        'name' => $mod->application->enterprise->name,
                    ] : null,
                ] : null,
            ] : null,
            'created_at' => $submodule->created_at,
        ];
    }
}
