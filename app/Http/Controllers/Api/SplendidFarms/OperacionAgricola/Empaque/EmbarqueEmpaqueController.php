<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\EmbarqueEmpaque;
use App\Models\ProduccionEmpaque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmbarqueEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code',
        'detalles.produccion:id,numero_pallet,is_cola,variedad_id,marca,presentacion,calibre,tipo_empaque,recipe_id,total_cajas',
        'detalles.produccion.variedad:id,nombre',
        'detalles.produccion.recipe:id,name,output_product_id',
        'detalles.produccion.recipe.outputProduct:id,name,brand_id',
        'detalles.produccion.recipe.outputProduct.brand:id,name,code',
        'detalles.produccion.detalles',
        'detalles.produccion.detalles.proceso:id,folio_proceso,etapa_id,recepcion_id',
        'detalles.produccion.detalles.proceso.productor:id,nombre,apellido',
        'detalles.produccion.detalles.proceso.lote:id,nombre,numero_lote',
        'detalles.produccion.detalles.proceso.etapa:id,nombre,variedad_id',
        'detalles.produccion.detalles.proceso.etapa.variedad:id,nombre',
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
                  ->orWhere('manifiesto', 'like', "%{$search}%")
                  ->orWhere('cliente', 'like', "%{$search}%")
                  ->orWhere('destino', 'like', "%{$search}%")
                  ->orWhere('numero_contenedor', 'like', "%{$search}%");
            });
        }

        $embarques = $query->orderByDesc('fecha_embarque')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $embarques]);
    }

    /**
     * Pallets disponibles para embarque (en_almacen + en cuarto frío)
     */
    public function palletsDisponibles(Request $request): JsonResponse
    {
        $query = ProduccionEmpaque::with([
            'proceso.productor:id,nombre,apellido',
            'proceso.lote:id,nombre,numero_lote',
            'proceso.etapa.variedad:id,nombre',
            'proceso.recepcion.salidaCampo.variedad:id,nombre',
            'variedad:id,nombre',
        ])
        ->whereIn('status', ['empacado', 'en_almacen'])
        ->where('en_cuarto_frio', true);

        if ($request->filled('temporada_id')) {
            $query->where('temporada_id', $request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $pallets = $query->orderBy('numero_pallet')->get();

        return response()->json(['success' => true, 'data' => $pallets]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'manifiesto' => 'nullable|string|max:200',
            'genera_manifiesto' => 'nullable|boolean',
            'tipo_venta' => 'required|in:exportacion,nacional',
            'fecha_embarque' => 'required|date',
            'status' => 'nullable|in:programado,cargando,en_transito,entregado,cancelado',
            'observaciones' => 'nullable|string',

            // Datos empresa (snapshot)
            'empresa_razon_social' => 'nullable|string|max:200',
            'empresa_rfc' => 'nullable|string|max:50',
            'empresa_direccion' => 'nullable|string|max:300',
            'empresa_ciudad' => 'nullable|string|max:100',
            'empresa_pais' => 'nullable|string|max:100',
            'empresa_agente_aduana_mx' => 'nullable|string|max:200',
            'factura' => 'nullable|string|max:100',

            // Consignatario
            'consignatario_id' => 'nullable|exists:consignatarios,id',
            'consigne_nombre' => 'nullable|string|max:200',
            'consigne_rfc' => 'nullable|string|max:50',
            'consigne_direccion' => 'nullable|string|max:300',
            'consigne_ciudad' => 'nullable|string|max:100',
            'consigne_pais' => 'nullable|string|max:100',
            'consigne_agente_aduana_eua' => 'nullable|string|max:200',
            'consigne_bodega' => 'nullable|string|max:200',

            // Destino / Cliente
            'cliente' => 'nullable|string|max:200',
            'destino' => 'nullable|string|max:200',
            'destino_consignatario_id' => 'nullable|exists:consignatarios,id',

            // Transporte
            'transportista' => 'nullable|string|max:150',
            'vehiculo' => 'nullable|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'rfc_chofer' => 'nullable|string|max:50',
            'numero_contenedor' => 'nullable|string|max:100',
            'marca_caja' => 'nullable|string|max:100',
            'placa_caja' => 'nullable|string|max:30',
            'placa_tracto' => 'nullable|string|max:30',
            'marca_tracto' => 'nullable|string|max:100',
            'scac' => 'nullable|string|max:20',
            'sello' => 'nullable|string|max:100',
            'temperatura' => 'nullable|numeric',
            'capacidad_volumen' => 'nullable|string|max:50',

            // Carga
            'codigo_rastreo' => 'nullable|string|max:100',
            'espacios_caja' => 'nullable|integer|min:1|max:50',

            // Pallets con posición
            'pallets' => 'required|array|min:1',
            'pallets.*.id' => 'required|exists:produccion_empaque,id',
            'pallets.*.posicion_carga' => 'nullable|integer|min:1',
        ]);

        $validated['status'] = $validated['status'] ?? 'programado';
        $validated['created_by'] = $request->user()->id;

        $palletsInput = $validated['pallets'];
        unset($validated['pallets']);

        $palletIds = array_column($palletsInput, 'id');
        $posiciones = collect($palletsInput)->keyBy('id');

        // Load pallets with relationships for snapshot
        $pallets = ProduccionEmpaque::with([
            'proceso.productor:id,nombre,apellido',
            'proceso.lote:id,nombre,numero_lote',
            'proceso.etapa.variedad:id,nombre',
            'proceso.recepcion.salidaCampo.variedad:id,nombre',
            'variedad:id,nombre',
        ])->whereIn('id', $palletIds)->get();

        // Calculate totals from actual pallet data
        $validated['total_pallets'] = $pallets->count();
        $validated['total_cajas'] = $pallets->sum('total_cajas');
        $validated['peso_total_kg'] = $pallets->sum('peso_neto_kg');

        $embarque = DB::transaction(function () use ($validated, $pallets) {
            // Generar folio dentro de la transacción con lock para evitar duplicados por concurrencia
            $validated['folio_embarque'] = $this->generarFolio($validated);

            $embarque = EmbarqueEmpaque::create($validated);

            foreach ($pallets as $pallet) {
                $proceso = $pallet->proceso;
                $productor = $proceso?->productor;
                $productorName = $productor ? trim("{$productor->nombre} {$productor->apellido}") : null;
                $variedad = $pallet->variedad?->nombre
                    ?? $proceso?->etapa?->variedad?->nombre
                    ?? $proceso?->recepcion?->salidaCampo?->variedad?->nombre;
                $lote = $proceso?->lote?->nombre ?? $proceso?->lote?->numero_lote;

                $embarque->detalles()->create([
                    'produccion_id' => $pallet->id,
                    'numero_pallet' => $pallet->numero_pallet,
                    'folio_produccion' => $pallet->folio_produccion,
                    'productor' => $productorName,
                    'variedad' => $variedad,
                    'lote' => $pallet->lote_producto_terminado ?: $lote,
                    'marca' => $pallet->marca,
                    'lote_producto_terminado' => $pallet->lote_producto_terminado,
                    'presentacion' => $pallet->presentacion ?: $pallet->tipo_empaque,
                    'tipo_empaque' => $pallet->tipo_empaque,
                    'etiqueta' => $pallet->etiqueta,
                    'calibre' => $pallet->calibre,
                    'fecha_produccion' => $pallet->fecha_produccion,
                    'cajas' => $pallet->total_cajas,
                    'peso_kg' => $pallet->peso_neto_kg,
                    'is_cola' => $pallet->is_cola,
                    'posicion_carga' => $posiciones[$pallet->id]['posicion_carga'] ?? null,
                ]);

                // Mark production as shipped and out of cuarto frío
                $pallet->update(['status' => 'embarcado', 'en_cuarto_frio' => false]);
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
            'manifiesto' => 'nullable|string|max:200',
            'tipo_venta' => 'sometimes|in:exportacion,nacional',
            'fecha_embarque' => 'sometimes|date',
            'status' => 'nullable|in:programado,cargando,en_transito,entregado,cancelado',
            'observaciones' => 'nullable|string',
            'factura' => 'nullable|string|max:100',
            // Empresa snapshot
            'empresa_razon_social' => 'nullable|string|max:200',
            'empresa_rfc' => 'nullable|string|max:50',
            'empresa_direccion' => 'nullable|string|max:300',
            'empresa_ciudad' => 'nullable|string|max:100',
            'empresa_pais' => 'nullable|string|max:100',
            'empresa_agente_aduana_mx' => 'nullable|string|max:200',
            // Consignatario
            'consignatario_id' => 'nullable|exists:consignatarios,id',
            'consigne_nombre' => 'nullable|string|max:200',
            'consigne_rfc' => 'nullable|string|max:50',
            'consigne_direccion' => 'nullable|string|max:300',
            'consigne_ciudad' => 'nullable|string|max:100',
            'consigne_pais' => 'nullable|string|max:100',
            'consigne_agente_aduana_eua' => 'nullable|string|max:200',
            'consigne_bodega' => 'nullable|string|max:200',
            // Destino / Cliente
            'cliente' => 'nullable|string|max:200',
            'destino' => 'nullable|string|max:200',
            'destino_consignatario_id' => 'nullable|exists:consignatarios,id',
            // Transporte
            'transportista' => 'nullable|string|max:150',
            'vehiculo' => 'nullable|string|max:150',
            'chofer' => 'nullable|string|max:150',
            'rfc_chofer' => 'nullable|string|max:50',
            'numero_contenedor' => 'nullable|string|max:100',
            'marca_caja' => 'nullable|string|max:100',
            'placa_caja' => 'nullable|string|max:30',
            'placa_tracto' => 'nullable|string|max:30',
            'marca_tracto' => 'nullable|string|max:100',
            'scac' => 'nullable|string|max:20',
            'sello' => 'nullable|string|max:100',
            'temperatura' => 'nullable|numeric',
            'capacidad_volumen' => 'nullable|string|max:50',
            'codigo_rastreo' => 'nullable|string|max:100',
            'espacios_caja' => 'nullable|integer|min:1|max:50',
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
                ProduccionEmpaque::where('id', $det->produccion_id)
                    ->update(['status' => 'en_almacen', 'en_cuarto_frio' => true]);
            }
            $embarque->detalles()->delete();
            $embarque->delete();
        });

        return response()->json(['success' => true, 'message' => 'Embarque eliminado']);
    }

    private function generarFolio(array $data): string
    {
        $prefix = $data['tipo_venta'] === 'exportacion' ? 'EXP' : 'NAC';
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        $pattern = "{$prefix}-{$entityId}-%";

        $lastFolio = EmbarqueEmpaque::withTrashed()
            ->where('entity_id', $data['entity_id'])
            ->where('folio_embarque', 'like', $pattern)
            ->orderByDesc('folio_embarque')
            ->lockForUpdate()
            ->value('folio_embarque');

        $nextNumber = $lastFolio ? (int) substr($lastFolio, -4) + 1 : 1;

        return "{$prefix}-{$entityId}-" . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
