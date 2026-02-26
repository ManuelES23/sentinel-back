<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Events\LoteUpdated;
use App\Http\Controllers\Controller;
use App\Models\Lote;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Lote::with(['productor:id,nombre,apellido,tipo', 'zonaCultivo:id,nombre']);

        // Filtrar por productor si se especifica
        if ($request->has('productor_id')) {
            $query->where('productor_id', $request->productor_id);
        }

        // Filtrar por zona de cultivo si se especifica
        if ($request->has('zona_cultivo_id')) {
            $query->where('zona_cultivo_id', $request->zona_cultivo_id);
        }

        $lotes = $query->orderBy('numero_lote', 'desc')->get();
        
        // Agregar atributos calculados a cada lote
        $lotes->each(function ($lote) {
            $lote->append(['superficie_efectiva', 'tiene_ubicacion', 'fuente_superficie']);
        });
        
        return response()->json([
            'success' => true,
            'data' => $lotes
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'productor_id' => 'required|exists:productores,id',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'nombre' => 'required|string|max:255',
            'codigo' => 'nullable|string|max:50',
            'superficie' => 'nullable|numeric|min:0',
            'coordenadas' => 'nullable|array',
            'coordenadas.*' => 'array|size:2',
            'coordenadas.*.*' => 'numeric',
            'centro_lat' => 'nullable|numeric|between:-90,90',
            'centro_lng' => 'nullable|numeric|between:-180,180',
            'superficie_calculada' => 'nullable|numeric|min:0',
            'tipo_suelo' => 'nullable|string|max:100',
            'sistema_riego' => 'nullable|string|max:100',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // numero_lote y codigo se generan automáticamente en el modelo
        $lote = Lote::create($validated);
        $lote->load(['productor:id,nombre,apellido,tipo', 'zonaCultivo:id,nombre']);
        $lote->append(['superficie_efectiva', 'tiene_ubicacion', 'fuente_superficie']);

        broadcast(new LoteUpdated('created', $lote->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Lote creado exitosamente',
            'data' => $lote
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Lote $lote): JsonResponse
    {
        $lote->load(['productor:id,nombre,apellido,tipo', 'zonaCultivo:id,nombre']);
        
        return response()->json([
            'success' => true,
            'data' => $lote
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Lote $lote): JsonResponse
    {
        $validated = $request->validate([
            'productor_id' => 'sometimes|required|exists:productores,id',
            'zona_cultivo_id' => 'nullable|exists:zonas_cultivo,id',
            'nombre' => 'sometimes|required|string|max:255',
            'codigo' => 'nullable|string|max:50',
            'superficie' => 'nullable|numeric|min:0',
            'coordenadas' => 'nullable|array',
            'coordenadas.*' => 'array|size:2',
            'coordenadas.*.*' => 'numeric',
            'centro_lat' => 'nullable|numeric|between:-90,90',
            'centro_lng' => 'nullable|numeric|between:-180,180',
            'superficie_calculada' => 'nullable|numeric|min:0',
            'tipo_suelo' => 'nullable|string|max:100',
            'sistema_riego' => 'nullable|string|max:100',
            'descripcion' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $lote->update($validated);
        $lote->load(['productor:id,nombre,apellido,tipo', 'zonaCultivo:id,nombre']);
        $lote->append(['superficie_efectiva', 'tiene_ubicacion', 'fuente_superficie']);

        broadcast(new LoteUpdated('updated', $lote->toArray()));

        return response()->json([
            'success' => true,
            'message' => 'Lote actualizado exitosamente',
            'data' => $lote
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Lote $lote): JsonResponse
    {
        $loteData = $lote->toArray();
        /** @phpstan-ignore-next-line */
        $lote->delete();

        broadcast(new LoteUpdated('deleted', $loteData));

        return response()->json([
            'success' => true,
            'message' => 'Lote eliminado exitosamente'
        ]);
    }

    /**
     * Get lotes by productor.
     */
    public function byProductor(int $productorId): JsonResponse
    {
        $lotes = Lote::where('productor_id', $productorId)
            ->active()
            ->orderBy('numero_lote', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $lotes
        ]);
    }

    /**
     * Get siguiente número de lote.
     */
    public function siguienteNumero(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'siguiente_numero' => Lote::generarSiguienteNumeroLote()
            ]
        ]);
    }
}
