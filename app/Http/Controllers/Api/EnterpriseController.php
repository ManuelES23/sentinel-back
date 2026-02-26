<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EnterpriseController extends Controller
{
    /**
     * Obtener todas las empresas (admin) o activas (usuarios)
     */
    public function index(Request $request): JsonResponse
    {
        // Si es admin, mostrar todas las empresas con más detalles
        if ($request->user() && $request->user()->role === 'admin') {
            $enterprises = Enterprise::with('activeApplications')
                ->get()
                ->map(function ($enterprise) {
                    return [
                        'id' => $enterprise->id,
                        'slug' => $enterprise->slug,
                        'name' => $enterprise->name,
                        'description' => $enterprise->description,
                        'logo' => $enterprise->logo ? asset('storage/' . $enterprise->logo) : null,
                        'domain' => $enterprise->domain,
                        'color' => $enterprise->color,
                        'active' => (bool) $enterprise->is_active,
                        'created_at' => $enterprise->created_at,
                        'applications_count' => $enterprise->activeApplications->count()
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $enterprises
            ]);
        }

        // Para usuarios normales, solo empresas activas
        $enterprises = Enterprise::active()
            ->with('activeApplications')
            ->get()
            ->map(function ($enterprise) {
                return [
                    'id' => $enterprise->id, // ID numérico para coincidir con permisos
                    'slug' => $enterprise->slug,
                    'name' => $enterprise->name,
                    'description' => $enterprise->description,
                    'color' => $enterprise->color,
                    'logo' => $enterprise->logo ? asset('storage/' . $enterprise->logo) : null,
                    'icon' => $enterprise->icon,
                    'primary_color' => $enterprise->primary_color,
                    'secondary_color' => $enterprise->secondary_color,
                    'applications' => $enterprise->activeApplications->map(function ($app) {
                        return [
                            'id' => $app->id, // ID numérico
                            'slug' => $app->slug,
                            'name' => $app->name,
                            'description' => $app->description,
                            'icon' => $app->icon,
                            'path' => $app->path
                        ];
                    })
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $enterprises
        ]);
    }

    /**
     * Crear una nueva empresa
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:enterprises,name',
            'description' => 'nullable|string|max:500',
            'domain' => 'nullable|string|max:255|unique:enterprises,domain',
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048',
            'active' => 'boolean'
        ]);

        // Convertir 'active' a 'is_active' para la base de datos
        if (isset($validated['active'])) {
            $validated['is_active'] = $validated['active'];
            unset($validated['active']);
        }

        // Generar slug único
        $slug = Str::slug($validated['name']);
        $originalSlug = $slug;
        $counter = 1;
        
        while (Enterprise::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $validated['slug'] = $slug;

        // Manejar subida de logo
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('enterprises', 'public');
            $validated['logo'] = $logoPath;
        }

        $enterprise = Enterprise::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Empresa creada exitosamente',
            'data' => [
                'id' => $enterprise->id,
                'slug' => $enterprise->slug,
                'name' => $enterprise->name,
                'description' => $enterprise->description,
                'logo' => $enterprise->logo ? asset('storage/' . $enterprise->logo) : null,
                'domain' => $enterprise->domain,
                'active' => (bool) $enterprise->is_active
            ]
        ], 201);
    }

    /**
     * Obtener una empresa específica
     */
    public function show(Request $request, $id): JsonResponse
    {
        // Buscar por ID o slug dependiendo del contexto
        $enterpriseModel = is_numeric($id) 
            ? Enterprise::find($id)
            : Enterprise::where('slug', $id)->first();

        if (!$enterpriseModel) {
            return response()->json([
                'status' => 'error',
                'message' => 'Empresa no encontrada'
            ], 404);
        }

        // Si es admin, devolver detalles completos
        if ($request->user() && $request->user()->role === 'admin') {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $enterpriseModel->id,
                    'slug' => $enterpriseModel->slug,
                    'name' => $enterpriseModel->name,
                    'description' => $enterpriseModel->description,
                    'logo' => $enterpriseModel->logo ? asset('storage/' . $enterpriseModel->logo) : null,
                    'domain' => $enterpriseModel->domain,
                    'color' => $enterpriseModel->color,
                    'active' => (bool) $enterpriseModel->is_active,
                    'created_at' => $enterpriseModel->created_at
                ]
            ]);
        }

        // Para usuarios normales, solo datos básicos
        $enterpriseModel->load('activeApplications');
        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $enterpriseModel->slug,
                'name' => $enterpriseModel->name,
                'description' => $enterpriseModel->description,
                'color' => $enterpriseModel->color,
                'applications' => $enterpriseModel->activeApplications->map(function ($app) {
                    return [
                        'id' => $app->slug,
                        'name' => $app->name,
                        'description' => $app->description,
                        'icon' => $app->icon,
                        'path' => $app->path
                    ];
                })
            ]
        ]);
    }

    /**
     * Actualizar una empresa
     */
    public function update(Request $request, $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (!$enterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'Empresa no encontrada'
            ], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('enterprises', 'name')->ignore($enterprise->id)
            ],
            'description' => 'nullable|string|max:500',
            'domain' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('enterprises', 'domain')->ignore($enterprise->id)
            ],
            'logo' => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048',
            'active' => 'boolean'
        ]);

        // Convertir 'active' a 'is_active' para la base de datos
        if (isset($validated['active'])) {
            $validated['is_active'] = $validated['active'];
            unset($validated['active']);
        }

        // Generar nuevo slug si el nombre cambió
        if ($validated['name'] !== $enterprise->name) {
            $slug = Str::slug($validated['name']);
            $originalSlug = $slug;
            $counter = 1;
            
            while (Enterprise::where('slug', $slug)->where('id', '!=', $enterprise->id)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            $validated['slug'] = $slug;
        }

        // Manejar subida de nuevo logo
        if ($request->hasFile('logo')) {
            // Eliminar logo anterior
            if ($enterprise->logo) {
                Storage::disk('public')->delete($enterprise->logo);
            }
            
            $logoPath = $request->file('logo')->store('enterprises', 'public');
            $validated['logo'] = $logoPath;
        }

        $enterprise->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Empresa actualizada exitosamente',
            'data' => [
                'id' => $enterprise->id,
                'slug' => $enterprise->slug,
                'name' => $enterprise->name,
                'description' => $enterprise->description,
                'logo' => $enterprise->logo ? asset('storage/' . $enterprise->logo) : null,
                'domain' => $enterprise->domain,
                'active' => (bool) $enterprise->is_active
            ]
        ]);
    }

    /**
     * Eliminar una empresa
     */
    public function destroy($id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (!$enterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'Empresa no encontrada'
            ], 404);
        }

        // Eliminar logo si existe
        if ($enterprise->logo) {
            Storage::disk('public')->delete($enterprise->logo);
        }

        $enterprise->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Empresa eliminada exitosamente'
        ]);
    }

    /**
     * Obtener aplicaciones de una empresa
     */
    public function applications(string $enterprise): JsonResponse
    {
        $enterpriseModel = Enterprise::where('slug', $enterprise)
            ->with('activeApplications')
            ->first();

        if (!$enterpriseModel) {
            return response()->json([
                'status' => 'error',
                'message' => 'Empresa no encontrada'
            ], 404);
        }

        $applications = $enterpriseModel->activeApplications->map(function ($app) {
            return [
                'id' => $app->slug,
                'name' => $app->name,
                'description' => $app->description,
                'icon' => $app->icon,
                'path' => $app->path
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $applications
        ]);
    }
}
