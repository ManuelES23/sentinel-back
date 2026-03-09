<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\EtapaFenologica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EtapaFenologicaController extends Controller
{
    /**
     * Listar etapas fenológicas por cultivo.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'cultivo_id' => 'required|exists:cultivos,id',
        ]);

        $etapas = EtapaFenologica::byCultivo($request->cultivo_id)
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $etapas,
        ]);
    }

    /**
     * Crear etapa fenológica.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cultivo_id' => 'required|exists:cultivos,id',
            'nombre' => 'required|string|max:100',
            'orden' => 'integer|min:0',
            'descripcion' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ]);

        // Verificar unicidad cultivo + nombre
        $exists = EtapaFenologica::where('cultivo_id', $validated['cultivo_id'])
            ->where('nombre', $validated['nombre'])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ya existe una etapa fenológica con ese nombre para este cultivo.',
            ], 422);
        }

        // Auto-asignar orden si no se envía
        if (!isset($validated['orden'])) {
            $validated['orden'] = EtapaFenologica::where('cultivo_id', $validated['cultivo_id'])
                ->max('orden') + 1;
        }

        $etapa = EtapaFenologica::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Etapa fenológica creada exitosamente.',
            'data' => $etapa,
        ], 201);
    }

    /**
     * Ver detalle de etapa fenológica.
     */
    public function show(EtapaFenologica $etapasFenologica): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $etapasFenologica,
        ]);
    }

    /**
     * Actualizar etapa fenológica.
     */
    public function update(Request $request, EtapaFenologica $etapasFenologica): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:100',
            'orden' => 'integer|min:0',
            'descripcion' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'is_active' => 'boolean',
        ]);

        // Verificar unicidad si se cambió el nombre
        if (isset($validated['nombre']) && $validated['nombre'] !== $etapasFenologica->nombre) {
            $exists = EtapaFenologica::where('cultivo_id', $etapasFenologica->cultivo_id)
                ->where('nombre', $validated['nombre'])
                ->where('id', '!=', $etapasFenologica->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ya existe otra etapa fenológica con ese nombre para este cultivo.',
                ], 422);
            }
        }

        $etapasFenologica->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Etapa fenológica actualizada exitosamente.',
            'data' => $etapasFenologica->fresh(),
        ]);
    }

    /**
     * Eliminar etapa fenológica.
     */
    public function destroy(EtapaFenologica $etapasFenologica): JsonResponse
    {
        $etapasFenologica->delete();

        return response()->json([
            'success' => true,
            'message' => 'Etapa fenológica eliminada exitosamente.',
        ]);
    }
}
