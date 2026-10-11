<?php

namespace App\Http\Controllers\Api\ActivosFijos;

use App\Events\FixedAssetUnitUpdated;
use App\Events\FixedAssetUpdated;
use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\FixedAssetReceiptUnit;
use App\Services\ActivosFijos\AltaActivosDesdeCompra;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\PermisosActivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administración → Activos Fijos → pestaña «Por dar de alta». Todo requiere
 * `activos.create` y respeta el alcance por empresa (GE ve todas).
 */
class AltaPendienteController extends Controller
{
    private const POR_PAGINA = 25;
    private const POR_PAGINA_MAX = 100;
    private const MAX_UNIDADES = 200;

    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
        private AltaActivosDesdeCompra $altas,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'create');
        $request->validate([
            'status' => ['nullable', Rule::in([...FixedAssetReceiptUnit::STATUSES, 'all'])],
            'search' => ['nullable', 'string', 'max:100'],
            'enterprise_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = $this->alcance->aplicar(FixedAssetReceiptUnit::query(), $request);

        // Solo se filtra por una empresa que la petición pueda ver.
        if ($request->filled('enterprise_id') && in_array((int) $request->input('enterprise_id'), $this->alcance->idsVisibles($request), true)) {
            $base->where('enterprise_id', (int) $request->input('enterprise_id'));
        }

        $conteos = (clone $base)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $query = (clone $base)->with([
            'product:id,code,name',
            'receipt:id,receipt_number,receipt_date,supplier_document,supplier_id,almacen_id,status',
            'receipt.supplier:id,business_name,trade_name,is_active',
            'receipt.almacen:id,name,code',
            'enterprise:id,name,slug',
            'fixedAsset:id,code,name',
        ]);

        $estado = $request->input('status', FixedAssetReceiptUnit::STATUS_PENDING);
        if ($estado !== 'all') {
            $query->where('status', $estado);
        }

        if ($request->filled('search')) {
            // '!' como carácter de escape: funciona igual en MySQL y SQLite.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('search')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('product', fn ($p) => $p->where(function ($w) use ($term) {
                    $w->whereRaw("products.name LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("products.code LIKE ? ESCAPE '!'", [$term]);
                }))->orWhereHas('receipt', fn ($r) => $r->where(function ($w) use ($term) {
                    $w->whereRaw("purchase_receipts.receipt_number LIKE ? ESCAPE '!'", [$term])
                        ->orWhereHas('supplier', fn ($s) => $s->whereRaw("suppliers.business_name LIKE ? ESCAPE '!'", [$term]));
                }));
            });
        }

        $porPagina = min(max((int) $request->input('per_page', self::POR_PAGINA), 1), self::POR_PAGINA_MAX);

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('purchase_receipt_id')
                ->orderBy('purchase_receipt_detail_id')
                ->orderBy('unit_number')
                ->paginate($porPagina),
            'meta' => ['conteos' => [
                'pending' => (int) ($conteos[FixedAssetReceiptUnit::STATUS_PENDING] ?? 0),
                'registered' => (int) ($conteos[FixedAssetReceiptUnit::STATUS_REGISTERED] ?? 0),
                'discarded' => (int) ($conteos[FixedAssetReceiptUnit::STATUS_DISCARDED] ?? 0),
            ]],
        ]);
    }

    public function alta(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'create');

        $validated = $request->validate([
            'units' => ['required', 'array', 'min:1', 'max:'.self::MAX_UNIDADES],
            'units.*.id' => ['required', 'integer', 'distinct'],
            'units.*.serial_number' => ['nullable', 'string', 'max:150'],
            'category_id' => ['required', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'subcategory_id' => ['nullable', Rule::exists('asset_categories', 'id')->whereNull('deleted_at')],
            'area_id' => ['nullable', 'exists:areas,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'model' => ['nullable', 'string', 'max:150'],
        ]);

        $activos = $this->altas->alta(
            $validated['units'],
            collect($validated)->only(['category_id', 'subcategory_id', 'area_id', 'brand_id', 'model'])->all(),
            $this->alcance->idsVisibles($request),
            $request->user(),
        );

        $this->emitir($activos->first()->enterprise_id, 'registered', ['unit_ids' => array_column($validated['units'], 'id')], true);

        $n = $activos->count();

        return response()->json([
            'success' => true,
            'message' => $n === 1 ? 'Activo fijo creado exitosamente' : "{$n} activos fijos creados exitosamente",
            'data' => ['assets' => $activos->map->only(['id', 'code', 'name'])->values()],
        ], 201);
    }

    public function descartar(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, 'activos', 'create');

        $validated = $request->validate([
            'unit_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_UNIDADES],
            'unit_ids.*' => ['integer', 'distinct'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $unidades = $this->altas->descartar(
            $validated['unit_ids'],
            $validated['reason'],
            $this->alcance->idsVisibles($request),
            $request->user(),
        );

        // Desde GE se pueden descartar unidades de varias empresas: un evento por empresa dueña.
        foreach ($unidades->groupBy('enterprise_id') as $empresaId => $deLaEmpresa) {
            $this->emitir((int) $empresaId, 'discarded', ['unit_ids' => $deLaEmpresa->pluck('id')->values()->all()]);
        }

        return response()->json([
            'success' => true,
            'message' => $unidades->count() === 1 ? 'Unidad descartada' : "{$unidades->count()} unidades descartadas",
            'data' => ['descartadas' => $unidades->count()],
        ]);
    }

    /** Un Reverb caído no debe convertir un guardado exitoso en un 500. */
    private function emitir(int $empresaId, string $accion, array $datos, bool $tambienActivos = false): void
    {
        $slug = Enterprise::whereKey($empresaId)->value('slug');

        try {
            broadcast(new FixedAssetUnitUpdated($accion, $datos, $slug));
            if ($tambienActivos) {
                broadcast(new FixedAssetUpdated('created', $datos, $slug));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
