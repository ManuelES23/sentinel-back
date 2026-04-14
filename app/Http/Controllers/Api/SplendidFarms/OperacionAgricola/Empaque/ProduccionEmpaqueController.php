<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProduccionEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code,abbreviation',
        'proceso:id,folio_proceso,productor_id,lote_id,recepcion_id',
        'proceso.productor:id,nombre,apellido',
        'proceso.lote:id,nombre,numero_lote',
        'proceso.recepcion:id,salida_campo_id',
        'proceso.recepcion.salidaCampo:id,variedad_id',
        'proceso.recepcion.salidaCampo.variedad:id,nombre',
        'variedad:id,nombre',
        'recipe:id,name,code,recipe_type,peso_pieza,output_quantity',
        'creador:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = ProduccionEmpaque::with($this->eagerLoad);

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
                $q->where('folio_produccion', 'like', "%{$search}%")
                  ->orWhere('numero_pallet', 'like', "%{$search}%")
                  ->orWhere('tipo_empaque', 'like', "%{$search}%")
                  ->orWhere('etiqueta', 'like', "%{$search}%");
            });
        }

        $producciones = $query->orderByDesc('fecha_produccion')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $producciones]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'entity_id' => 'required|exists:entities,id',
            'proceso_id' => 'required|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'required|date',
            'turno' => 'nullable|string|max:50',
            'variedad_id' => 'nullable|exists:variedades,id',
            'linea_empaque' => 'nullable|string|max:100',
            'numero_pallet' => 'nullable|string|max:100',
            'pallet_qr_id' => 'nullable|string|max:36',
            'total_cajas' => 'required|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'is_cola' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        $validated['status'] = $validated['status'] ?? 'empacado';
        $validated['is_cola'] = $validated['is_cola'] ?? false;
        $validated['created_by'] = $request->user()->id;
        $validated['folio_produccion'] = $this->generarFolio($validated);

        // Auto-resolver variedad_id desde el proceso si no viene
        if (empty($validated['variedad_id']) && !empty($validated['proceso_id'])) {
            $proceso = ProcesoEmpaque::with([
                'etapa:id,variedad_id',
                'recepcion:id,salida_campo_id',
                'recepcion.salidaCampo:id,variedad_id',
            ])->find($validated['proceso_id']);

            $variedadId = $proceso?->etapa?->variedad_id
                ?? $proceso?->recepcion?->salidaCampo?->variedad_id;

            if ($variedadId) {
                $validated['variedad_id'] = $variedadId;
            }
        }

        // Generar UUID para QR del pallet si no viene (pallet nuevo)
        if (empty($validated['pallet_qr_id'])) {
            $validated['pallet_qr_id'] = (string) Str::uuid();
        }

        // Auto-calcular peso neto si hay receta con peso_pieza y no se envió peso manual
        if (!empty($validated['recipe_id']) && empty($validated['peso_neto_kg'])) {
            $recipe = Recipe::find($validated['recipe_id']);
            if ($recipe && $recipe->peso_pieza > 0) {
                $validated['peso_neto_kg'] = round($validated['total_cajas'] * (float) $recipe->peso_pieza, 2);
            }
        }

        $produccion = ProduccionEmpaque::create($validated);
        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Producción registrada exitosamente',
            'data' => $produccion,
        ], 201);
    }

    public function show(ProduccionEmpaque $produccion): JsonResponse
    {
        $produccion->load([...$this->eagerLoad, 'embarqueDetalles.embarque']);

        return response()->json(['success' => true, 'data' => $produccion]);
    }

    public function update(Request $request, ProduccionEmpaque $produccion): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => 'sometimes|exists:entities,id',
            'proceso_id' => 'nullable|exists:proceso_empaque,id',
            'recipe_id' => 'nullable|exists:recipes,id',
            'fecha_produccion' => 'sometimes|date',
            'turno' => 'nullable|string|max:50',
            'variedad_id' => 'nullable|exists:variedades,id',
            'linea_empaque' => 'nullable|string|max:100',
            'numero_pallet' => 'nullable|string|max:100',
            'total_cajas' => 'sometimes|integer|min:1',
            'peso_neto_kg' => 'nullable|numeric|min:0',
            'tipo_empaque' => 'nullable|string|max:100',
            'etiqueta' => 'nullable|string|max:100',
            'calibre' => 'nullable|string|max:50',
            'categoria' => 'nullable|string|max:50',
            'status' => 'nullable|in:empacado,en_almacen,embarcado',
            'is_cola' => 'nullable|boolean',
            'observaciones' => 'nullable|string',
        ]);

        $produccion->update($validated);
        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Producción actualizada',
            'data' => $produccion,
        ]);
    }

    public function destroy(ProduccionEmpaque $produccion): JsonResponse
    {
        $produccion->delete();

        return response()->json(['success' => true, 'message' => 'Producción eliminada']);
    }

    /**
     * Obtener el siguiente número de pallet consecutivo para una entidad.
     */
    public function nextPalletNumber(Request $request): JsonResponse
    {
        $request->validate(['entity_id' => 'required|exists:entities,id']);

        $entity = Entity::find($request->entity_id);
        $abbreviation = $entity->abbreviation ?: 'PLT';

        $lastPallet = ProduccionEmpaque::where('entity_id', $entity->id)
            ->where('numero_pallet', 'like', $abbreviation . '-%')
            ->selectRaw("MAX(CAST(SUBSTRING(numero_pallet, ?) AS UNSIGNED)) as max_num", [strlen($abbreviation) + 2])
            ->value('max_num');

        $nextNum = ($lastPallet ?? 0) + 1;

        return response()->json([
            'success' => true,
            'data' => [
                'abbreviation' => $abbreviation,
                'next_number' => str_pad($nextNum, 4, '0', STR_PAD_LEFT),
                'full_pallet' => $abbreviation . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT),
            ],
        ]);
    }

    /**
     * Toggle estado cuarto frío de un pallet.
     */
    public function toggleCuartoFrio(ProduccionEmpaque $produccion): JsonResponse
    {
        $produccion->update([
            'en_cuarto_frio' => !$produccion->en_cuarto_frio,
        ]);

        $produccion->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => $produccion->en_cuarto_frio
                ? 'Pallet marcado en cuarto frío'
                : 'Pallet retirado de cuarto frío',
            'data' => $produccion,
        ]);
    }

    /**
     * Toggle masivo de cuarto frío para múltiples pallets.
     */
    public function toggleCuartoFrioMasivo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:produccion_empaque,id',
            'en_cuarto_frio' => 'required|boolean',
        ]);

        ProduccionEmpaque::whereIn('id', $validated['ids'])
            ->update(['en_cuarto_frio' => $validated['en_cuarto_frio']]);

        $label = $validated['en_cuarto_frio'] ? 'ingresados a' : 'retirados de';

        return response()->json([
            'success' => true,
            'message' => count($validated['ids']) . " pallet(s) {$label} cuarto frío",
        ]);
    }

    /**
     * Listar pallets cola (incompletos) para poder continuar al día siguiente.
     */
    public function colaPallets(Request $request): JsonResponse
    {
        $query = ProduccionEmpaque::where('is_cola', true)
            ->with([
                'entity:id,name,code,abbreviation',
                'proceso:id,folio_proceso,productor_id,lote_id',
                'proceso.productor:id,nombre,apellido',
                'recipe:id,name,code',
            ]);

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('temporada_id')) {
            $query->where('temporada_id', $request->temporada_id);
        }

        $pallets = $query->orderByDesc('fecha_produccion')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $pallets]);
    }

    private function generarFolio(array $data): string
    {
        $entityId = str_pad($data['entity_id'], 2, '0', STR_PAD_LEFT);
        $prefix = "PROD-{$entityId}-";

        $lastFolio = ProduccionEmpaque::withTrashed()
            ->where('temporada_id', $data['temporada_id'])
            ->where('entity_id', $data['entity_id'])
            ->where('folio_produccion', 'like', $prefix . '%')
            ->orderByDesc('folio_produccion')
            ->value('folio_produccion');

        $nextNumber = $lastFolio
            ? (int) substr($lastFolio, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
