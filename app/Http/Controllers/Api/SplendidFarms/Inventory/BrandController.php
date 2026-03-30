<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Brand::withCount('products');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $brands = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $brands
        ]);
    }

    public function list(): JsonResponse
    {
        $brands = Brand::active()->orderBy('name')->get(['id', 'code', 'name']);

        return response()->json([
            'success' => true,
            'data' => $brands
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:brands,code',
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        if (empty($validated['code'])) {
            $prefix = 'MRC';
            $lastBrand = Brand::withTrashed()
                ->where('code', 'like', $prefix . '-%')
                ->orderByRaw('CAST(SUBSTRING(code, ' . (strlen($prefix) + 2) . ') AS UNSIGNED) DESC')
                ->first();

            $nextNumber = $lastBrand
                ? (int) substr($lastBrand->code, strlen($prefix) + 1) + 1
                : 1;

            $validated['code'] = $prefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        $brand = Brand::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Marca creada exitosamente',
            'data' => $brand
        ], 201);
    }

    public function show(Brand $brand): JsonResponse
    {
        $brand->loadCount('products');

        return response()->json([
            'success' => true,
            'data' => $brand
        ]);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'boolean',
        ]);

        $brand->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Marca actualizada exitosamente',
            'data' => $brand
        ]);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        if ($brand->products()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar una marca con productos asociados'
            ], 422);
        }

        $brand->delete();

        return response()->json([
            'success' => true,
            'message' => 'Marca eliminada exitosamente'
        ]);
    }
}
