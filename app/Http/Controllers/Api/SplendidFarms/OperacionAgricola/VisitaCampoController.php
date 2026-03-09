<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\VisitaCampo;
use App\Models\VisitaCampoDetalle;
use App\Models\VisitaCampoFoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VisitaCampoController extends Controller
{
    /**
     * Listar visitas filtradas por temporada.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $query = VisitaCampo::byTemporada($request->temporada_id)
            ->with([
                'user:id,name',
                'detalles.etapa:id,nombre,codigo,lote_id',
                'detalles.etapa.lote:id,nombre',
                'detalles.plagas',
                'detalles.recomendaciones',
            ])
            ->withCount('detalles');

        // Filtros opcionales
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('fecha_desde')) {
            $query->where('fecha_visita', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->where('fecha_visita', '<=', $request->fecha_hasta);
        }
        if ($request->filled('lote_id')) {
            $query->whereHas('detalles.etapa', function ($q) use ($request) {
                $q->where('lote_id', $request->lote_id);
            });
        }
        if ($request->filled('etapa_id')) {
            $query->whereHas('detalles', function ($q) use ($request) {
                $q->where('etapa_id', $request->etapa_id);
            });
        }

        $visitas = $query->orderByDesc('fecha_visita')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $visitas,
        ]);
    }

    /**
     * Crear visita con detalles anidados (transaccional).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'fecha_visita' => 'required|date',
            'observaciones_generales' => 'nullable|string',
            'status' => 'in:borrador,completada',
            'detalles' => 'required|array|min:1',
            'detalles.*.etapa_id' => 'required|exists:etapas,id',
            'detalles.*.etapa_fenologica_id' => 'nullable|exists:etapas_fenologicas,id',
            'detalles.*.poblacion_plantas_ha' => 'nullable|integer|min:0',
            'detalles.*.fecha_siembra_real' => 'nullable|date',
            'detalles.*.fecha_cosecha_proyectada' => 'nullable|date|after_or_equal:detalles.*.fecha_siembra_real',
            'detalles.*.observaciones' => 'nullable|string',
            'detalles.*.recomendaciones_generales' => 'nullable|string',
            // Plagas anidadas
            'detalles.*.plagas' => 'nullable|array',
            'detalles.*.plagas.*.plaga_id' => 'required|exists:plagas,id',
            'detalles.*.plagas.*.severidad' => 'required|in:baja,media,alta,critica',
            'detalles.*.plagas.*.area_afectada_porcentaje' => 'nullable|numeric|min:0|max:100',
            'detalles.*.plagas.*.observaciones' => 'nullable|string',
            // Recomendaciones anidadas
            'detalles.*.recomendaciones' => 'nullable|array',
            'detalles.*.recomendaciones.*.product_id' => 'nullable|exists:products,id',
            'detalles.*.recomendaciones.*.nombre_producto' => 'required|string|max:255',
            'detalles.*.recomendaciones.*.dosis' => 'required|numeric|min:0',
            'detalles.*.recomendaciones.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'detalles.*.recomendaciones.*.metodo_aplicacion' => 'nullable|string|max:100',
            'detalles.*.recomendaciones.*.prioridad' => 'in:baja,media,alta,urgente',
            'detalles.*.recomendaciones.*.observaciones' => 'nullable|string',
        ]);

        $visita = DB::transaction(function () use ($validated) {
            $visita = VisitaCampo::create([
                'temporada_id' => $validated['temporada_id'],
                'user_id' => Auth::id(),
                'fecha_visita' => $validated['fecha_visita'],
                'observaciones_generales' => $validated['observaciones_generales'] ?? null,
                'status' => $validated['status'] ?? 'borrador',
            ]);

            foreach ($validated['detalles'] as $detalleData) {
                $detalle = $visita->detalles()->create([
                    'etapa_id' => $detalleData['etapa_id'],
                    'etapa_fenologica_id' => $detalleData['etapa_fenologica_id'] ?? null,
                    'poblacion_plantas_ha' => $detalleData['poblacion_plantas_ha'] ?? null,
                    'fecha_siembra_real' => $detalleData['fecha_siembra_real'] ?? null,
                    'fecha_cosecha_proyectada' => $detalleData['fecha_cosecha_proyectada'] ?? null,
                    'observaciones' => $detalleData['observaciones'] ?? null,
                    'recomendaciones_generales' => $detalleData['recomendaciones_generales'] ?? null,
                ]);

                // Sincronizar fecha_siembra_real → etapa
                if (!empty($detalleData['fecha_siembra_real'])) {
                    Etapa::where('id', $detalleData['etapa_id'])->update([
                        'fecha_siembra_real' => $detalleData['fecha_siembra_real'],
                        'fecha_cosecha_proyectada' => $detalleData['fecha_cosecha_proyectada'] ?? null,
                    ]);
                }

                // Plagas
                if (!empty($detalleData['plagas'])) {
                    foreach ($detalleData['plagas'] as $plagaData) {
                        $detalle->plagas()->create($plagaData);
                    }
                }

                // Recomendaciones
                if (!empty($detalleData['recomendaciones'])) {
                    foreach ($detalleData['recomendaciones'] as $recData) {
                        $detalle->recomendaciones()->create($recData);
                    }
                }
            }

            return $visita;
        });

        // Reload con relaciones completas
        $visita->load([
            'user:id,name',
            'detalles.etapa:id,nombre,codigo,lote_id',
            'detalles.etapa.lote:id,nombre',
            'detalles.etapaFenologica',
            'detalles.plagas.plaga',
            'detalles.recomendaciones.product:id,name,code',
            'detalles.recomendaciones.unit:id,name,abbreviation',
            'detalles.fotos',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Visita de campo registrada exitosamente.',
            'data' => $visita,
        ], 201);
    }

    /**
     * Ver detalle completo de una visita.
     */
    public function show(VisitaCampo $visitasCampo): JsonResponse
    {
        $visitasCampo->load([
            'user:id,name',
            'detalles.etapa:id,nombre,codigo,lote_id,superficie,variedad_id',
            'detalles.etapa.lote:id,nombre',
            'detalles.etapa.variedad:id,nombre',
            'detalles.etapaFenologica',
            'detalles.plagas.plaga',
            'detalles.recomendaciones.product:id,name,code',
            'detalles.recomendaciones.unit:id,name,abbreviation',
            'detalles.fotos',
        ]);

        // Append foto_url a cada foto
        $visitasCampo->detalles->each(function ($detalle) {
            $detalle->fotos->each(function ($foto) {
                $foto->append('foto_url');
            });
        });

        return response()->json([
            'success' => true,
            'data' => $visitasCampo,
        ]);
    }

    /**
     * Actualizar visita (solo borradores). Reemplaza detalles.
     */
    public function update(Request $request, VisitaCampo $visitasCampo): JsonResponse
    {
        if ($visitasCampo->status === 'completada') {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede editar una visita completada.',
            ], 422);
        }

        $validated = $request->validate([
            'fecha_visita' => 'sometimes|required|date',
            'observaciones_generales' => 'nullable|string',
            'status' => 'in:borrador,completada',
            'detalles' => 'sometimes|required|array|min:1',
            'detalles.*.etapa_id' => 'required|exists:etapas,id',
            'detalles.*.etapa_fenologica_id' => 'nullable|exists:etapas_fenologicas,id',
            'detalles.*.poblacion_plantas_ha' => 'nullable|integer|min:0',
            'detalles.*.fecha_siembra_real' => 'nullable|date',
            'detalles.*.fecha_cosecha_proyectada' => 'nullable|date|after_or_equal:detalles.*.fecha_siembra_real',
            'detalles.*.observaciones' => 'nullable|string',
            'detalles.*.recomendaciones_generales' => 'nullable|string',
            'detalles.*.plagas' => 'nullable|array',
            'detalles.*.plagas.*.plaga_id' => 'required|exists:plagas,id',
            'detalles.*.plagas.*.severidad' => 'required|in:baja,media,alta,critica',
            'detalles.*.plagas.*.area_afectada_porcentaje' => 'nullable|numeric|min:0|max:100',
            'detalles.*.plagas.*.observaciones' => 'nullable|string',
            'detalles.*.recomendaciones' => 'nullable|array',
            'detalles.*.recomendaciones.*.product_id' => 'nullable|exists:products,id',
            'detalles.*.recomendaciones.*.nombre_producto' => 'required|string|max:255',
            'detalles.*.recomendaciones.*.dosis' => 'required|numeric|min:0',
            'detalles.*.recomendaciones.*.unit_id' => 'nullable|exists:units_of_measure,id',
            'detalles.*.recomendaciones.*.metodo_aplicacion' => 'nullable|string|max:100',
            'detalles.*.recomendaciones.*.prioridad' => 'in:baja,media,alta,urgente',
            'detalles.*.recomendaciones.*.observaciones' => 'nullable|string',
        ]);

        DB::transaction(function () use ($visitasCampo, $validated) {
            // Actualizar cabecera
            $visitasCampo->update([
                'fecha_visita' => $validated['fecha_visita'] ?? $visitasCampo->fecha_visita,
                'observaciones_generales' => $validated['observaciones_generales'] ?? $visitasCampo->observaciones_generales,
                'status' => $validated['status'] ?? $visitasCampo->status,
            ]);

            // Si se envían detalles, reemplazar todos
            if (isset($validated['detalles'])) {
                // Limpiar fotos huérfanas del storage
                $oldFotos = VisitaCampoFoto::whereIn(
                    'visita_campo_detalle_id',
                    $visitasCampo->detalles()->pluck('id')
                )->get();

                foreach ($oldFotos as $foto) {
                    Storage::disk('public')->delete($foto->foto_path);
                }

                // Eliminar detalles antiguos (cascada borra plagas, recomendaciones, fotos)
                $visitasCampo->detalles()->delete();

                // Crear nuevos detalles
                foreach ($validated['detalles'] as $detalleData) {
                    $detalle = $visitasCampo->detalles()->create([
                        'etapa_id' => $detalleData['etapa_id'],
                        'etapa_fenologica_id' => $detalleData['etapa_fenologica_id'] ?? null,
                        'poblacion_plantas_ha' => $detalleData['poblacion_plantas_ha'] ?? null,
                        'fecha_siembra_real' => $detalleData['fecha_siembra_real'] ?? null,
                        'fecha_cosecha_proyectada' => $detalleData['fecha_cosecha_proyectada'] ?? null,
                        'observaciones' => $detalleData['observaciones'] ?? null,
                        'recomendaciones_generales' => $detalleData['recomendaciones_generales'] ?? null,
                    ]);

                    if (!empty($detalleData['fecha_siembra_real'])) {
                        Etapa::where('id', $detalleData['etapa_id'])->update([
                            'fecha_siembra_real' => $detalleData['fecha_siembra_real'],
                            'fecha_cosecha_proyectada' => $detalleData['fecha_cosecha_proyectada'] ?? null,
                        ]);
                    }

                    if (!empty($detalleData['plagas'])) {
                        foreach ($detalleData['plagas'] as $plagaData) {
                            $detalle->plagas()->create($plagaData);
                        }
                    }

                    if (!empty($detalleData['recomendaciones'])) {
                        foreach ($detalleData['recomendaciones'] as $recData) {
                            $detalle->recomendaciones()->create($recData);
                        }
                    }
                }
            }
        });

        $visitasCampo->load([
            'user:id,name',
            'detalles.etapa:id,nombre,codigo,lote_id',
            'detalles.etapa.lote:id,nombre',
            'detalles.etapaFenologica',
            'detalles.plagas.plaga',
            'detalles.recomendaciones.product:id,name,code',
            'detalles.recomendaciones.unit:id,name,abbreviation',
            'detalles.fotos',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Visita actualizada exitosamente.',
            'data' => $visitasCampo,
        ]);
    }

    /**
     * Eliminar visita.
     */
    public function destroy(VisitaCampo $visitasCampo): JsonResponse
    {
        // Limpiar fotos del storage
        $fotos = VisitaCampoFoto::whereIn(
            'visita_campo_detalle_id',
            $visitasCampo->detalles()->pluck('id')
        )->get();

        foreach ($fotos as $foto) {
            Storage::disk('public')->delete($foto->foto_path);
        }

        $visitasCampo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Visita eliminada exitosamente.',
        ]);
    }

    /**
     * Completar visita (cambiar status).
     */
    public function complete(VisitaCampo $visita): JsonResponse
    {
        if ($visita->status === 'completada') {
            return response()->json([
                'status' => 'error',
                'message' => 'La visita ya está completada.',
            ], 422);
        }

        $visita->update(['status' => 'completada']);

        return response()->json([
            'success' => true,
            'message' => 'Visita completada exitosamente.',
            'data' => $visita->fresh(),
        ]);
    }

    /**
     * Subir fotos a un detalle de visita.
     * POST visitas-campo/{visita}/detalles/{detalle}/fotos
     */
    public function uploadFotos(Request $request, VisitaCampo $visita, VisitaCampoDetalle $detalle): JsonResponse
    {
        // Verificar que el detalle pertenece a la visita
        if ($detalle->visita_campo_id !== $visita->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'El detalle no pertenece a esta visita.',
            ], 422);
        }

        $request->validate([
            'fotos' => 'required|array|min:1',
            'fotos.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
            'descripciones' => 'nullable|array',
            'descripciones.*' => 'nullable|string|max:255',
        ]);

        $fotosCreadss = [];
        $files = $request->file('fotos');
        $descripciones = $request->input('descripciones', []);

        foreach ($files as $index => $file) {
            $path = $file->store("visitas_campo/{$visita->id}", 'public');
            $foto = $detalle->fotos()->create([
                'foto_path' => $path,
                'descripcion' => $descripciones[$index] ?? null,
            ]);
            $foto->append('foto_url');
            $fotosCreadss[] = $foto;
        }

        return response()->json([
            'success' => true,
            'message' => count($fotosCreadss) . ' foto(s) subida(s) exitosamente.',
            'data' => $fotosCreadss,
        ], 201);
    }

    /**
     * Eliminar una foto específica.
     */
    public function deleteFoto(VisitaCampo $visita, VisitaCampoFoto $foto): JsonResponse
    {
        // Verificar que la foto pertenece a esta visita
        $detalle = $foto->detalle;
        if (!$detalle || $detalle->visita_campo_id !== $visita->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'La foto no pertenece a esta visita.',
            ], 422);
        }

        Storage::disk('public')->delete($foto->foto_path);
        $foto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Foto eliminada exitosamente.',
        ]);
    }
}
