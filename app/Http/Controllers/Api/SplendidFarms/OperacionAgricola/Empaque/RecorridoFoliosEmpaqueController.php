<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\EmbarqueEmpaqueDetalle;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecorridoFoliosEmpaqueController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folio' => 'required|string|max:100',
            'temporada_id' => 'nullable|exists:temporadas,id',
            'entity_id' => 'nullable|exists:entities,id',
        ]);

        $folio = trim((string) $validated['folio']);

        $recepcion = RecepcionEmpaque::query()
            ->with([
                'productor:id,nombre,apellido',
                'lote:id,nombre,numero_lote',
                'zonaCultivo:id,nombre',
                'variedad:id,nombre',
                'tipoCarga:id,nombre,peso_estimado_kg',
                'entity:id,name,code',
            ])
            ->when(isset($validated['temporada_id']), fn ($q) => $q->where('temporada_id', $validated['temporada_id']))
            ->when(isset($validated['entity_id']), fn ($q) => $q->where('entity_id', $validated['entity_id']))
            ->where('folio_recepcion', $folio)
            ->first();

        $procesoBase = ProcesoEmpaque::withTrashed()
            ->when(isset($validated['temporada_id']), fn ($q) => $q->where('temporada_id', $validated['temporada_id']))
            ->when(isset($validated['entity_id']), fn ($q) => $q->where('entity_id', $validated['entity_id']))
            ->where('folio_proceso', $folio)
            ->first();

        if (!$recepcion && $procesoBase?->recepcion_id) {
            $recepcion = RecepcionEmpaque::query()
                ->with([
                    'productor:id,nombre,apellido',
                    'lote:id,nombre,numero_lote',
                    'zonaCultivo:id,nombre',
                    'variedad:id,nombre',
                    'tipoCarga:id,nombre,peso_estimado_kg',
                    'entity:id,name,code',
                ])
                ->find($procesoBase->recepcion_id);
        }

        $folioProceso = $procesoBase?->folio_proceso ?? $recepcion?->folio_recepcion;

        if (!$folioProceso) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontró información para el folio indicado',
            ], 404);
        }

        $procesos = ProcesoEmpaque::withTrashed()
            ->with([
                'productor:id,nombre,apellido',
                'lote:id,nombre,numero_lote',
                'etapa:id,nombre,variedad_id',
                'etapa.variedad:id,nombre',
                'tipoCarga:id,nombre,peso_estimado_kg',
                'recepcion:id,folio_recepcion,lote_producto_terminado,variedad_id,zona_cultivo_id,cantidad_recibida,peso_bascula,peso_recibido_kg',
                'recepcion.zonaCultivo:id,nombre',
                'recepcion.variedad:id,nombre',
            ])
            ->when(isset($validated['temporada_id']), fn ($q) => $q->where('temporada_id', $validated['temporada_id']))
            ->when(isset($validated['entity_id']), fn ($q) => $q->where('entity_id', $validated['entity_id']))
            ->where('folio_proceso', $folioProceso)
            ->orderBy('id')
            ->get();

        $procesoIds = $procesos->pluck('id')->all();

        $rezagas = RezagaEmpaque::query()
            ->with([
                'proceso:id,folio_proceso,status',
                'ventaDetalles:id,rezaga_id,peso_kg',
                'ajustesPeso:id,rezaga_empaque_id,kg_antes,kg_despues',
            ])
            ->when(!empty($procesoIds), fn ($q) => $q->whereIn('proceso_id', $procesoIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $rezagas->each(function (RezagaEmpaque $rezaga) {
            $vendidoKg = (float) $rezaga->ventaDetalles->sum('peso_kg');
            $ajusteInicialKg = (float) $rezaga->ajustesPeso
                ->sortByDesc('id')
                ->pluck('kg_antes')
                ->first();

            $historicaKg = max(
                (float) ($rezaga->cantidad_kg ?? 0) + $vendidoKg,
                $ajusteInicialKg,
                (float) ($rezaga->cantidad_kg ?? 0)
            );

            $rezaga->setAttribute('cantidad_historica_kg', round($historicaKg, 2));
        });

        $producciones = ProduccionEmpaque::query()
            ->with([
                'proceso:id,folio_proceso,recepcion_id,productor_id,lote_id',
                'proceso.productor:id,nombre,apellido',
                'proceso.lote:id,nombre,numero_lote',
                'variedad:id,nombre',
                'detalles:id,produccion_id,proceso_id,numero_entrada,fecha_produccion,presentacion,total_cajas,peso_neto_kg',
                'detalles.proceso:id,folio_proceso',
            ])
            ->when(isset($validated['temporada_id']), fn ($q) => $q->where('temporada_id', $validated['temporada_id']))
            ->when(isset($validated['entity_id']), fn ($q) => $q->where('entity_id', $validated['entity_id']))
            ->where(function ($q) use ($procesoIds) {
                if (empty($procesoIds)) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                $q->whereIn('proceso_id', $procesoIds)
                    ->orWhereHas('detalles', function ($detalleQuery) use ($procesoIds) {
                        $detalleQuery->whereIn('proceso_id', $procesoIds);
                    });
            })
            ->orderBy('fecha_produccion')
            ->orderBy('id')
            ->get();

        $produccionIds = $producciones->pluck('id')->all();

        $embarqueDetalles = EmbarqueEmpaqueDetalle::query()
            ->with([
                'embarque:id,folio_embarque,manifiesto,fecha_embarque,status,cliente,destino,total_pallets,total_cajas',
            ])
            ->when(!empty($produccionIds), fn ($q) => $q->whereIn('produccion_id', $produccionIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('fecha_produccion')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'folio' => $folio,
                'folio_proceso' => $folioProceso,
                'recepcion' => $recepcion,
                'procesos' => $procesos,
                'rezagas' => $rezagas,
                'producciones' => $producciones,
                'embarques' => $embarqueDetalles,
            ],
        ]);
    }
}
