<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\EmbarqueEmpaque;
use App\Models\EmbarqueEmpaqueDetalle;
use App\Models\ProduccionEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmbarqueEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'detalles.produccion:id,folio_produccion,numero_pallet,total_cajas,peso_neto_kg,tipo_empaque,etiqueta',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = EmbarqueEmpaque::with($this->eagerLoad);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }
        if ($request->filled('tipo_venta')) {
            $query->where('tipo_venta', $request->tipo_venta);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('folio_embarque', 'like', "%{$search}%")
                  ->orWhere('cliente', 'like', "%{$search}%")
                  ->orWhere('destino', 'like', "%{$search}%")
                  ->orWhere('numero_contenedor', 'like', "%{$search}%");
            });
        }

        $embarques = $query->orderByDesc('fecha_embarque')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $embarques]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'tipo_venta' => 'required|in:exportacion,nacional',
            'cliente' => 'required|string|max:200',
            'destino' => 'nullable|string|max:200',
            'fecha_embarque' => 'required|date',
            'transportista' => 'nullable|string|max:150',
            'vehiculo' => 'nullable|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'numero_contenedor' => 'nullable|string|max:100',
            'sello' => 'nullable|string|max:100',
            'temperatura' => 'nullable|numeric',
            'status' => 'nullable|in:programado,cargando,en_transito,entregado,cancelado',
            'observaciones' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.produccion_id' => 'required|exists:produccion_empaque,id',
            'detalles.*.cajas' => 'required|integer|min:1',
            'detalles.*.peso_kg' => 'nullable|numeric|min:0',
        ]);

        $validated['status'] = $validated['status'] ?? 'programado';
        $validated['created_by'] = $request->user()->id;
        $validated['folio_embarque'] = $this->generarFolio($validated);

        $detalles = $validated['detalles'];
        unset($validated['detalles']);

        // Calculate totals
        $validated['total_pallets'] = count($detalles);
        $validated['total_cajas'] = array_sum(array_column($detalles, 'cajas'));
        $validated['peso_total_kg'] = array_sum(array_column($detalles, 'peso_kg'));

        $embarque = DB::transaction(function () use ($validated, $detalles) {
            $embarque = EmbarqueEmpaque::create($validated);

            foreach ($detalles as $det) {
                $embarque->detalles()->create($det);
                // Mark production as shipped
                ProduccionEmpaque::where('id', $det['produccion_id'])->update(['status' => 'embarcado']);
            }

            return $embarque;
        });

        $embarque->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Embarque registrado exitosamente',
            'data' => $embarque,
        ], 201);
    }

    public function show(EmbarqueEmpaque $embarque): JsonResponse
    {
        $embarque->load($this->eagerLoad);

        return response()->json(['success' => true, 'data' => $embarque]);
    }

    public function update(Request $request, EmbarqueEmpaque $embarque): JsonResponse
    {
        $validated = $request->validate([
            'tipo_venta' => 'sometimes|in:exportacion,nacional',
            'cliente' => 'sometimes|string|max:200',
            'destino' => 'nullable|string|max:200',
            'fecha_embarque' => 'sometimes|date',
            'transportista' => 'nullable|string|max:150',
            'vehiculo' => 'nullable|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'numero_contenedor' => 'nullable|string|max:100',
            'sello' => 'nullable|string|max:100',
            'temperatura' => 'nullable|numeric',
            'status' => 'nullable|in:programado,cargando,en_transito,entregado,cancelado',
            'observaciones' => 'nullable|string',
        ]);

        $embarque->update($validated);
        $embarque->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Embarque actualizado',
            'data' => $embarque,
        ]);
    }

    public function destroy(EmbarqueEmpaque $embarque): JsonResponse
    {
        DB::transaction(function () use ($embarque) {
            // Revert production status
            foreach ($embarque->detalles as $det) {
                ProduccionEmpaque::where('id', $det->produccion_id)->update(['status' => 'en_almacen']);
            }
            $embarque->detalles()->delete();
            $embarque->delete();
        });

        return response()->json(['success' => true, 'message' => 'Embarque eliminado']);
    }

    private function generarFolio(array $data): string
    {
        $prefix = $data['tipo_venta'] === 'exportacion' ? 'EXP' : 'NAC';
        $count = EmbarqueEmpaque::where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->count() + 1;
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        return "{$prefix}-{$entityId}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
