<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\CalidadEmpaque;
use App\Models\ProcesoEmpaque;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalidadEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'evaluable',
        'evaluadoPor:id,name',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = CalidadEmpaque::with([
            'entity:id,name,code',
            'evaluable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    ProcesoEmpaque::class => [
                        'productor:id,nombre,apellido',
                        'lote:id,nombre,numero_lote',
                    ],
                ]);
            },
            'evaluadoPor:id,name',
            'creador:id,name',
        ]);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('tipo_evaluacion')) {
            $query->byTipoEvaluacion($request->tipo_evaluacion);
        }
        if ($request->filled('resultado')) {
            $query->byResultado($request->resultado);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_evaluacion', 'like', "%{$search}%")
                  ->orWhere('defectos_encontrados', 'like', "%{$search}%")
                  ->orWhere('observaciones', 'like', "%{$search}%");
            });
        }

        $evaluaciones = $query->orderByDesc('fecha_evaluacion')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $evaluaciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'fecha_evaluacion' => 'required|date',
            'resultado' => 'required|in:aprobada,condicionada,rechazada',
            'porcentaje_defectos' => 'nullable|numeric|min:0|max:100',
            'defectos_encontrados' => 'nullable|string',
            'temperatura' => 'nullable|numeric',
            'humedad' => 'nullable|numeric|min:0|max:100',
            'observaciones' => 'nullable|string',
        ]);

        // Auto-set polymorphic fields from proceso
        $validated['evaluable_type'] = 'App\\Models\\ProcesoEmpaque';
        $validated['evaluable_id'] = $validated['proceso_id'];
        $validated['tipo_evaluacion'] = 'proceso';
        $validated['evaluado_por'] = $request->user()->id;
        $validated['created_by'] = $request->user()->id;
        $validated['folio_evaluacion'] = $this->generarFolio($validated);

        $calidad = CalidadEmpaque::create($validated);
        $calidad->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Evaluación de calidad registrada exitosamente',
            'data' => $calidad,
        ], 201);
    }

    public function show(CalidadEmpaque $calidad): JsonResponse
    {
        $calidad->load($this->eagerLoad);

        return response()->json(['success' => true, 'data' => $calidad]);
    }

    public function update(Request $request, CalidadEmpaque $calidad): JsonResponse
    {
        $validated = $request->validate([
            'tipo_evaluacion' => 'sometimes|in:recepcion,empacado',
            'fecha_evaluacion' => 'sometimes|date',
            'resultado' => 'sometimes|in:aprobada,condicionada,rechazada',
            'porcentaje_defectos' => 'nullable|numeric|min:0|max:100',
            'defectos_encontrados' => 'nullable|string',
            'temperatura' => 'nullable|numeric',
            'humedad' => 'nullable|numeric|min:0|max:100',
            'observaciones' => 'nullable|string',
        ]);

        $calidad->update($validated);
        $calidad->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Evaluación de calidad actualizada',
            'data' => $calidad,
        ]);
    }

    public function destroy(CalidadEmpaque $calidad): JsonResponse
    {
        $calidad->delete();

        return response()->json(['success' => true, 'message' => 'Evaluación de calidad eliminada']);
    }

    private function generarFolio(array $data): string
    {
        $prefix = $data['tipo_evaluacion'] === 'recepcion' ? 'QC-R' : 'QC-E';
        $count = CalidadEmpaque::where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->count() + 1;
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        return "{$prefix}-{$entityId}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
