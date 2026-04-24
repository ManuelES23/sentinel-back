<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\SfPositionGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SfPositionGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SfPositionGroup::query()
            ->withCount('positions')
            ->when($request->enterprise_id, fn($q, $v) => $q->where('enterprise_id', $v))
            ->when($request->is_active !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderBy('code');

        $perPage = (int) $request->get('per_page', 15);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $groups = SfPositionGroup::query()
            ->when($request->enterprise_id, fn($q, $v) => $q->where('enterprise_id', $v))
            ->active()
            ->select('id', 'code', 'name', 'salary')
            ->orderBy('code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);

        $validated['code'] = strtoupper($validated['code']);
        $validated['name'] = $validated['name'] ?? ('Grupo ' . $validated['code']);
        $validated['is_active'] = $validated['is_active'] ?? true;

        $group = SfPositionGroup::create($validated);
        $group->loadCount('positions');

        return response()->json([
            'success' => true,
            'message' => 'Grupo salarial creado exitosamente',
            'data' => $group,
        ], 201);
    }

    public function show(SfPositionGroup $grupo): JsonResponse
    {
        $grupo->loadCount('positions');

        return response()->json([
            'success' => true,
            'data' => $grupo,
        ]);
    }

    public function update(Request $request, SfPositionGroup $grupo): JsonResponse
    {
        $validated = $this->validateData($request, $grupo->id, true, (int) $grupo->enterprise_id);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }
        if (array_key_exists('name', $validated) && empty($validated['name']) && isset($validated['code'])) {
            $validated['name'] = 'Grupo ' . $validated['code'];
        }

        $grupo->update($validated);
        $grupo->loadCount('positions');

        return response()->json([
            'success' => true,
            'message' => 'Grupo salarial actualizado exitosamente',
            'data' => $grupo,
        ]);
    }

    public function destroy(SfPositionGroup $grupo): JsonResponse
    {
        if ($grupo->positions()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el grupo porque tiene puestos asignados',
            ], 422);
        }

        $grupo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Grupo salarial eliminado exitosamente',
        ]);
    }

    private function validateData(
        Request $request,
        ?int $ignoreId = null,
        bool $partial = false,
        ?int $currentEnterpriseId = null
    ): array
    {
        $required = $partial ? 'sometimes|required' : 'required';
        $optional = 'sometimes';
        $enterpriseId = (int) ($request->enterprise_id ?? $currentEnterpriseId ?? 0);

        return $request->validate([
            'enterprise_id' => $required . '|exists:enterprises,id',
            'code' => [
                $required,
                'string',
                'max:30',
                'regex:/^[A-Za-z]+$/',
                Rule::unique('sf_position_groups', 'code')
                    ->ignore($ignoreId)
                    ->where(fn($q) => $q->where('enterprise_id', $enterpriseId)),
            ],
            'name' => 'nullable|string|max:100',
            'salary' => $required . '|numeric|min:0',
            'is_active' => $optional . '|boolean',
            'notes' => 'nullable|string',
        ]);
    }
}
