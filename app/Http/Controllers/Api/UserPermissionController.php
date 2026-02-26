<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Module;
use App\Models\Submodule;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserPermissionController extends Controller
{
    /**
     * Obtener todos los permisos de un usuario (usando nuevo sistema jerárquico)
     */
    public function index($userId): JsonResponse
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Obtener permisos de módulos desde el nuevo sistema (user_module_access)
        $modulePermissions = DB::table('user_module_access')
            ->join('modules', 'user_module_access.module_id', '=', 'modules.id')
            ->join('applications', 'modules.application_id', '=', 'applications.id')
            ->join('enterprises', 'applications.enterprise_id', '=', 'enterprises.id')
            ->where('user_module_access.user_id', $userId)
            ->select(
                'user_module_access.*',
                'modules.name as module_name',
                'modules.slug as module_slug',
                'modules.icon as module_icon',
                'applications.id as application_id',
                'applications.name as application_name',
                'applications.slug as application_slug',
                'enterprises.id as enterprise_id',
                'enterprises.name as enterprise_name',
                'enterprises.slug as enterprise_slug'
            )
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'module_id' => $item->module_id,
                    'module' => [
                        'name' => $item->module_name,
                        'slug' => $item->module_slug,
                        'icon' => $item->module_icon
                    ],
                    'application' => [
                        'id' => $item->application_id,
                        'name' => $item->application_name,
                        'slug' => $item->application_slug
                    ],
                    'enterprise' => [
                        'id' => $item->enterprise_id,
                        'name' => $item->enterprise_name,
                        'slug' => $item->enterprise_slug
                    ],
                    'permissions' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true], // Por ahora, todos los permisos
                    'is_active' => (bool) $item->is_active,
                    'granted_at' => $item->granted_at,
                    'expires_at' => $item->expires_at
                ];
            });

        // Obtener permisos de submódulos desde el nuevo sistema (user_submodule_access)
        $submodulePermissions = DB::table('user_submodule_access')
            ->join('submodules', 'user_submodule_access.submodule_id', '=', 'submodules.id')
            ->join('modules', 'submodules.module_id', '=', 'modules.id')
            ->join('applications', 'modules.application_id', '=', 'applications.id')
            ->where('user_submodule_access.user_id', $userId)
            ->select(
                'user_submodule_access.*',
                'submodules.name as submodule_name',
                'submodules.slug as submodule_slug',
                'submodules.icon as submodule_icon',
                'modules.id as module_id',
                'modules.name as module_name',
                'applications.id as application_id',
                'applications.name as application_name'
            )
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'submodule_id' => $item->submodule_id,
                    'submodule' => [
                        'name' => $item->submodule_name,
                        'slug' => $item->submodule_slug,
                        'icon' => $item->submodule_icon
                    ],
                    'module' => [
                        'id' => $item->module_id,
                        'name' => $item->module_name
                    ],
                    'application' => [
                        'id' => $item->application_id,
                        'name' => $item->application_name
                    ],
                    'permissions' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true], // Permisos por defecto
                    'is_active' => (bool) $item->is_active,
                    'granted_at' => $item->granted_at,
                    'expires_at' => $item->expires_at
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'module_permissions' => $modulePermissions,
                'submodule_permissions' => $submodulePermissions
            ]
        ]);
    }

    /**
     * Asignar permisos de módulo a un usuario
     */
    public function assignModulePermission(Request $request, $userId): JsonResponse
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validated = $request->validate([
            'module_id' => 'required|exists:modules,id',
            'permissions' => 'nullable|array',
            'permissions.view' => 'nullable|boolean',
            'permissions.create' => 'nullable|boolean',
            'permissions.edit' => 'nullable|boolean',
            'permissions.delete' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'expires_at' => 'nullable|date'
        ]);

        $permissions = $validated['permissions'] ?? [
            'view' => true,
            'create' => false,
            'edit' => false,
            'delete' => false
        ];

        // Usar el nuevo sistema jerárquico (user_module_access)
        DB::table('user_module_access')->updateOrInsert(
            [
                'user_id' => $userId,
                'module_id' => $validated['module_id']
            ],
            [
                'is_active' => $validated['is_active'] ?? true,
                'granted_at' => now(),
                'expires_at' => $validated['expires_at'] ?? null,
                'updated_at' => now()
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permisos de módulo asignados exitosamente'
        ]);
    }

    /**
     * Asignar permisos de submódulo a un usuario
     */
    public function assignSubmodulePermission(Request $request, $userId): JsonResponse
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validated = $request->validate([
            'submodule_id' => 'required|exists:submodules,id',
            'permissions' => 'nullable|array',
            'permissions.view' => 'nullable|boolean',
            'permissions.create' => 'nullable|boolean',
            'permissions.edit' => 'nullable|boolean',
            'permissions.delete' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'expires_at' => 'nullable|date'
        ]);

        $permissions = $validated['permissions'] ?? [
            'view' => true,
            'create' => false,
            'edit' => false,
            'delete' => false
        ];

        // Usar el nuevo sistema jerárquico (user_submodule_access)
        DB::table('user_submodule_access')->updateOrInsert(
            [
                'user_id' => $userId,
                'submodule_id' => $validated['submodule_id']
            ],
            [
                'is_active' => $validated['is_active'] ?? true,
                'granted_at' => now(),
                'expires_at' => $validated['expires_at'] ?? null,
                'updated_at' => now()
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Permisos de submódulo asignados exitosamente'
        ]);
    }

    /**
     * Asignar permisos masivos a un usuario
     */
    public function assignBulkPermissions(Request $request, $userId): JsonResponse
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validated = $request->validate([
            'module_permissions' => 'nullable|array',
            'module_permissions.*.module_id' => 'required|exists:modules,id',
            'module_permissions.*.permissions' => 'nullable|array',
            'module_permissions.*.is_active' => 'nullable|boolean',
            'submodule_permissions' => 'nullable|array',
            'submodule_permissions.*.submodule_id' => 'required|exists:submodules,id',
            'submodule_permissions.*.permissions' => 'nullable|array',
            'submodule_permissions.*.is_active' => 'nullable|boolean'
        ]);

        DB::beginTransaction();
        try {
            // Asignar permisos de módulos (usando nuevo sistema jerárquico)
            if (!empty($validated['module_permissions'])) {
                foreach ($validated['module_permissions'] as $mp) {
                    DB::table('user_module_access')->updateOrInsert(
                        [
                            'user_id' => $userId,
                            'module_id' => $mp['module_id']
                        ],
                        [
                            'is_active' => $mp['is_active'] ?? true,
                            'granted_at' => now(),
                            'updated_at' => now()
                        ]
                    );
                }
            }

            // Asignar permisos de submódulos (usando nuevo sistema jerárquico)
            if (!empty($validated['submodule_permissions'])) {
                foreach ($validated['submodule_permissions'] as $sp) {
                    DB::table('user_submodule_access')->updateOrInsert(
                        [
                            'user_id' => $userId,
                            'submodule_id' => $sp['submodule_id']
                        ],
                        [
                            'is_active' => $sp['is_active'] ?? true,
                            'granted_at' => now(),
                            'updated_at' => now()
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Permisos asignados exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al asignar permisos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revocar permiso de módulo
     */
    public function revokeModulePermission($userId, $moduleId): JsonResponse
    {
        $deleted = DB::table('user_module_access')
            ->where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->delete();

        if ($deleted) {
            return response()->json([
                'status' => 'success',
                'message' => 'Permiso de módulo revocado exitosamente'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Permiso no encontrado'
        ], 404);
    }

    /**
     * Revocar permiso de submódulo
     */
    public function revokeSubmodulePermission($userId, $submoduleId): JsonResponse
    {
        $deleted = DB::table('user_submodule_access')
            ->where('user_id', $userId)
            ->where('submodule_id', $submoduleId)
            ->delete();

        if ($deleted) {
            return response()->json([
                'status' => 'success',
                'message' => 'Permiso de submódulo revocado exitosamente'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Permiso no encontrado'
        ], 404);
    }

    /**
     * Obtener estructura jerárquica de permisos disponibles para un usuario
     */
    public function getAvailablePermissions($userId): JsonResponse
    {
        $user = User::with(['enterprises.applications.modules.submodules'])->find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Obtener permisos actuales del usuario (usando nuevo sistema)
        $currentModulePermissions = DB::table('user_module_access')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('module_id')
            ->toArray();

        $currentSubmodulePermissions = DB::table('user_submodule_access')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('submodule_id')
            ->toArray();

        // Construir estructura jerárquica
        $hierarchy = $user->enterprises->map(function ($enterprise) use ($currentModulePermissions, $currentSubmodulePermissions) {
            return [
                'id' => $enterprise->id,
                'name' => $enterprise->name,
                'slug' => $enterprise->slug,
                'applications' => $enterprise->applications->map(function ($app) use ($currentModulePermissions, $currentSubmodulePermissions) {
                    return [
                        'id' => $app->id,
                        'name' => $app->name,
                        'slug' => $app->slug,
                        'icon' => $app->icon,
                        'modules' => $app->modules->map(function ($module) use ($currentModulePermissions, $currentSubmodulePermissions) {
                            $hasModuleAccess = in_array($module->id, $currentModulePermissions);
                            return [
                                'id' => $module->id,
                                'name' => $module->name,
                                'slug' => $module->slug,
                                'icon' => $module->icon,
                                'is_active' => $module->is_active,
                                'has_access' => $hasModuleAccess,
                                'current_permissions' => $hasModuleAccess ? ['view' => true, 'create' => true, 'edit' => true, 'delete' => true] : null,
                                'submodules' => $module->submodules->map(function ($sub) use ($currentSubmodulePermissions) {
                                    $hasSubmoduleAccess = in_array($sub->id, $currentSubmodulePermissions);
                                    return [
                                        'id' => $sub->id,
                                        'name' => $sub->name,
                                        'slug' => $sub->slug,
                                        'icon' => $sub->icon,
                                        'is_active' => $sub->is_active,
                                        'has_access' => $hasSubmoduleAccess,
                                        'current_permissions' => $hasSubmoduleAccess ? ['view' => true, 'create' => true, 'edit' => true, 'delete' => true] : null
                                    ];
                                })
                            ];
                        })
                    ];
                })
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'hierarchy' => $hierarchy
            ]
        ]);
    }
}
