<?php

namespace App\Http\Controllers\Api\SplendidFarms;

use App\Http\Controllers\Controller;
use App\Models\Temporada;
use App\Models\Cultivo;
use App\Models\Productor;
use App\Models\ZonaCultivo;
use App\Models\Lote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class TemporadaController extends Controller
{
    /**
     * Display a listing of temporadas.
     */
    public function index(Request $request)
    {
        try {
            $query = Temporada::with(['cultivo', 'usuario']);

            // Filtros opcionales
            if ($request->filled('cultivo_id')) {
                $query->where('cultivo_id', $request->cultivo_id);
            }

            if ($request->filled('locacion')) {
                $query->where('locacion', 'like', '%' . $request->locacion . '%');
            }

            if ($request->filled('año')) {
                $query->where(function($q) use ($request) {
                    $q->where('año_inicio', $request->año)
                      ->orWhere('año_fin', $request->año);
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre', 'like', '%' . $search . '%')
                      ->orWhere('folio_temporada', 'like', '%' . $search . '%')
                      ->orWhere('locacion', 'like', '%' . $search . '%');
                });
            }

            $temporadas = $query->orderBy('created_at', 'desc')->get();

            // Agregar imagen_url de cultivo
            $temporadas->each(function ($temporada) {
                if ($temporada->cultivo && $temporada->cultivo->imagen) {
                    $temporada->cultivo->imagen_url = asset('storage/' . $temporada->cultivo->imagen);
                }
            });

            return response()->json($temporadas);
        } catch (\Exception $e) {
            Log::error('Error al listar temporadas: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener las temporadas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created temporada.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'cultivo_id' => 'required|exists:cultivos,id',
                'locacion' => 'required|string|max:255',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            ], [
                'cultivo_id.required' => 'El cultivo es requerido',
                'cultivo_id.exists' => 'El cultivo seleccionado no existe',
                'locacion.required' => 'La locación es requerida',
                'fecha_inicio.required' => 'La fecha de inicio es requerida',
                'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida',
                'fecha_fin.required' => 'La fecha de fin es requerida',
                'fecha_fin.date' => 'La fecha de fin debe ser una fecha válida',
                'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Obtener el cultivo
            $cultivo = Cultivo::findOrFail($request->cultivo_id);

            // Generar folio_temporada
            $folio = Temporada::generarFolio($request->cultivo_id);

            // Extraer años de las fechas
            $añoInicio = Carbon::parse($request->fecha_inicio)->year;
            $añoFin = Carbon::parse($request->fecha_fin)->year;

            // Generar nombre automáticamente
            $nombre = $cultivo->nombre . ' ' . $request->locacion . ' ' . $añoInicio;
            if ($añoInicio != $añoFin) {
                $nombre .= '-' . $añoFin;
            }

            // Crear la temporada
            $temporada = Temporada::create([
                'cultivo_id' => $request->cultivo_id,
                'nombre' => $nombre,
                'locacion' => $request->locacion,
                'folio_temporada' => $folio,
                'año_inicio' => $añoInicio,
                'año_fin' => $añoFin,
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'estado' => 'abierta',
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
            ]);

            // Cargar relaciones
            $temporada->load(['cultivo', 'usuario']);

            // Agregar imagen_url
            if ($temporada->cultivo && $temporada->cultivo->imagen) {
                $temporada->cultivo->imagen_url = asset('storage/' . $temporada->cultivo->imagen);
            }

            return response()->json([
                'message' => 'Temporada creada exitosamente',
                'temporada' => $temporada
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear temporada: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al crear la temporada',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified temporada.
     */
    public function show($id)
    {
        try {
            $temporada = Temporada::with(['cultivo', 'usuario'])->findOrFail($id);

            // Agregar imagen_url
            if ($temporada->cultivo && $temporada->cultivo->imagen) {
                $temporada->cultivo->imagen_url = asset('storage/' . $temporada->cultivo->imagen);
            }

            return response()->json($temporada);
        } catch (\Exception $e) {
            Log::error('Error al obtener temporada: ' . $e->getMessage());
            return response()->json([
                'message' => 'Temporada no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified temporada.
     */
    public function update(Request $request, $id)
    {
        try {
            $temporada = Temporada::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'cultivo_id' => 'required|exists:cultivos,id',
                'locacion' => 'required|string|max:255',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            ], [
                'cultivo_id.required' => 'El cultivo es requerido',
                'cultivo_id.exists' => 'El cultivo seleccionado no existe',
                'locacion.required' => 'La locación es requerida',
                'fecha_inicio.required' => 'La fecha de inicio es requerida',
                'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida',
                'fecha_fin.required' => 'La fecha de fin es requerida',
                'fecha_fin.date' => 'La fecha de fin debe ser una fecha válida',
                'fecha_fin.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Si cambió el cultivo, regenerar folio
            $folio = $temporada->folio_temporada;
            if ($request->cultivo_id != $temporada->cultivo_id) {
                $folio = Temporada::generarFolio($request->cultivo_id);
            }

            // Obtener el cultivo
            $cultivo = Cultivo::findOrFail($request->cultivo_id);

            // Extraer años de las fechas
            $añoInicio = Carbon::parse($request->fecha_inicio)->year;
            $añoFin = Carbon::parse($request->fecha_fin)->year;

            // Regenerar nombre automáticamente
            $nombre = $cultivo->nombre . ' ' . $request->locacion . ' ' . $añoInicio;
            if ($añoInicio != $añoFin) {
                $nombre .= '-' . $añoFin;
            }

            // Actualizar la temporada
            $temporada->update([
                'cultivo_id' => $request->cultivo_id,
                'nombre' => $nombre,
                'locacion' => $request->locacion,
                'folio_temporada' => $folio,
                'año_inicio' => $añoInicio,
                'año_fin' => $añoFin,
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
            ]);

            // Cargar relaciones
            $temporada->load(['cultivo', 'usuario']);

            // Agregar imagen_url
            if ($temporada->cultivo && $temporada->cultivo->imagen) {
                $temporada->cultivo->imagen_url = asset('storage/' . $temporada->cultivo->imagen);
            }

            return response()->json([
                'message' => 'Temporada actualizada exitosamente',
                'temporada' => $temporada
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar temporada: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al actualizar la temporada',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified temporada.
     */
    public function destroy($id)
    {
        try {
            $temporada = Temporada::findOrFail($id);
            /** @phpstan-ignore-next-line */
            $temporada->delete();

            return response()->json([
                'message' => 'Temporada eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar temporada: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al eliminar la temporada',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cerrar una temporada.
     */
    public function cerrar($id)
    {
        try {
            $temporada = Temporada::findOrFail($id);

            // Validar que la temporada esté abierta
            if ($temporada->estado === 'cerrada') {
                return response()->json([
                    'message' => 'La temporada ya está cerrada'
                ], 400);
            }

            // Cerrar la temporada
            $temporada->update([
                'estado' => 'cerrada',
                'fecha_cierre_real' => now(),
            ]);

            // Cargar relaciones
            $temporada->load(['cultivo', 'usuario']);

            // Agregar imagen_url
            if ($temporada->cultivo && $temporada->cultivo->imagen) {
                $temporada->cultivo->imagen_url = asset('storage/' . $temporada->cultivo->imagen);
            }

            return response()->json([
                'message' => 'Temporada cerrada exitosamente',
                'temporada' => $temporada
            ]);
        } catch (\Exception $e) {
            Log::error('Error al cerrar temporada: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al cerrar la temporada',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de la temporada (productores, zonas, lotes).
     */
    public function resumen($id)
    {
        try {
            $temporada = Temporada::findOrFail($id);
            $resumen = $temporada->resumen();

            return response()->json($resumen);
        } catch (\Exception $e) {
            Log::error('Error al obtener resumen de temporada: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener el resumen',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =====================================================
    // GESTIÓN DE PRODUCTORES
    // =====================================================

    /**
     * Obtener productores asignados a la temporada con sus lotes.
     */
    public function getProductores($id)
    {
        try {
            $temporada = Temporada::with(['productoresActivos.lotesActivos.zonaCultivo'])->findOrFail($id);
            
            $productores = $temporada->productores->map(function ($productor) {
                return [
                    'id' => $productor->id,
                    'nombre' => $productor->nombre,
                    'apellido' => $productor->apellido ?? '',
                    'tipo' => $productor->tipo,
                    'ubicacion' => $productor->ubicacion,
                    'telefono' => $productor->telefono,
                    'email' => $productor->email,
                    'notas' => $productor->pivot->notas,
                    'is_active' => $productor->pivot->is_active,
                    'created_at' => $productor->pivot->created_at,
                    // Incluir lotes activos del productor
                    'lotes' => $productor->lotesActivos->map(function ($lote) {
                        return [
                            'id' => $lote->id,
                            'nombre' => $lote->nombre,
                            'codigo' => $lote->codigo,
                            'numero_lote' => $lote->numero_lote,
                            'superficie' => $lote->superficie,
                            'superficie_calculada' => $lote->superficie_calculada,
                            'superficie_efectiva' => $lote->superficie_efectiva,
                            'zona_cultivo' => $lote->zonaCultivo ? [
                                'id' => $lote->zonaCultivo->id,
                                'nombre' => $lote->zonaCultivo->nombre,
                            ] : null,
                        ];
                    }),
                ];
            });

            return response()->json($productores);
        } catch (\Exception $e) {
            Log::error('Error al obtener productores: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener productores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar productor a la temporada.
     */
    public function asignarProductor(Request $request, $id)
    {
        try {
            $temporada = Temporada::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'productor_id' => 'required|exists:productores,id',
                'notas' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar que no esté ya asignado
            if ($temporada->productores()->where('productor_id', $request->productor_id)->exists()) {
                return response()->json([
                    'message' => 'El productor ya está asignado a esta temporada'
                ], 400);
            }

            $temporada->asignarProductor($request->productor_id, $request->notas);

            return response()->json([
                'message' => 'Productor asignado exitosamente'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al asignar productor: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al asignar productor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Desasignar productor de la temporada.
     */
    public function desasignarProductor($id, $productorId)
    {
        try {
            $temporada = Temporada::findOrFail($id);
            $temporada->productores()->detach($productorId);

            return response()->json([
                'message' => 'Productor desasignado exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al desasignar productor: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al desasignar productor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activar/desactivar productor en la temporada.
     */
    public function toggleProductor(Request $request, $id, $productorId)
    {
        try {
            $temporada = Temporada::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'is_active' => 'required|boolean',
                'notas' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $temporada->productores()->updateExistingPivot($productorId, [
                'is_active' => $request->is_active,
                'notas' => $request->notas ?? null,
            ]);

            return response()->json([
                'message' => 'Estado actualizado exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar estado: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al actualizar estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =====================================================
    // GESTIÓN DE ZONAS DE CULTIVO
    // =====================================================

    /**
     * Obtener zonas de cultivo asignadas a la temporada.
     */
    public function getZonasCultivo($id)
    {
        try {
            $temporada = Temporada::with(['zonasCultivo.productor'])->findOrFail($id);
            
            $zonas = $temporada->zonasCultivo->map(function ($zona) {
                return [
                    'id' => $zona->id,
                    'nombre' => $zona->nombre,
                    'superficie_total' => $zona->superficie_total,
                    'productor' => $zona->productor ? [
                        'id' => $zona->productor->id,
                        'nombre' => $zona->productor->nombre,
                    ] : null,
                    'superficie_asignada' => $zona->pivot->superficie_asignada,
                    'notas' => $zona->pivot->notas,
                    'is_active' => $zona->pivot->is_active,
                    'created_at' => $zona->pivot->created_at,
                ];
            });

            return response()->json($zonas);
        } catch (\Exception $e) {
            Log::error('Error al obtener zonas de cultivo: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener zonas de cultivo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar zona de cultivo a la temporada.
     */
    public function asignarZonaCultivo(Request $request, $id)
    {
        try {
            $temporada = Temporada::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'zona_cultivo_id' => 'required|exists:zonas_cultivo,id',
                'superficie_asignada' => 'required|numeric|min:0',
                'notas' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar que no esté ya asignada
            if ($temporada->zonasCultivo()->where('zona_cultivo_id', $request->zona_cultivo_id)->exists()) {
                return response()->json([
                    'message' => 'La zona de cultivo ya está asignada a esta temporada'
                ], 400);
            }

            // Verificar que la superficie asignada no exceda la total
            $zona = ZonaCultivo::findOrFail($request->zona_cultivo_id);
            if ($request->superficie_asignada > $zona->superficie_total) {
                return response()->json([
                    'message' => 'La superficie asignada no puede ser mayor a la superficie total de la zona'
                ], 400);
            }

            $temporada->asignarZonaCultivo(
                $request->zona_cultivo_id,
                $request->superficie_asignada,
                $request->notas
            );

            return response()->json([
                'message' => 'Zona de cultivo asignada exitosamente'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al asignar zona de cultivo: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al asignar zona de cultivo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Desasignar zona de cultivo de la temporada.
     */
    public function desasignarZonaCultivo($id, $zonaId)
    {
        try {
            $temporada = Temporada::findOrFail($id);
            $temporada->zonasCultivo()->detach($zonaId);

            return response()->json([
                'message' => 'Zona de cultivo desasignada exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al desasignar zona de cultivo: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al desasignar zona de cultivo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =====================================================
    // GESTIÓN DE LOTES
    // =====================================================

    /**
     * Obtener lotes asignados a la temporada.
     */
    public function getLotes($id)
    {
        try {
            $temporada = Temporada::with(['lotes.zonaCultivo', 'lotes.productor'])->findOrFail($id);
            
            $lotes = $temporada->lotes->map(function ($lote) {
                /** @var Cultivo|null $cultivo */
                /** @phpstan-ignore-next-line */
                $cultivo = isset($lote->pivot->cultivo_id) ? Cultivo::find($lote->pivot->cultivo_id) : null;
                
                return [
                    'id' => $lote->id,
                    'nombre' => $lote->nombre,
                    'codigo' => $lote->codigo,
                    'numero_lote' => $lote->numero_lote,
                    'superficie' => $lote->superficie,
                    'superficie_calculada' => $lote->superficie_calculada,
                    'superficie_efectiva' => $lote->superficie_efectiva,
                    'zona_cultivo' => $lote->zonaCultivo ? [
                        'id' => $lote->zonaCultivo->id,
                        'nombre' => $lote->zonaCultivo->nombre,
                    ] : null,
                    'productor' => $lote->productor ? [
                        'id' => $lote->productor->id,
                        'nombre' => $lote->productor->nombre,
                        'apellido' => $lote->productor->apellido ?? '',
                    ] : null,
                    'cultivo' => $cultivo ? [
                        'id' => $cultivo->id,
                        'nombre' => $cultivo->nombre,
                    ] : null,
                    'superficie_sembrada' => $lote->pivot->superficie_sembrada,
                    'fecha_siembra' => $lote->pivot->fecha_siembra,
                    'fecha_cosecha_estimada' => $lote->pivot->fecha_cosecha_estimada,
                    'notas' => $lote->pivot->notas,
                    'is_active' => $lote->pivot->is_active,
                    'created_at' => $lote->pivot->created_at,
                ];
            });

            return response()->json($lotes);
        } catch (\Exception $e) {
            Log::error('Error al obtener lotes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener lotes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar lote a la temporada.
     */
    public function asignarLote(Request $request, $id)
    {
        try {
            $temporada = Temporada::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'lote_id' => 'required|exists:lotes,id',
                'cultivo_id' => 'required|exists:cultivos,id',
                'superficie_sembrada' => 'required|numeric|min:0.01',
                'fecha_siembra' => 'nullable|date',
                'fecha_cosecha_estimada' => 'nullable|date|after_or_equal:fecha_siembra',
                'notas' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar que no esté ya asignado
            if ($temporada->lotes()->where('lote_id', $request->lote_id)->exists()) {
                return response()->json([
                    'message' => 'El lote ya está asignado a esta temporada'
                ], 400);
            }

            // Verificar que la superficie sembrada no exceda la disponible
            $lote = Lote::findOrFail($request->lote_id);
            $superficieMaxima = $lote->superficie_efectiva ?? $lote->superficie ?? 0;
            if ($superficieMaxima > 0 && $request->superficie_sembrada > $superficieMaxima) {
                return response()->json([
                    'message' => "La superficie sembrada no puede ser mayor a {$superficieMaxima} ha (superficie del lote)"
                ], 400);
            }

            $temporada->asignarLote(
                $request->lote_id,
                $request->cultivo_id,
                $request->superficie_sembrada,
                $request->fecha_siembra,
                $request->notas,
                $request->fecha_cosecha_estimada
            );

            return response()->json([
                'message' => 'Lote asignado exitosamente'
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al asignar lote: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al asignar lote',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Desasignar lote de la temporada.
     */
    public function desasignarLote($id, $loteId)
    {
        try {
            $temporada = Temporada::findOrFail($id);
            $temporada->lotes()->detach($loteId);

            return response()->json([
                'message' => 'Lote desasignado exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al desasignar lote: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al desasignar lote',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
