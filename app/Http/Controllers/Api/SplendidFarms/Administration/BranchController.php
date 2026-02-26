<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Events\BranchUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $branches = Branch::with(['enterprise', 'entities'])
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
            'enterprise_id' => 'required|exists:enterprises,id',
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

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = 'SUC';
            
            // Obtener el último código con este prefijo
            $lastBranch = Branch::where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();
            
            if ($lastBranch) {
                // Extraer el número del último código
                $lastNumber = (int) substr($lastBranch->code, strlen($prefix) + 1);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }
            
            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        $branch = Branch::create($validated);
        $branch->load(['enterprise', 'entities']);

        // Broadcast evento en tiempo real
        broadcast(new BranchUpdated('created', $branch->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Sucursal creada exitosamente',
            'data' => $branch
        ], 201);
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
