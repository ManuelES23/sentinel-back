<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserEntityAccess;
use App\Services\Inventory\AlmacenAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Almacenes (entidades) asignados a un usuario, por empresa.
 */
class UserAlmacenController extends Controller
{
    public function __construct(private AlmacenAccessService $almacenes)
    {
    }

    public function index(User $user): JsonResponse
    {
        $empresaIds = UserEnterpriseAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('enterprise_id');

        $data = Enterprise::whereIn('id', $empresaIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Enterprise $empresa) => $this->resumen($user, $empresa))
            ->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|integer|exists:enterprises,id',
            'entity_ids' => 'present|array',
            'entity_ids.*' => 'integer',
        ]);

        $empresa = Enterprise::findOrFail($validated['enterprise_id']);
        $ids = collect($validated['entity_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $permitidos = $this->almacenes->idsDeEmpresa($empresa);

        if ($ids->diff($permitidos)->isNotEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Hay almacenes que no pertenecen a la empresa seleccionada',
            ], 422);
        }

        DB::transaction(function () use ($user, $empresa, $ids, $request) {
            UserEntityAccess::where('user_id', $user->id)
                ->where('enterprise_id', $empresa->id)
                ->whereNotIn('entity_id', $ids)
                ->delete();

            $existentes = UserEntityAccess::where('user_id', $user->id)
                ->where('enterprise_id', $empresa->id)
                ->pluck('entity_id')
                ->map(fn ($id) => (int) $id);

            foreach ($ids->diff($existentes) as $entityId) {
                UserEntityAccess::create([
                    'user_id' => $user->id,
                    'entity_id' => $entityId,
                    'enterprise_id' => $empresa->id,
                    'granted_by' => $request->user()->id,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Almacenes actualizados',
            'data' => [$this->resumen($user, $empresa)],
        ]);
    }

    private function resumen(User $user, Enterprise $empresa): array
    {
        $asignados = UserEntityAccess::where('user_id', $user->id)
            ->where('enterprise_id', $empresa->id)
            ->pluck('entity_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $almacenes = Entity::with(['entityType:id,name', 'branch:id,name'])
            ->whereIn('id', $this->almacenes->idsDeEmpresa($empresa))
            ->orderBy('name')
            ->get()
            ->map(fn (Entity $e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name' => $e->name,
                'tipo' => $e->entityType?->name,
                'sucursal' => $e->branch?->name,
                'asignado' => in_array($e->id, $asignados, true),
            ])
            ->values();

        return [
            'enterprise' => $empresa->only(['id', 'name', 'slug']),
            'ver_todos' => $this->almacenes->puedeVerTodos($user, $empresa),
            'almacenes' => $almacenes,
        ];
    }
}
