<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\BranchUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Enterprise;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BranchController extends Controller
{
    private function getCurrentEnterprise(Request $request): ?Enterprise
    {
        $slug = $request->header('X-Enterprise-Slug');

        if (!$slug) {
            return null;
        }

        return Enterprise::where('slug', $slug)->first();
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Branch::with(['enterprise', 'entities']);

        if ($currentEnterprise = $this->getCurrentEnterprise($request)) {
            $query->where('enterprise_id', $currentEnterprise->id);
        }

        $branches = $query
            ->orderBy('is_main', 'desc')
            ->orderBy('name')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $branches
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'nullable|exists:enterprises,id',
            'code' => 'nullable|string|max:50|unique:branches,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:branches,slug',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_main' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        if ($currentEnterprise = $this->getCurrentEnterprise($request)) {
            $validated['enterprise_id'] = $currentEnterprise->id;
        } elseif (empty($validated['enterprise_id'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo determinar la empresa actual desde el header X-Enterprise-Slug',
            ], 422);
        }

        $validated['slug'] = $this->generateUniqueBranchSlug(
            $validated['slug'] ?? null,
            $validated['name'] ?? ''
        );

        $isAutoCode = empty($validated['code']);
        $maxAttempts = $isAutoCode ? 5 : 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($isAutoCode) {
                $validated['code'] = $this->generateNextBranchCode('SUC');
            }

            try {
                $branch = Branch::create($validated);
                $branch->load(['enterprise', 'entities']);

                // Broadcast evento en tiempo real
                broadcast(new BranchUpdated('created', $branch->toArray()));

                return response()->json([
                    'success' => true,
                    'message' => 'Sucursal creada exitosamente',
                    'data' => $branch
                ], 201);
            } catch (QueryException $e) {
                if ((int) $e->getCode() !== 23000) {
                    throw $e;
                }

                $constraint = $this->getUniqueConstraintName($e);

                if ($this->isSlugConstraint($constraint)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Ya existe una sucursal con ese nombre/slug. Usa otro nombre o captura un slug distinto.',
                    ], 422);
                }

                if ($this->isCodeConstraint($constraint)) {
                    if ($isAutoCode && $attempt < $maxAttempts) {
                        continue;
                    }

                    return response()->json([
                        'status' => 'error',
                        'message' => 'El código de sucursal ya existe. Intenta nuevamente o captura un código distinto.',
                    ], 422);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'No se pudo crear la sucursal por un conflicto de datos únicos.',
                ], 422);
            }
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No se pudo crear la sucursal. Intenta nuevamente.',
        ], 422);
    }

    private function generateNextBranchCode(string $prefix): string
    {
        // Incluye soft-deleted para no reciclar códigos previamente usados.
        $lastCode = Branch::withTrashed()
            ->where('code', 'like', $prefix . '-%')
            ->select('code')
            ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
            ->value('code');

        $nextNumber = $lastCode
            ? ((int) substr($lastCode, strlen($prefix) + 1)) + 1
            : 1;

        $candidate = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

        while (Branch::withTrashed()->where('code', $candidate)->exists()) {
            $nextNumber++;
            $candidate = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }

    private function generateUniqueBranchSlug(?string $requestedSlug, string $name): string
    {
        $base = Str::slug($requestedSlug ?: $name);
        if ($base === '') {
            $base = 'sucursal';
        }

        $slug = $base;
        $counter = 2;

        while (Branch::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function getUniqueConstraintName(QueryException $e): string
    {
        return strtolower((string) ($e->errorInfo[2] ?? ''));
    }

    private function isCodeConstraint(string $constraint): bool
    {
        return str_contains($constraint, 'branches.branches_code_unique') || str_contains($constraint, 'code');
    }

    private function isSlugConstraint(string $constraint): bool
    {
        return str_contains($constraint, 'branches.branches_slug_unique') || str_contains($constraint, 'slug');
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch): JsonResponse
    {
        $branch->load(['enterprise', 'entities.entityType', 'entities.areas']);

        return response()->json([
            'success' => true,
            'data' => $branch
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Branch $branch): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:branches,code,' . $branch->id,
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:branches,slug,' . $branch->id,
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'is_main' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        $branch->update($validated);
        
        // Recargar la sucursal con sus relaciones
        $branch = $branch->fresh(['enterprise', 'entities']);

        // Broadcast evento en tiempo real
        broadcast(new BranchUpdated('updated', $branch->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Sucursal actualizada exitosamente',
            'data' => $branch
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Branch $branch): JsonResponse
    {
        $branchData = $branch->toArray();
        $branch->delete();

        // Broadcast evento en tiempo real
        broadcast(new BranchUpdated('deleted', $branchData));

        return response()->json([
            'success' => true,
            'message' => 'Sucursal eliminada exitosamente'
        ]);
    }
}
