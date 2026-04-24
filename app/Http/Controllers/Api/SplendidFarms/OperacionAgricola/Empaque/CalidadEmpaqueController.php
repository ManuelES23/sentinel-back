<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\CalidadEmpaque;
use App\Models\CalidadEmpaqueMuestra;
use App\Models\CalidadEmpaqueMuestraPlaga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CalidadEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'temporada:id,nombre',
        'evaluadoPor:id,name',
        'creador:id,name',
        'muestras',
        'muestras.recepcion:id,folio_recepcion,hora_recepcion,productor_id,lote_id,etapa_id,tipo_carga_id,salida_campo_id',
        'muestras.recepcion.productor:id,nombre,apellido',
        'muestras.recepcion.lote:id,nombre,numero_lote',
        'muestras.recepcion.etapa:id,nombre,variedad_id',
        'muestras.recepcion.etapa.variedad:id,nombre',
        'muestras.recepcion.tipoCarga:id,nombre',
        'muestras.recepcion.salidaCampo:id,variedad_id',
        'muestras.recepcion.salidaCampo.variedad:id,nombre',
        'muestras.empleado:id,nombre,apellido',
        'muestras.plagas',
        'muestras.plagas.plaga:id,nombre,abreviatura',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = CalidadEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('tipo_evaluacion')) {
            $query->byTipoEvaluacion($request->tipo_evaluacion);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_evaluacion', 'like', "%{$search}%")
                  ->orWhere('responsable', 'like', "%{$search}%")
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
            'tipo_evaluacion' => ['required', Rule::in(['recepcion', 'empacadores'])],
            'fecha_evaluacion' => 'required|date',
            'responsable' => 'nullable|string|max:150',
            'piezas_por_caja' => 'nullable|integer|min:0',
            'observaciones' => 'nullable|string',

            'muestras' => 'required|array|min:1',
            'muestras.*.recepcion_id' => 'nullable|exists:recepciones_empaque,id',
            'muestras.*.empleado_id' => 'nullable|exists:employees,id',
            'muestras.*.empacador_nombre' => 'nullable|string|max:150',
            'muestras.*.hora' => 'nullable',
            'muestras.*.muestra' => 'required|integer|min:0',
            'muestras.*.conteo' => 'nullable|numeric|min:0',
            'muestras.*.cumple' => 'nullable|numeric|min:0',
            'muestras.*.no_cumple' => 'nullable|numeric|min:0',
            'muestras.*.calificacion' => 'nullable|string|max:30',
            'muestras.*.plagas' => 'nullable|array',
            'muestras.*.plagas.*.plaga_id' => 'required_with:muestras.*.plagas|exists:plagas,id',
            'muestras.*.plagas.*.cantidad' => 'required_with:muestras.*.plagas|numeric|min:0',
        ]);

        $calidad = DB::transaction(function () use ($validated, $request) {
            $header = [
                'temporada_id' => $validated['temporada_id'],
                'entity_id' => $validated['entity_id'],
                'tipo_evaluacion' => $validated['tipo_evaluacion'],
                'fecha_evaluacion' => $validated['fecha_evaluacion'],
                'responsable' => $validated['responsable'] ?? null,
                'piezas_por_caja' => $validated['piezas_por_caja'] ?? null,
                'observaciones' => $validated['observaciones'] ?? null,
                'evaluado_por' => $request->user()->id,
                'created_by' => $request->user()->id,
                'folio_evaluacion' => $this->generarFolio($validated),
                'resultado' => 'aprobada',
            ];

            $calidad = CalidadEmpaque::create($header);

            $this->sincronizarMuestras($calidad, $validated['muestras']);
            $this->recalcularTotales($calidad);

            return $calidad;
        });

        $calidad->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Evaluacion de calidad registrada exitosamente',
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
            'fecha_evaluacion' => 'sometimes|date',
            'responsable' => 'nullable|string|max:150',
            'piezas_por_caja' => 'nullable|integer|min:0',
            'observaciones' => 'nullable|string',

            'muestras' => 'nullable|array',
            'muestras.*.recepcion_id' => 'nullable|exists:recepciones_empaque,id',
            'muestras.*.empleado_id' => 'nullable|exists:employees,id',
            'muestras.*.empacador_nombre' => 'nullable|string|max:150',
            'muestras.*.hora' => 'nullable',
            'muestras.*.muestra' => 'required_with:muestras|integer|min:0',
            'muestras.*.conteo' => 'nullable|numeric|min:0',
            'muestras.*.cumple' => 'nullable|numeric|min:0',
            'muestras.*.no_cumple' => 'nullable|numeric|min:0',
            'muestras.*.calificacion' => 'nullable|string|max:30',
            'muestras.*.plagas' => 'nullable|array',
            'muestras.*.plagas.*.plaga_id' => 'required_with:muestras.*.plagas|exists:plagas,id',
            'muestras.*.plagas.*.cantidad' => 'required_with:muestras.*.plagas|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $calidad) {
            $calidad->update([
                'fecha_evaluacion' => $validated['fecha_evaluacion'] ?? $calidad->fecha_evaluacion,
                'responsable' => array_key_exists('responsable', $validated) ? $validated['responsable'] : $calidad->responsable,
                'piezas_por_caja' => array_key_exists('piezas_por_caja', $validated) ? $validated['piezas_por_caja'] : $calidad->piezas_por_caja,
                'observaciones' => array_key_exists('observaciones', $validated) ? $validated['observaciones'] : $calidad->observaciones,
            ]);

            if (array_key_exists('muestras', $validated) && is_array($validated['muestras'])) {
                $calidad->muestras()->delete();
                $this->sincronizarMuestras($calidad, $validated['muestras']);
            }

            $this->recalcularTotales($calidad);
        });

        $calidad->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Evaluacion de calidad actualizada',
            'data' => $calidad,
        ]);
    }

    public function destroy(CalidadEmpaque $calidad): JsonResponse
    {
        $calidad->delete();

        return response()->json(['success' => true, 'message' => 'Evaluacion de calidad eliminada']);
    }

    private function sincronizarMuestras(CalidadEmpaque $calidad, array $muestras): void
    {
        foreach ($muestras as $muestraData) {
            $muestra = new CalidadEmpaqueMuestra([
                'recepcion_id' => $muestraData['recepcion_id'] ?? null,
                'empleado_id' => $muestraData['empleado_id'] ?? null,
                'empacador_nombre' => $muestraData['empacador_nombre'] ?? null,
                'hora' => $muestraData['hora'] ?? null,
                'muestra' => (int) ($muestraData['muestra'] ?? 0),
                'conteo' => $muestraData['conteo'] ?? null,
                'cumple' => (float) ($muestraData['cumple'] ?? 0),
                'no_cumple' => (float) ($muestraData['no_cumple'] ?? 0),
                'calificacion' => $muestraData['calificacion'] ?? null,
                'observaciones' => $muestraData['observaciones'] ?? null,
            ]);

            $this->calcularPorcentajeMuestra($muestra);
            $calidad->muestras()->save($muestra);

            foreach ($muestraData['plagas'] ?? [] as $plagaData) {
                CalidadEmpaqueMuestraPlaga::create([
                    'muestra_id' => $muestra->id,
                    'plaga_id' => $plagaData['plaga_id'],
                    'cantidad' => (float) $plagaData['cantidad'],
                ]);
            }
        }
    }

    private function calcularPorcentajeMuestra(CalidadEmpaqueMuestra $muestra): void
    {
        $base = (float) ($muestra->conteo ?: $muestra->muestra);
        if ($base <= 0) {
            $muestra->porcentaje_cumple = null;
            return;
        }

        $pct = ((float) $muestra->cumple / $base) * 100;
        $muestra->porcentaje_cumple = round($pct, 2);

        if (empty($muestra->calificacion)) {
            if ($pct >= 95) {
                $muestra->calificacion = 'Bueno';
            } elseif ($pct >= 80) {
                $muestra->calificacion = 'Regular';
            } else {
                $muestra->calificacion = 'Malo';
            }
        }
    }

    private function recalcularTotales(CalidadEmpaque $calidad): void
    {
        $muestras = $calidad->muestras()->get();

        if ($muestras->isEmpty()) {
            $calidad->update([
                'tamano_muestra_total' => 0,
                'cumple_total' => 0,
                'no_cumple_total' => 0,
                'porcentaje_cumple' => null,
            ]);
            return;
        }

        $tamano = (int) $muestras->sum('muestra');
        $cumple = (float) $muestras->sum('cumple');
        $noCumple = (float) $muestras->sum('no_cumple');
        $base = $muestras->sum(fn($m) => (float) ($m->conteo ?: $m->muestra));

        $porcentaje = $base > 0 ? round(($cumple / $base) * 100, 2) : null;

        $calidad->update([
            'tamano_muestra_total' => $tamano,
            'cumple_total' => $cumple,
            'no_cumple_total' => $noCumple,
            'porcentaje_cumple' => $porcentaje,
        ]);
    }

    private function generarFolio(array $data): string
    {
        $prefix = $data['tipo_evaluacion'] === 'recepcion' ? 'QC-R' : 'QC-E';
        $entityId = str_pad((string) $data['entity_id'], 2, '0', STR_PAD_LEFT);
        $fullPrefix = "{$prefix}-{$entityId}-";

        $lastFolio = CalidadEmpaque::withTrashed()
            ->where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->where('folio_evaluacion', 'like', "{$fullPrefix}%")
            ->orderByDesc('folio_evaluacion')
            ->value('folio_evaluacion');

        $nextNum = 1;
        if ($lastFolio) {
            $nextNum = (int) str_replace($fullPrefix, '', $lastFolio) + 1;
        }

        return $fullPrefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }
}
