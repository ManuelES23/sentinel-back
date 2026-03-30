<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Temporada;
use App\Models\Variedad;
use App\Models\TipoVariedad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EtapaController extends Controller
{
    /**
     * Listar etapas, opcionalmente filtradas por lote y/o temporada.
     */
    public function index(Request $request): JsonResponse
    {
        // Temporada obligatoria para context OA
        if (!$request->filled('temporada_id')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $temporada = Temporada::findOrFail($request->temporada_id);
        $loteIds = $temporada->lotesActivos()->pluck('lotes.id');
        $query = Etapa::with(['lote:id,nombre,codigo,superficie,numero_lote,zona_cultivo_id,productor_id', 'lote.productor:id,nombre,apellido', 'lote.zonaCultivo:id,nombre', 'variedad:id,nombre,cultivo_id', 'tipoVariedad:id,nombre,variedad_id'])
            ->whereIn('lote_id', $loteIds);

        if ($request->filled('lote_id')) {
            $query->where('lote_id', $request->lote_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('codigo', 'like', "%{$search}%");
            });
        }

        $etapas = $query->orderBy('lote_id')->orderBy('orden')->get();

        return response()->json([
            'success' => true,
            'data' => $etapas,
        ]);
    }

    /**
     * Crear una nueva etapa.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lote_id' => 'required|exists:lotes,id',
            'nombre' => 'required|string|max:255',
            'codigo' => 'nullable|string|max:50',
            'superficie' => 'required|numeric|min:0.01',
            'variedad_id' => 'nullable|exists:variedades,id',
            'tipo_variedad_id' => 'nullable|exists:tipos_variedad,id',
            'fecha_siembra_estimada' => 'nullable|date',
            'fecha_cosecha_estimada' => 'nullable|date|after_or_equal:fecha_siembra_estimada',
            'fecha_siembra_real' => 'nullable|date',
            'fecha_cosecha_proyectada' => 'nullable|date|after_or_equal:fecha_siembra_real',
            'descripcion' => 'nullable|string',
            'orden' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'temporada_id' => 'nullable|exists:temporadas,id',
        ]);

        // Validar que el lote pertenece a la temporada si se proporcionó
        $temporada = null;
        if (!empty($validated['temporada_id'])) {
            $temporada = Temporada::findOrFail($validated['temporada_id']);
            $loteEnTemporada = $temporada->lotesActivos()->where('lotes.id', $validated['lote_id'])->exists();
            if (!$loteEnTemporada) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El lote seleccionado no pertenece a la temporada activa',
                ], 422);
            }
        }

        // Validar que la variedad pertenece al cultivo de la temporada
        if (!empty($validated['variedad_id']) && $temporada) {
            $variedad = Variedad::findOrFail($validated['variedad_id']);
            if ($variedad->cultivo_id !== $temporada->cultivo_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La variedad seleccionada no pertenece al cultivo de la temporada',
                ], 422);
            }
        }

        // Validar que el tipo de variedad pertenece a la variedad seleccionada
        if (!empty($validated['tipo_variedad_id']) && !empty($validated['variedad_id'])) {
            $tipoVariedad = TipoVariedad::findOrFail($validated['tipo_variedad_id']);
            if ($tipoVariedad->variedad_id != $validated['variedad_id']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El tipo de variedad no pertenece a la variedad seleccionada',
                ], 422);
            }
        }

        unset($validated['temporada_id']);

        // Validar que la superficie no exceda la disponible en el lote
        $disponible = Etapa::superficieDisponible($validated['lote_id']);
        if ($validated['superficie'] > $disponible) {
            return response()->json([
                'status' => 'error',
                'message' => "La superficie ({$validated['superficie']} ha) excede la disponible en el lote ({$disponible} ha)",
            ], 422);
        }

        // Auto-generar código si no se proporcionó
        if (empty($validated['codigo'])) {
            $lote = Lote::findOrFail($validated['lote_id']);
            $count = Etapa::where('lote_id', $validated['lote_id'])->withTrashed()->count();
            $validated['codigo'] = "L{$lote->numero_lote}-E" . ($count + 1);
        }

        // Auto-asignar orden si no se proporcionó
        if (!isset($validated['orden'])) {
            $validated['orden'] = Etapa::where('lote_id', $validated['lote_id'])->max('orden') + 1;
        }

        // Limpiar tipo_variedad_id si no hay variedad
        if (empty($validated['variedad_id'])) {
            $validated['tipo_variedad_id'] = null;
        }

        $etapa = Etapa::create($validated);
        $etapa->load(['lote:id,nombre,codigo,superficie,numero_lote,zona_cultivo_id,productor_id', 'lote.productor:id,nombre,apellido', 'lote.zonaCultivo:id,nombre', 'variedad:id,nombre,cultivo_id', 'tipoVariedad:id,nombre,variedad_id']);

        return response()->json([
            'success' => true,
            'message' => 'Etapa creada exitosamente',
            'data' => $etapa,
        ], 201);
    }

    /**
     * Mostrar una etapa específica.
     */
    public function show(Etapa $etapa): JsonResponse
    {
        $etapa->load(['lote:id,nombre,codigo,superficie,numero_lote,zona_cultivo_id,productor_id', 'lote.productor:id,nombre,apellido', 'lote.zonaCultivo:id,nombre', 'variedad:id,nombre,cultivo_id', 'tipoVariedad:id,nombre,variedad_id']);

        return response()->json([
            'success' => true,
            'data' => $etapa,
        ]);
    }

    /**
     * Actualizar una etapa.
     */
    public function update(Request $request, Etapa $etapa): JsonResponse
    {
        $validated = $request->validate([
            'lote_id' => 'sometimes|required|exists:lotes,id',
            'nombre' => 'sometimes|required|string|max:255',
            'codigo' => 'nullable|string|max:50',
            'superficie' => 'sometimes|required|numeric|min:0.01',
            'variedad_id' => 'nullable|exists:variedades,id',
            'tipo_variedad_id' => 'nullable|exists:tipos_variedad,id',
            'fecha_siembra_estimada' => 'nullable|date',
            'fecha_cosecha_estimada' => 'nullable|date|after_or_equal:fecha_siembra_estimada',
            'fecha_siembra_real' => 'nullable|date',
            'fecha_cosecha_proyectada' => 'nullable|date|after_or_equal:fecha_siembra_real',
            'descripcion' => 'nullable|string',
            'orden' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'temporada_id' => 'nullable|exists:temporadas,id',
        ]);

        // Si cambia la superficie, validar contra el lote
        if (isset($validated['superficie'])) {
            $loteId = $validated['lote_id'] ?? $etapa->lote_id;
            $disponible = Etapa::superficieDisponible($loteId, $etapa->id);
            if ($validated['superficie'] > $disponible) {
                return response()->json([
                    'status' => 'error',
                    'message' => "La superficie ({$validated['superficie']} ha) excede la disponible en el lote ({$disponible} ha)",
                ], 422);
            }
        }

        // Validar que la variedad pertenece al cultivo de la temporada
        if (!empty($validated['variedad_id']) && !empty($validated['temporada_id'])) {
            $temporada = Temporada::findOrFail($validated['temporada_id']);
            $variedad = Variedad::findOrFail($validated['variedad_id']);
            if ($variedad->cultivo_id !== $temporada->cultivo_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La variedad seleccionada no pertenece al cultivo de la temporada',
                ], 422);
            }
        }

        // Validar que el tipo de variedad pertenece a la variedad seleccionada
        $variedadId = $validated['variedad_id'] ?? $etapa->variedad_id;
        if (!empty($validated['tipo_variedad_id']) && $variedadId) {
            $tipoVariedad = TipoVariedad::findOrFail($validated['tipo_variedad_id']);
            if ($tipoVariedad->variedad_id != $variedadId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El tipo de variedad no pertenece a la variedad seleccionada',
                ], 422);
            }
        }

        // Limpiar tipo_variedad_id si se quita la variedad
        if (array_key_exists('variedad_id', $validated) && empty($validated['variedad_id'])) {
            $validated['tipo_variedad_id'] = null;
        }

        unset($validated['temporada_id']);

        $etapa->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Etapa actualizada exitosamente',
            'data' => $etapa->fresh()->load(['lote:id,nombre,codigo,superficie,numero_lote,zona_cultivo_id,productor_id', 'lote.productor:id,nombre,apellido', 'lote.zonaCultivo:id,nombre', 'variedad:id,nombre,cultivo_id', 'tipoVariedad:id,nombre,variedad_id']),
        ]);
    }

    /**
     * Eliminar una etapa.
     */
    public function destroy(Etapa $etapa): JsonResponse
    {
        $etapa->delete();

        return response()->json([
            'success' => true,
            'message' => 'Etapa eliminada exitosamente',
        ]);
    }

    /**
     * Superficie disponible para un lote (útil en formularios).
     */
    public function superficieDisponible(Request $request): JsonResponse
    {
        $request->validate([
            'lote_id' => 'required|exists:lotes,id',
            'exclude_id' => 'nullable|exists:etapas,id',
        ]);

        $loteId = $request->lote_id;
        $excludeId = $request->exclude_id;

        $lote = Lote::findOrFail($loteId);
        $superficieTotal = (float) ($lote->superficie ?? 0);

        $queryAsignada = Etapa::where('lote_id', $loteId)->whereNull('deleted_at');
        if ($excludeId) {
            $queryAsignada->where('id', '!=', $excludeId);
        }
        $superficieAsignada = (float) $queryAsignada->sum('superficie');
        $superficieDisponible = max(0, $superficieTotal - $superficieAsignada);

        return response()->json([
            'success' => true,
            'data' => [
                'superficie_total' => $superficieTotal,
                'superficie_asignada' => $superficieAsignada,
                'superficie_disponible' => $superficieDisponible,
            ],
        ]);
    }
}
