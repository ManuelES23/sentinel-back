<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\SalidaRezagaEmpaque;
use App\Models\RezagaEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalidaRezagaEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'detalles.rezaga:id,folio_rezaga,tipo_rezaga,cantidad_kg,proceso_id',
        'detalles.rezaga.proceso:id,folio_proceso,etapa_id,recepcion_id',
        'detalles.rezaga.proceso.productor:id,nombre,apellido',
        'detalles.rezaga.proceso.lote:id,nombre,numero_lote',
        'detalles.rezaga.proceso.etapa:id,nombre,variedad_id',
        'detalles.rezaga.proceso.etapa.variedad:id,nombre',
        'detalles.rezaga.proceso.recepcion:id,salida_campo_id,folio_recepcion,cantidad_recibida,lote_producto_terminado',
        'detalles.rezaga.proceso.recepcion.salidaCampo:id,variedad_id,folio_salida,cantidad,productor_id,lote_id',
        'detalles.rezaga.proceso.recepcion.salidaCampo.variedad:id,nombre',
        'detalles.rezaga.proceso.recepcion.salidaCampo.productor:id,nombre,apellido',
        'detalles.rezaga.proceso.recepcion.salidaCampo.lote:id,nombre,numero_lote',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = SalidaRezagaEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_venta', 'like', "%{$search}%")
                  ->orWhere('comprador', 'like', "%{$search}%")
                  ->orWhere('autorizado_por', 'like', "%{$search}%")
                  ->orWhere('solicitado_por', 'like', "%{$search}%")
                  ->orWhere('chofer', 'like', "%{$search}%")
                  ->orWhere('placa', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tipo_salida')) {
            $query->where('tipo_salida', $request->tipo_salida);
        }

        $salidas = $query->orderByDesc('fecha_venta')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $salidas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'tipo_salida' => 'required|in:venta,regalia,desecho',
            'fecha_venta' => 'required|date',
            'total_peso_kg' => 'required|numeric|min:0.01',
            'comprador' => 'required_if:tipo_salida,venta|nullable|string|max:200',
            'precio_kg' => 'required_if:tipo_salida,venta|nullable|numeric|min:0',
            'autorizado_por' => 'required|string|max:150',
            'solicitado_por' => 'required|string|max:150',
            'chofer' => 'required|string|max:150',
            'placa' => 'required|string|max:50',
            'status' => 'nullable|in:pendiente,pagada,cancelada',
            'observaciones' => 'nullable|string',
            'detalles' => 'nullable|array|min:1',
            'detalles.*.rezaga_id' => 'required_with:detalles|exists:rezaga_empaque,id',
            'detalles.*.peso_kg' => 'required_with:detalles|numeric|min:0.01',
        ]);

        $validated['status'] = $validated['status'] ?? 'pendiente';
        $validated['created_by'] = $request->user()->id;
        $validated['folio_venta'] = $this->generarFolio($validated);

        $precioKg = (float) ($validated['precio_kg'] ?? 0);
        if (($validated['tipo_salida'] ?? null) !== 'venta') {
            $precioKg = 0;
            $validated['comprador'] = null;
        }

        $detallesSolicitados = $validated['detalles'] ?? [];
        unset($validated['detalles']);

        $cantidadSolicitada = (float) $validated['total_peso_kg'];
        if (!empty($detallesSolicitados)) {
            $cantidadSolicitada = (float) collect($detallesSolicitados)->sum(fn($d) => (float) $d['peso_kg']);
            $cantidadInformada = (float) ($validated['total_peso_kg'] ?? 0);
            if ($cantidadInformada > 0 && abs($cantidadInformada - $cantidadSolicitada) > 0.01) {
                throw ValidationException::withMessages([
                    'total_peso_kg' => 'La cantidad total no coincide con el detalle seleccionado de rezaga.',
                ]);
            }
        }

        $validated['total_peso_kg'] = $cantidadSolicitada;
        $validated['precio_kg'] = $precioKg;
        $validated['monto_total'] = $cantidadSolicitada * $precioKg;

        $salida = DB::transaction(function () use ($validated, $cantidadSolicitada, $precioKg, $detallesSolicitados) {
            $rezagaPendiente = RezagaEmpaque::query()
                ->where('temporada_id', $validated['temporada_id'])
                ->where('entity_id', $validated['entity_id'])
                ->where('status', 'pendiente')
                ->where('cantidad_kg', '>', 0)
                ->orderBy('fecha')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $acumuladoDisponible = (float) $rezagaPendiente->sum('cantidad_kg');
            if ($acumuladoDisponible < $cantidadSolicitada) {
                throw ValidationException::withMessages([
                    'total_peso_kg' => 'La cantidad solicitada excede la rezaga acumulada disponible.',
                ]);
            }

            $salida = SalidaRezagaEmpaque::create($validated);

            if (!empty($detallesSolicitados)) {
                $rezagaPendienteMap = $rezagaPendiente->keyBy('id');

                $ids = collect($detallesSolicitados)->pluck('rezaga_id');
                if ($ids->count() !== $ids->unique()->count()) {
                    throw ValidationException::withMessages([
                        'detalles' => 'No se puede repetir el mismo folio de rezaga en el detalle.',
                    ]);
                }

                foreach ($detallesSolicitados as $det) {
                    $rezaga = $rezagaPendienteMap->get((int) $det['rezaga_id']);
                    if (!$rezaga) {
                        throw ValidationException::withMessages([
                            'detalles' => 'Uno o más folios de rezaga no están disponibles para esta salida.',
                        ]);
                    }

                    $consumo = (float) $det['peso_kg'];
                    $disponible = (float) $rezaga->cantidad_kg;
                    if ($consumo > $disponible + 0.0001) {
                        throw ValidationException::withMessages([
                            'detalles' => "La cantidad para el folio {$rezaga->folio_rezaga} excede lo disponible.",
                        ]);
                    }

                    $salida->detalles()->create([
                        'venta_rezaga_id' => $salida->id,
                        'rezaga_id' => $rezaga->id,
                        'peso_kg' => $consumo,
                        'precio_kg' => $precioKg,
                        'monto' => $consumo * $precioKg,
                    ]);

                    $restante = $disponible - $consumo;
                    if ($restante <= 0.0001) {
                        $nuevoStatus = $validated['tipo_salida'] === 'desecho' ? 'destruida' : 'vendida';
                        RezagaEmpaque::where('id', $rezaga->id)->update([
                            'status' => $nuevoStatus,
                            'cantidad_kg' => 0,
                        ]);
                    } else {
                        RezagaEmpaque::where('id', $rezaga->id)->update([
                            'cantidad_kg' => $restante,
                        ]);
                    }
                }
            } else {
                $pendiente = $cantidadSolicitada;
                foreach ($rezagaPendiente as $rezaga) {
                    if ($pendiente <= 0) {
                        break;
                    }

                    $disponible = (float) $rezaga->cantidad_kg;
                    if ($disponible <= 0) {
                        continue;
                    }

                    $consumo = min($pendiente, $disponible);
                    $pendiente -= $consumo;

                    $salida->detalles()->create([
                        'venta_rezaga_id' => $salida->id,
                        'rezaga_id' => $rezaga->id,
                        'peso_kg' => $consumo,
                        'precio_kg' => $precioKg,
                        'monto' => $consumo * $precioKg,
                    ]);

                    $restante = $disponible - $consumo;
                    if ($restante <= 0.0001) {
                        $nuevoStatus = $validated['tipo_salida'] === 'desecho' ? 'destruida' : 'vendida';
                        RezagaEmpaque::where('id', $rezaga->id)->update([
                            'status' => $nuevoStatus,
                            'cantidad_kg' => 0,
                        ]);
                    } else {
                        RezagaEmpaque::where('id', $rezaga->id)->update([
                            'cantidad_kg' => $restante,
                        ]);
                    }
                }
            }

            return $salida;
        });

        $salida->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Salida de rezaga registrada exitosamente',
            'data' => $salida,
        ], 201);
    }

    public function show(SalidaRezagaEmpaque $salidaRezaga): JsonResponse
    {
        $salidaRezaga->load($this->eagerLoad);

        return response()->json(['success' => true, 'data' => $salidaRezaga]);
    }

    public function update(Request $request, SalidaRezagaEmpaque $salidaRezaga): JsonResponse
    {
        $validated = $request->validate([
            'tipo_salida' => 'sometimes|in:venta,regalia,desecho',
            'comprador' => 'nullable|string|max:200',
            'fecha_venta' => 'sometimes|date',
            'precio_kg' => 'sometimes|numeric|min:0',
            'autorizado_por' => 'sometimes|string|max:150',
            'solicitado_por' => 'sometimes|string|max:150',
            'chofer' => 'sometimes|string|max:150',
            'placa' => 'sometimes|string|max:50',
            'status' => 'nullable|in:pendiente,pagada,cancelada',
            'observaciones' => 'nullable|string',
        ]);

        if (($validated['tipo_salida'] ?? $salidaRezaga->tipo_salida) !== 'venta') {
            $validated['comprador'] = null;
            $validated['precio_kg'] = 0;
            $validated['monto_total'] = 0;
        }

        $salidaRezaga->update($validated);
        $salidaRezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Salida de rezaga actualizada',
            'data' => $salidaRezaga,
        ]);
    }

    public function destroy(SalidaRezagaEmpaque $salidaRezaga): JsonResponse
    {
        DB::transaction(function () use ($salidaRezaga) {
            foreach ($salidaRezaga->detalles as $det) {
                $rezaga = RezagaEmpaque::query()->lockForUpdate()->find($det->rezaga_id);
                if (!$rezaga) {
                    continue;
                }

                $rezaga->update([
                    'status' => 'pendiente',
                    'cantidad_kg' => ((float) $rezaga->cantidad_kg) + ((float) $det->peso_kg),
                ]);
            }
            $salidaRezaga->detalles()->delete();
            $salidaRezaga->delete();
        });

        return response()->json(['success' => true, 'message' => 'Salida de rezaga eliminada']);
    }

    private function generarFolio(array $data): string
    {
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        $prefix = "SREZ-{$entityId}-";

        $lastFolio = SalidaRezagaEmpaque::withTrashed()
            ->where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->where('folio_venta', 'like', "{$prefix}%")
            ->orderByDesc('folio_venta')
            ->value('folio_venta');

        $nextNum = 1;
        if ($lastFolio) {
            $nextNum = (int) str_replace($prefix, '', $lastFolio) + 1;
        }

        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
