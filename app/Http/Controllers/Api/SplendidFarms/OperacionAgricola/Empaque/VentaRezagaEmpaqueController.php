<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\VentaRezagaEmpaque;
use App\Models\RezagaEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VentaRezagaEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'detalles.rezaga:id,folio_rezaga,tipo_rezaga,cantidad_kg',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = VentaRezagaEmpaque::with($this->eagerLoad);

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
                  ->orWhere('comprador', 'like', "%{$search}%");
            });
        }

        $ventas = $query->orderByDesc('fecha_venta')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $ventas]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'comprador' => 'required|string|max:200',
            'fecha_venta' => 'required|date',
            'precio_kg' => 'required|numeric|min:0',
            'status' => 'nullable|in:pendiente,pagada,cancelada',
            'observaciones' => 'nullable|string',
            'detalles' => 'required|array|min:1',
            'detalles.*.rezaga_id' => 'required|exists:rezaga_empaque,id',
            'detalles.*.peso_kg' => 'required|numeric|min:0.01',
            'detalles.*.precio_kg' => 'nullable|numeric|min:0',
        ]);

        $validated['status'] = $validated['status'] ?? 'pendiente';
        $validated['created_by'] = $request->user()->id;
        $validated['folio_venta'] = $this->generarFolio($validated);

        $detalles = $validated['detalles'];
        unset($validated['detalles']);

        // Calculate totals
        $totalPeso = 0;
        $totalMonto = 0;
        foreach ($detalles as &$det) {
            $det['precio_kg'] = $det['precio_kg'] ?? $validated['precio_kg'];
            $det['monto'] = $det['peso_kg'] * $det['precio_kg'];
            $totalPeso += $det['peso_kg'];
            $totalMonto += $det['monto'];
        }
        $validated['total_peso_kg'] = $totalPeso;
        $validated['monto_total'] = $totalMonto;

        $venta = DB::transaction(function () use ($validated, $detalles) {
            $venta = VentaRezagaEmpaque::create($validated);

            foreach ($detalles as $det) {
                $venta->detalles()->create($det);
                RezagaEmpaque::where('id', $det['rezaga_id'])->update(['status' => 'vendida']);
            }

            return $venta;
        });

        $venta->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Venta de rezaga registrada exitosamente',
            'data' => $venta,
        ], 201);
    }

    public function show(VentaRezagaEmpaque $ventaRezaga): JsonResponse
    {
        $ventaRezaga->load($this->eagerLoad);

        return response()->json(['success' => true, 'data' => $ventaRezaga]);
    }

    public function update(Request $request, VentaRezagaEmpaque $ventaRezaga): JsonResponse
    {
        $validated = $request->validate([
            'comprador' => 'sometimes|string|max:200',
            'fecha_venta' => 'sometimes|date',
            'precio_kg' => 'sometimes|numeric|min:0',
            'status' => 'nullable|in:pendiente,pagada,cancelada',
            'observaciones' => 'nullable|string',
        ]);

        $ventaRezaga->update($validated);
        $ventaRezaga->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Venta de rezaga actualizada',
            'data' => $ventaRezaga,
        ]);
    }

    public function destroy(VentaRezagaEmpaque $ventaRezaga): JsonResponse
    {
        DB::transaction(function () use ($ventaRezaga) {
            foreach ($ventaRezaga->detalles as $det) {
                RezagaEmpaque::where('id', $det->rezaga_id)->update(['status' => 'pendiente']);
            }
            $ventaRezaga->detalles()->delete();
            $ventaRezaga->delete();
        });

        return response()->json(['success' => true, 'message' => 'Venta de rezaga eliminada']);
    }

    private function generarFolio(array $data): string
    {
        $count = VentaRezagaEmpaque::where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->count() + 1;
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        return "VREZ-{$entityId}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
