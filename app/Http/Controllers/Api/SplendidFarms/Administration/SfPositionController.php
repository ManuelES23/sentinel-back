<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfPosition;
use App\Models\SfPositionGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SfPositionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SfPosition::query()
            ->with(['group:id,code,name,salary'])
            ->when($request->enterprise_id, fn($q, $v) => $q->where('enterprise_id', $v))
            ->when($request->sf_position_group_id, fn($q, $v) => $q->where('sf_position_group_id', $v))
            ->when($request->is_active !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('department', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $perPage = (int) $request->get('per_page', 15);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $positions = SfPosition::query()
            ->with(['group:id,code,name,salary'])
            ->when($request->enterprise_id, fn($q, $v) => $q->where('enterprise_id', $v))
            ->active()
            ->select('id', 'code', 'name', 'sf_position_group_id', 'department')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $positions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);
        $this->assertGroupBelongsToEnterprise($validated['sf_position_group_id'], (int) $validated['enterprise_id']);

        $validated['code'] = SfPosition::generateCode();
        $validated['is_active'] = $validated['is_active'] ?? true;

        $position = SfPosition::create($validated);
        $position->load(['group:id,code,name,salary']);

        return response()->json([
            'success' => true,
            'message' => 'Puesto creado exitosamente',
            'data' => $position,
        ], 201);
    }

    public function show(SfPosition $puesto): JsonResponse
    {
        $puesto->load(['group:id,code,name,salary']);

        return response()->json([
            'success' => true,
            'data' => $puesto,
        ]);
    }

    public function update(Request $request, SfPosition $puesto): JsonResponse
    {
        $validated = $this->validateData($request, true);

        $enterpriseId = (int) ($validated['enterprise_id'] ?? $puesto->enterprise_id);
        $groupId = (int) ($validated['sf_position_group_id'] ?? $puesto->sf_position_group_id);
        $this->assertGroupBelongsToEnterprise($groupId, $enterpriseId);

        $puesto->update($validated);
        $puesto->load(['group:id,code,name,salary']);

        return response()->json([
            'success' => true,
            'message' => 'Puesto actualizado exitosamente',
            'data' => $puesto,
        ]);
    }

    public function destroy(SfPosition $puesto): JsonResponse
    {
        $puesto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Puesto eliminado exitosamente',
        ]);
    }

    private function validateData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';
        $optional = 'sometimes';

        return $request->validate([
            'enterprise_id' => $required . '|exists:enterprises,id',
            'name' => $required . '|string|max:120',
            'sf_position_group_id' => $required . '|exists:sf_position_groups,id',
            'department' => 'nullable|string|max:100',
            'is_active' => $optional . '|boolean',
            'notes' => 'nullable|string',
        ]);
    }

    private function assertGroupBelongsToEnterprise(int $groupId, int $enterpriseId): void
    {
        $belongs = SfPositionGroup::query()
            ->where('id', $groupId)
            ->where('enterprise_id', $enterpriseId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'sf_position_group_id' => ['El grupo salarial no pertenece a la empresa seleccionada'],
            ]);
        }
    }
}
