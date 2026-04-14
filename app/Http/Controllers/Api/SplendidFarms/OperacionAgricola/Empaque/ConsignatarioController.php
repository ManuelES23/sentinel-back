<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\Consignatario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsignatarioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Consignatario::query();

        if ($request->filled('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('nombre', 'like', "%{$s}%")
                  ->orWhere('rfc_tax_id', 'like', "%{$s}%")
                  ->orWhere('ciudad', 'like', "%{$s}%");
            });
        }

        $consignatarios = $query->orderBy('nombre')->get();

        return response()->json(['success' => true, 'data' => $consignatarios]);
    }

    public function list(Request $request): JsonResponse
    {
        $query = Consignatario::active()->select('id', 'nombre', 'rfc_tax_id', 'direccion', 'ciudad', 'pais', 'agente_aduana', 'bodega');

        if ($request->filled('enterprise_id')) {
            $query->where('enterprise_id', $request->enterprise_id);
        }

        $consignatarios = $query->orderBy('nombre')->get();

        return response()->json(['success' => true, 'data' => $consignatarios]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|exists:enterprises,id',
            'nombre' => 'required|string|max:200',
            'rfc_tax_id' => 'nullable|string|max:50',
            'direccion' => 'nullable|string|max:300',
            'ciudad' => 'nullable|string|max:100',
            'pais' => 'nullable|string|max:100',
            'agente_aduana' => 'nullable|string|max:200',
            'bodega' => 'nullable|string|max:200',
        ]);

        $consignatario = Consignatario::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Consignatario creado',
            'data' => $consignatario,
        ], 201);
    }

    public function update(Request $request, Consignatario $consignatario): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|string|max:200',
            'rfc_tax_id' => 'nullable|string|max:50',
            'direccion' => 'nullable|string|max:300',
            'ciudad' => 'nullable|string|max:100',
            'pais' => 'nullable|string|max:100',
            'agente_aduana' => 'nullable|string|max:200',
            'bodega' => 'nullable|string|max:200',
            'is_active' => 'sometimes|boolean',
        ]);

        $consignatario->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Consignatario actualizado',
            'data' => $consignatario,
        ]);
    }

    public function destroy(Consignatario $consignatario): JsonResponse
    {
        $consignatario->delete();

        return response()->json(['success' => true, 'message' => 'Consignatario eliminado']);
    }
}
