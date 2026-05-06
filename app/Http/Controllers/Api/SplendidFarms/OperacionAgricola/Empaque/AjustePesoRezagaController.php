<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\AjustePesoRezaga;
use App\Models\RezagaEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AjustePesoRezagaController extends Controller
{
    private array $eagerLoad = [
        'rezaga:id,folio_rezaga,tipo_rezaga,cantidad_kg,proceso_id,entity_id',
        'rezaga.proceso:id,folio_proceso,productor_id,lote_id,etapa_id,recepcion_id',
        'rezaga.proceso.productor:id,nombre,apellido',
        'rezaga.proceso.lote:id,nombre,numero_lote',
        'rezaga.proceso.etapa:id,nombre,variedad_id',
        'rezaga.proceso.etapa.variedad:id,nombre',
        'rezaga.proceso.recepcion:id,folio_recepcion,salida_campo_id',
        'rezaga.proceso.recepcion.salidaCampo:id,folio_salida,variedad_id',
        'rezaga.proceso.recepcion.salidaCampo.variedad:id,nombre',
        'creador:id,name',
    ];

    /* ─── index ─── */
    public function index(Request $request): JsonResponse
    {
        $query = AjustePesoRezaga::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->byEntity($request->entity_id);
        }
        if ($request->filled('rezaga_id')) {
            $query->byRezaga($request->rezaga_id);
        }
        if ($request->filled('motivo')) {
            $query->where('motivo', $request->motivo);
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_ajuste', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_ajuste', '<=', $request->fecha_hasta);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('folio_ajuste', 'like', "%{$s}%")
                  ->orWhere('observaciones', 'like', "%{$s}%");
            });
        }

        $ajustes = $query->orderByDesc('fecha_ajuste')->orderByDesc('id')->get();

        // Resumen por entidad/temporada
        $resumen = [
            'total_ajustes' => $ajustes->count(),
            'total_kg_perdido' => $ajustes->sum('kg_perdido'),
        ];

        return response()->json([
            'success' => true,
            'data'    => $ajustes,
            'resumen' => $resumen,
        ]);
    }

    /* ─── store ─── */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id'      => 'required|exists:temporadas,id',
            'entity_id'         => 'required|exists:entities,id',
            'rezaga_empaque_id' => 'required|exists:rezaga_empaque,id',
            'fecha_ajuste'      => 'required|date',
            'kg_despues'        => 'required|numeric|min:0',
            'motivo'            => 'required|in:deshidratacion,putrefaccion,merma_natural,otro',
            'observaciones'     => 'nullable|string|max:500',
        ]);

        // Buscar la rezaga y validar que esté pendiente
        $rezaga = RezagaEmpaque::findOrFail($validated['rezaga_empaque_id']);

        if ($rezaga->status !== 'pendiente') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Solo se pueden ajustar rezagas con status pendiente.',
            ], 422);
        }

        $kgAntes   = (float) $rezaga->cantidad_kg;
        $kgDespues = (float) $validated['kg_despues'];

        if ($kgDespues >= $kgAntes) {
            return response()->json([
                'status'  => 'error',
                'message' => "Los kg después del ajuste ({$kgDespues}) deben ser menores a los actuales ({$kgAntes}).",
            ], 422);
        }

        // Auto-generar folio AJR-XXXXX
        $lastFolio = AjustePesoRezaga::withTrashed()
            ->where('folio_ajuste', 'like', 'AJR-%')
            ->orderByDesc('folio_ajuste')
            ->value('folio_ajuste');
        $nextNum   = $lastFolio ? ((int) substr($lastFolio, 4)) + 1 : 1;
        $folio     = 'AJR-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);

        DB::transaction(function () use (&$ajuste, $validated, $rezaga, $kgAntes, $kgDespues, $folio, $request) {
            $ajuste = AjustePesoRezaga::create([
                ...$validated,
                'folio_ajuste' => $folio,
                'kg_antes'     => $kgAntes,
                'created_by'   => $request->user()?->id,
            ]);

            // Actualizar el peso real de la rezaga
            $rezaga->update(['cantidad_kg' => $kgDespues]);
        });

        $ajuste->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => "Ajuste registrado. Pérdida: " . number_format($kgAntes - $kgDespues, 2) . " kg.",
            'data'    => $ajuste,
        ], 201);
    }

    /* ─── show ─── */
    public function show(AjustePesoRezaga $ajustePesoRezaga): JsonResponse
    {
        $ajustePesoRezaga->load($this->eagerLoad);
        return response()->json(['success' => true, 'data' => $ajustePesoRezaga]);
    }

    /* ─── destroy  (revierte el ajuste) ─── */
    public function destroy(AjustePesoRezaga $ajustePesoRezaga): JsonResponse
    {
        $rezaga = RezagaEmpaque::find($ajustePesoRezaga->rezaga_empaque_id);

        DB::transaction(function () use ($ajustePesoRezaga, $rezaga) {
            // Restaurar el peso original solo si la rezaga sigue pendiente
            if ($rezaga && $rezaga->status === 'pendiente') {
                $rezaga->update(['cantidad_kg' => $ajustePesoRezaga->kg_antes]);
            }
            $ajustePesoRezaga->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Ajuste eliminado y peso restaurado.',
        ]);
    }
}
