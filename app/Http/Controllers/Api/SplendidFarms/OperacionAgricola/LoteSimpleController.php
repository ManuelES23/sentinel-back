<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Lote;
use App\Models\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller simplificado de Lotes para Operación Agrícola.
 * Alta sencilla: nombre, superficie, productor, zona.
 * Los datos completos (coordenadas, mapas) se gestionan en Administración.
 */
class LoteSimpleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Temporada obligatoria para context OA
        if (!$request->filled('temporada_id')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $temporada = Temporada::findOrFail($request->temporada_id);
        $loteIds = $temporada->lotesActivos()->pluck('lotes.id');
        $query = Lote::with([
            'productor:id,nombre,apellido,tipo',
            'zonaCultivo:id,nombre',
            'etapas:id,lote_id,nombre,codigo,superficie,orden,is_active',
        ])->whereIn('id', $loteIds);

        if ($request->filled('productor_id')) {
            $query->where('productor_id', $request->productor_id);
        }

        if ($request->filled('zona_cultivo_id')) {
            $query->where('zona_cultivo_id', $request->zona_cultivo_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%");
            });
        }

        $lotes = $query->orderBy('numero_lote', 'desc')->get();

        return response()->json(['success' => true, 'data' => $lotes]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'productor_id' => 'required|exists:productores,id',
            'superficie' => 'nullable|numeric|min:0',
            'superficie_calculada' => 'nullable|numeric|min:0',
            'descripcion' => 'nullable|string',
            'temporada_id' => 'nullable|exists:temporadas,id',
            'coordenadas' => 'nullable|array',
            'coordenadas.*' => 'array|size:2',
            'centro_lat' => 'nullable|numeric',
            'centro_lng' => 'nullable|numeric',
        ]);

        $temporadaId = $validated['temporada_id'] ?? null;
        unset($validated['temporada_id']);

        // Auto-set superficie from superficie_calculada when coordinates exist
        if (!empty($validated['coordenadas']) && count($validated['coordenadas']) >= 3) {
            if (!empty($validated['superficie_calculada']) && $validated['superficie_calculada'] > 0) {
                $validated['superficie'] = $validated['superficie_calculada'];
            }
        }

        $lote = Lote::create($validated);
        $lote->load([
            'productor:id,nombre,apellido,tipo',
            'zonaCultivo:id,nombre',
            'etapas:id,lote_id,nombre,codigo,superficie,orden,is_active',
        ]);

        // Vincular a la temporada si se proporcionó
        if ($temporadaId) {
            $temporada = Temporada::findOrFail($temporadaId);
            $temporada->asignarLote($lote->id, $temporada->cultivo_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lote registrado.',
            'data' => $lote,
        ], 201);
    }

    public function show(Lote $lote): JsonResponse
    {
        $lote->load([
            'productor:id,nombre,apellido,tipo',
            'zonaCultivo:id,nombre',
            'etapas:id,lote_id,nombre,codigo,superficie,orden,is_active',
        ]);

        return response()->json([
            'success' => true,
            'data' => $lote,
        ]);
    }

    public function update(Request $request, Lote $lote): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'productor_id' => 'sometimes|required|exists:productores,id',
            'superficie' => 'nullable|numeric|min:0',
            'superficie_calculada' => 'nullable|numeric|min:0',
            'descripcion' => 'nullable|string',
            'coordenadas' => 'nullable|array',
            'coordenadas.*' => 'array|size:2',
            'centro_lat' => 'nullable|numeric',
            'centro_lng' => 'nullable|numeric',
        ]);

        // Auto-set superficie from superficie_calculada when coordinates exist
        $coordenadas = $validated['coordenadas'] ?? $lote->coordenadas;
        if (!empty($coordenadas) && count($coordenadas) >= 3) {
            if (!empty($validated['superficie_calculada']) && $validated['superficie_calculada'] > 0) {
                $validated['superficie'] = $validated['superficie_calculada'];
            }
        }

        $lote->update($validated);
        $lote->load([
            'productor:id,nombre,apellido,tipo',
            'zonaCultivo:id,nombre',
            'etapas:id,lote_id,nombre,codigo,superficie,orden,is_active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lote actualizado',
            'data' => $lote->fresh(),
        ]);
    }

    public function destroy(Lote $lote): JsonResponse
    {
        $lote->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lote eliminado',
        ]);
    }
}
