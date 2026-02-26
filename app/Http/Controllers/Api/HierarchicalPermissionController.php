<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserEnterpriseAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HierarchicalPermissionController extends Controller
{
    /**
     * Obtener todos los permisos jerárquicos de un usuario
     */
    public function getUserPermissions($userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        // Obtener accesos a empresas con datos de la empresa
        $enterprises = UserEnterpriseAccess::where('user_id', $userId)
            ->with('enterprise:id,name,slug,description,logo,color,is_active')
            ->get()
            ->map(function ($access) {
                return [
                    'id' => $access->enterprise_id,
                    'access_id' => $access->id,
                    'name' => $access->enterprise->name ?? null,
                    'slug' => $access->enterprise->slug ?? null,
                    'description' => $access->enterprise->description ?? null,
                    'logo' => $access->enterprise->logo ? asset('storage/' . $access->enterprise->logo) : null,
                    'color' => $access->enterprise->color ?? null,
                    'is_active' => $access->is_active && ($access->enterprise->is_active ?? false),
                    'granted_at' => $access->granted_at,
                    'expires_at' => $access->expires_at,
                ];
            });

        // Obtener accesos a aplicaciones
        $applications = UserApplicationAccess::where('user_id', $userId)
            ->get()
            ->map(function ($access) {
                return [
                    'id' => $access->application_id,
                    'access_id' => $access->id,
                    'is_active' => $access->is_active,
                    'granted_at' => $access->granted_at,
                    'expires_at' => $access->expires_at,
                ];
            });

        // Obtener accesos a módulos
        $modules = UserModuleAccess::where('user_id', $userId)
            ->get()
            ->map(function ($access) {
                return [
                    'id' => $access->module_id,
                    'access_id' => $access->id,
                    'is_active' => $access->is_active,
                    'granted_at' => $access->granted_at,
                    'expires_at' => $access->expires_at,
                ];
            });

        // Obtener accesos a submódulos
        $submodules = UserSubmoduleAccess::where('user_id', $userId)
            ->get()
            ->map(function ($access) {
                return [
                    'id' => $access->submodule_id,
                    'access_id' => $access->id,
                    'is_active' => $access->is_active,
                    'granted_at' => $access->granted_at,
                    'expires_at' => $access->expires_at,
                ];
            });

        // Obtener permisos específicos de submódulos
        $submodulePermissions = UserSubmodulePermission::where('user_id', $userId)
            ->with('permissionType:id,slug,name')
            ->get(['id', 'submodule_id', 'permission_type_id', 'is_granted']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'enterprises' => $enterprises,
                'applications' => $applications,
                'modules' => $modules,
                'submodules' => $submodules,
                'submodule_permissions' => $submodulePermissions,
            ],
        ]);
    }

    /**
     * Obtener la jerarquía completa de una empresa
     */
    public function getEnterpriseHierarchy($enterpriseId): JsonResponse
    {
        $enterprise = Enterprise::with(['applications' => function ($query) {
            $query->where('is_active', true)
                ->orderBy('name')
                ->with(['modules' => function ($q) {
                    $q->where('is_active', true)
                        ->orderBy('order')
                        ->with(['submodules' => function ($sq) {
                            $sq->where('is_active', true)
                                ->orderBy('order')
                                ->with(['permissionTypes' => function ($pt) {
                                    $pt->where('is_active', true)->orderBy('order');
                                }]);
                        }]);
                }]);
        }])->find($enterpriseId);

        if (! $enterprise) {
            return response()->json([
                'status' => 'error',
                'message' => 'Empresa no encontrada',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $enterprise,
        ]);
    }

    /**
     * Asignar/revocar acceso a empresa
     */
    public function assignEnterpriseAccess(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'is_active' => 'required|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $access = UserEnterpriseAccess::updateOrCreate(
            [
                'user_id' => $userId,
                'enterprise_id' => $validated['enterprise_id'],
            ],
            [
                'is_active' => $validated['is_active'],
                'granted_at' => $validated['is_active'] ? now() : null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]
        );

        // Si se revoca acceso a empresa, revocar también acceso a sus aplicaciones, módulos y submódulos
        if (! $validated['is_active']) {
            $this->revokeChildAccess($userId, $validated['enterprise_id']);
        }

        return response()->json([
            'status' => 'success',
            'message' => $validated['is_active'] ? 'Acceso a empresa concedido' : 'Acceso a empresa revocado',
            'data' => $access,
        ]);
    }

    /**
     * Asignar/revocar acceso a aplicación
     */
    public function assignApplicationAccess(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => 'required|exists:applications,id',
            'is_active' => 'required|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $access = UserApplicationAccess::updateOrCreate(
            [
                'user_id' => $userId,
                'application_id' => $validated['application_id'],
            ],
            [
                'is_active' => $validated['is_active'],
                'granted_at' => $validated['is_active'] ? now() : null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]
        );

        // Si se revoca acceso, revocar también módulos y submódulos
        if (! $validated['is_active']) {
            $application = Application::find($validated['application_id']);
            if ($application) {
                $moduleIds = $application->modules->pluck('id')->toArray();
                UserModuleAccess::where('user_id', $userId)
                    ->whereIn('module_id', $moduleIds)
                    ->update(['is_active' => false]);

                $submoduleIds = Submodule::whereIn('module_id', $moduleIds)->pluck('id')->toArray();
                UserSubmoduleAccess::where('user_id', $userId)
                    ->whereIn('submodule_id', $submoduleIds)
                    ->update(['is_active' => false]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => $validated['is_active'] ? 'Acceso a aplicación concedido' : 'Acceso a aplicación revocado',
            'data' => $access,
        ]);
    }

    /**
     * Asignar/revocar acceso a módulo
     */
    public function assignModuleAccess(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'module_id' => 'required|exists:modules,id',
            'is_active' => 'required|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $access = UserModuleAccess::updateOrCreate(
            [
                'user_id' => $userId,
                'module_id' => $validated['module_id'],
            ],
            [
                'is_active' => $validated['is_active'],
                'granted_at' => $validated['is_active'] ? now() : null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]
        );

        // Si se revoca acceso, revocar también submódulos
        if (! $validated['is_active']) {
            $submoduleIds = Submodule::where('module_id', $validated['module_id'])->pluck('id')->toArray();
            UserSubmoduleAccess::where('user_id', $userId)
                ->whereIn('submodule_id', $submoduleIds)
                ->update(['is_active' => false]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $validated['is_active'] ? 'Acceso a módulo concedido' : 'Acceso a módulo revocado',
            'data' => $access,
        ]);
    }

    /**
     * Asignar/revocar acceso a submódulo
     */
    public function assignSubmoduleAccess(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'submodule_id' => 'required|exists:submodules,id',
            'is_active' => 'required|boolean',
            'expires_at' => 'nullable|date',
        ]);

        $access = UserSubmoduleAccess::updateOrCreate(
            [
                'user_id' => $userId,
                'submodule_id' => $validated['submodule_id'],
            ],
            [
                'is_active' => $validated['is_active'],
                'granted_at' => $validated['is_active'] ? now() : null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => $validated['is_active'] ? 'Acceso a submódulo concedido' : 'Acceso a submódulo revocado',
            'data' => $access,
        ]);
    }

    /**
     * Asignar permiso específico de submódulo
     */
    public function assignSubmodulePermission(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'submodule_id' => 'required|exists:submodules,id',
            'permission_type_id' => 'required|exists:submodule_permission_types,id',
            'is_granted' => 'required|boolean',
        ]);

        $permission = UserSubmodulePermission::updateOrCreate(
            [
                'user_id' => $userId,
                'submodule_id' => $validated['submodule_id'],
                'permission_type_id' => $validated['permission_type_id'],
            ],
            [
                'is_granted' => $validated['is_granted'],
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => $validated['is_granted'] ? 'Permiso concedido' : 'Permiso revocado',
            'data' => $permission,
        ]);
    }

    /**
     * Asignación masiva de permisos
     */
    public function bulkAssignPermissions(Request $request, $userId): JsonResponse
    {
        $validated = $request->validate([
            'enterprises' => 'nullable|array',
            'enterprises.*' => 'integer|exists:enterprises,id',
            'applications' => 'nullable|array',
            'applications.*' => 'integer|exists:applications,id',
            'modules' => 'nullable|array',
            'modules.*' => 'integer|exists:modules,id',
            'submodules' => 'nullable|array',
            'submodules.*' => 'integer|exists:submodules,id',
            'permissions' => 'nullable|array',
            'permissions.*.submodule_id' => 'required|integer|exists:submodules,id',
            'permissions.*.permission_type_id' => 'required|integer|exists:submodule_permission_types,id',
            'permissions.*.is_granted' => 'required|boolean',
        ]);

        DB::beginTransaction();
        try {
            $now = now();

            // Procesar empresas
            if (isset($validated['enterprises'])) {
                // Desactivar todas las que no están en la lista
                UserEnterpriseAccess::where('user_id', $userId)
                    ->whereNotIn('enterprise_id', $validated['enterprises'])
                    ->update(['is_active' => false]);

                // Activar las que están en la lista
                foreach ($validated['enterprises'] as $enterpriseId) {
                    UserEnterpriseAccess::updateOrCreate(
                        ['user_id' => $userId, 'enterprise_id' => $enterpriseId],
                        ['is_active' => true, 'granted_at' => $now]
                    );
                }
            }

            // Procesar aplicaciones
            if (isset($validated['applications'])) {
                UserApplicationAccess::where('user_id', $userId)
                    ->whereNotIn('application_id', $validated['applications'])
                    ->update(['is_active' => false]);

                foreach ($validated['applications'] as $applicationId) {
                    UserApplicationAccess::updateOrCreate(
                        ['user_id' => $userId, 'application_id' => $applicationId],
                        ['is_active' => true, 'granted_at' => $now]
                    );
                }
            }

            // Procesar módulos
            if (isset($validated['modules'])) {
                UserModuleAccess::where('user_id', $userId)
                    ->whereNotIn('module_id', $validated['modules'])
                    ->update(['is_active' => false]);

                foreach ($validated['modules'] as $moduleId) {
                    UserModuleAccess::updateOrCreate(
                        ['user_id' => $userId, 'module_id' => $moduleId],
                        ['is_active' => true, 'granted_at' => $now]
                    );
                }
            }

            // Procesar submódulos
            if (isset($validated['submodules'])) {
                UserSubmoduleAccess::where('user_id', $userId)
                    ->whereNotIn('submodule_id', $validated['submodules'])
                    ->update(['is_active' => false]);

                foreach ($validated['submodules'] as $submoduleId) {
                    UserSubmoduleAccess::updateOrCreate(
                        ['user_id' => $userId, 'submodule_id' => $submoduleId],
                        ['is_active' => true, 'granted_at' => $now]
                    );
                }
            }

            // Procesar permisos específicos
            if (isset($validated['permissions'])) {
                foreach ($validated['permissions'] as $perm) {
                    UserSubmodulePermission::updateOrCreate(
                        [
                            'user_id' => $userId,
                            'submodule_id' => $perm['submodule_id'],
                            'permission_type_id' => $perm['permission_type_id'],
                        ],
                        ['is_granted' => $perm['is_granted']]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos actualizados correctamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar permisos: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener tipos de permisos de un submódulo
     */
    public function getSubmodulePermissionTypes($submoduleId): JsonResponse
    {
        $types = SubmodulePermissionType::where('submodule_id', $submoduleId)
            ->orderBy('order')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $types,
        ]);
    }

    /**
     * Agregar tipo de permiso a un submódulo
     */
    public function addPermissionType(Request $request, $submoduleId): JsonResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|max:50',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'order' => 'nullable|integer',
        ]);

        // Verificar que el submódulo existe
        $submodule = Submodule::find($submoduleId);
        if (! $submodule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Submódulo no encontrado',
            ], 404);
        }

        // Verificar que no exista ya este slug
        $exists = SubmodulePermissionType::where('submodule_id', $submoduleId)
            ->where('slug', $validated['slug'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ya existe un tipo de permiso con ese slug para este submódulo',
            ], 422);
        }

        // Obtener el orden máximo actual
        $maxOrder = SubmodulePermissionType::where('submodule_id', $submoduleId)->max('order') ?? 0;

        $type = SubmodulePermissionType::create([
            'submodule_id' => $submoduleId,
            'slug' => $validated['slug'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'order' => $validated['order'] ?? ($maxOrder + 1),
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Tipo de permiso agregado',
            'data' => $type,
        ], 201);
    }

    /**
     * Eliminar tipo de permiso de un submódulo
     */
    public function removePermissionType($submoduleId, $permissionTypeId): JsonResponse
    {
        $type = SubmodulePermissionType::where('submodule_id', $submoduleId)
            ->where('id', $permissionTypeId)
            ->first();

        if (! $type) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tipo de permiso no encontrado',
            ], 404);
        }

        // Eliminar todos los permisos de usuario asociados
        UserSubmodulePermission::where('permission_type_id', $permissionTypeId)->delete();

        // Eliminar el tipo de permiso
        $type->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Tipo de permiso eliminado',
        ]);
    }

    /**
     * Crear permisos CRUD por defecto para un submódulo
     */
    public function createDefaultPermissions($submoduleId): JsonResponse
    {
        $submodule = Submodule::find($submoduleId);

        if (! $submodule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Submódulo no encontrado',
            ], 404);
        }

        $defaultPermissions = [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver registros', 'order' => 1],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear registros', 'order' => 2],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Permite editar registros', 'order' => 3],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar registros', 'order' => 4],
        ];

        $created = 0;

        foreach ($defaultPermissions as $perm) {
            $exists = SubmodulePermissionType::where('submodule_id', $submoduleId)
                ->where('slug', $perm['slug'])
                ->exists();

            if (! $exists) {
                SubmodulePermissionType::create([
                    'submodule_id' => $submoduleId,
                    'slug' => $perm['slug'],
                    'name' => $perm['name'],
                    'description' => $perm['description'],
                    'order' => $perm['order'],
                    'is_active' => true,
                ]);
                $created++;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Se crearon $created permisos por defecto",
        ]);
    }

    /**
     * Revocar acceso a todos los hijos de una empresa
     */
    private function revokeChildAccess($userId, $enterpriseId): void
    {
        $enterprise = Enterprise::with(['applications.modules.submodules'])->find($enterpriseId);

        if (! $enterprise) {
            return;
        }

        $applicationIds = $enterprise->applications->pluck('id')->toArray();
        $moduleIds = [];
        $submoduleIds = [];

        foreach ($enterprise->applications as $app) {
            $moduleIds = array_merge($moduleIds, $app->modules->pluck('id')->toArray());
            foreach ($app->modules as $module) {
                $submoduleIds = array_merge($submoduleIds, $module->submodules->pluck('id')->toArray());
            }
        }

        // Desactivar accesos
        UserApplicationAccess::where('user_id', $userId)
            ->whereIn('application_id', $applicationIds)
            ->update(['is_active' => false]);

        UserModuleAccess::where('user_id', $userId)
            ->whereIn('module_id', $moduleIds)
            ->update(['is_active' => false]);

        UserSubmoduleAccess::where('user_id', $userId)
            ->whereIn('submodule_id', $submoduleIds)
            ->update(['is_active' => false]);
    }
}
