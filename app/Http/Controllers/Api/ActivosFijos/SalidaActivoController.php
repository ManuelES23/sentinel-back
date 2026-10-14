<?php

namespace App\Http\Controllers\Api\ActivosFijos;

use App\Events\FixedAssetUpdated;
use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\ActivosFijos\PermisosActivos;
use App\Services\ActivosFijos\RegistradorSalidaActivo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bajas, ventas y traspasos de activos fijos. El alcance sale de la URL (AlcanceActivos): un
 * activo o una salida de una empresa que el usuario no ve responde 404, antes de revisar permisos.
 */
class SalidaActivoController extends Controller
{
    private const SUBMODULO = 'salidas';
    private const POR_PAGINA = 25;
    private const POR_PAGINA_MAX = 100;

    public function __construct(
        private AlcanceActivos $alcance,
        private PermisosActivos $permisos,
        private RegistradorSalidaActivo $registrador,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');
        $request->validate([
            'type' => ['nullable', Rule::in(FixedAssetDisposal::TIPOS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'reversed' => ['nullable', 'boolean'],
            'enterprise_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->alcance->aplicar(FixedAssetDisposal::query(), $request);

        // Solo se filtra por una empresa que la petición pueda ver.
        if ($request->filled('enterprise_id') && in_array((int) $request->input('enterprise_id'), $this->alcance->idsVisibles($request), true)) {
            $query->where('enterprise_id', (int) $request->input('enterprise_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('from')) {
            $query->whereDate('disposal_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('disposal_date', '<=', $request->input('to'));
        }
        if ($request->filled('search')) {
            // '!' como carácter de escape: funciona igual en MySQL y SQLite.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('search')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw("fixed_asset_disposals.buyer_name LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('asset', fn ($a) => $a->where(function ($w) use ($term) {
                        $w->whereRaw("fixed_assets.code LIKE ? ESCAPE '!'", [$term])
                            ->orWhereRaw("fixed_assets.name LIKE ? ESCAPE '!'", [$term]);
                    }));
            });
        }

        // Los totales nunca suman las revertidas, se muestren o no.
        $totales = $this->totales((clone $query)->whereNull('reversed_at'));
        if (! $request->boolean('reversed')) {
            $query->whereNull('reversed_at');
        }

        $porPagina = min(max((int) $request->input('per_page', self::POR_PAGINA), 1), self::POR_PAGINA_MAX);
        $pagina = $query
            ->with(['enterprise:id,name,slug', 'destinationEnterprise:id,name,slug', 'destinationAsset:id,code,name', 'reversedBy:id,name'])
            ->orderByDesc('disposal_date')
            ->orderByDesc('id')
            ->paginate($porPagina);

        return response()->json(['success' => true, 'data' => $pagina, 'totals' => $totales]);
    }

    public function show(Request $request, FixedAssetDisposal $salida): JsonResponse
    {
        $this->alcance->autorizarSalida($salida, $request);
        $this->permisos->autorizar($request, self::SUBMODULO, 'view');

        return response()->json(['success' => true, 'data' => $this->detalle($salida)]);
    }

    /** Cifras de una salida antes de confirmarla. No escribe. */
    public function previa(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'create');
        $datos = $request->validate([
            'fixed_asset_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(FixedAssetDisposal::TIPOS)],
            'sale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ]);
        $activo = $this->activo((int) $datos['fixed_asset_id'], $request);
        $precio = isset($datos['sale_price']) ? number_format((float) $datos['sale_price'], 2, '.', '') : null;

        return response()->json([
            'success' => true,
            'data' => $this->registrador->previa($activo, $datos['type'], CarbonImmutable::createFromFormat('!Y-m-d', $datos['date']), $precio),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->permisos->autorizar($request, self::SUBMODULO, 'create');
        $request->validate(['type' => ['required', Rule::in(FixedAssetDisposal::TIPOS)]]);
        $datos = $request->validate($this->reglas((string) $request->input('type')));
        $activo = $this->activo((int) $datos['fixed_asset_id'], $request);

        $salida = $this->detalle($this->registrador->registrar($activo, $datos, $request->user(), $this->destinosPermitidos($request, $activo)));
        $this->emitir($salida);

        return response()->json([
            'success' => true,
            'message' => 'Salida registrada correctamente',
            'data' => $salida,
        ], 201);
    }

    /** Deshacer: marca la salida como revertida (el historial se conserva). */
    public function destroy(Request $request, FixedAssetDisposal $salida): JsonResponse
    {
        $this->alcance->autorizarSalida($salida, $request);
        $this->permisos->autorizar($request, self::SUBMODULO, 'delete');
        $datos = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->exigirPoderRetirarDestino($salida, $request);

        $deshecha = $this->detalle($this->registrador->deshacer($salida, $datos['reason'], $request->user()));
        $this->emitir($deshecha);

        return response()->json(['success' => true, 'message' => 'Salida deshecha correctamente', 'data' => $deshecha]);
    }

    /**
     * Deshacer un traspaso borra el activo que se dio de alta en la empresa receptora. Por simetría con el
     * registro (que exige `activos.create` allá), hay que ver esa empresa y tener `activos.delete` en ella.
     * El mensaje no revela datos del activo recibido.
     */
    private function exigirPoderRetirarDestino(FixedAssetDisposal $salida, Request $request): void
    {
        if ($salida->type !== 'traspaso' || ! $salida->destination_asset_id) {
            return;
        }

        $destino = in_array($salida->destination_enterprise_id, $this->alcance->idsVisibles($request), true)
            ? $this->alcance->empresasVisibles($request)->firstWhere('id', $salida->destination_enterprise_id)
            : null;

        if (! $destino || ! $this->permisos->puede($request->user(), $destino, 'activos', 'delete')) {
            throw ValidationException::withMessages([
                'salida' => 'Este traspaso dio de alta un activo en otra empresa en la que no tienes permiso para retirarlo. Deshazlo desde Grupo Espléndido o pídelo a quien administre esa empresa.',
            ]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    private function reglas(string $tipo): array
    {
        $base = [
            'fixed_asset_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(FixedAssetDisposal::TIPOS)],
            'disposal_date' => ['required', 'date_format:Y-m-d'],
            'authorized_by_name' => ['required', 'string', 'max:255'],
            'reason' => [$tipo === 'venta' ? 'nullable' : 'required', 'string', 'max:2000'],
        ];

        return match ($tipo) {
            'venta' => $base + [
                'sale_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
                'buyer_name' => ['required', 'string', 'max:255'],
                'buyer_address' => ['nullable', 'string', 'max:500'],
                'buyer_identification' => ['nullable', 'string', 'max:255'],
                'payment_method' => ['nullable', 'string', 'max:100'],
                'place' => ['nullable', 'string', 'max:255'],
            ],
            'siniestro' => $base + ['claim_reference' => ['nullable', 'string', 'max:255']],
            'traspaso' => $base + [
                'destination_enterprise_id' => ['required', 'integer'],
                'destination_branch_id' => ['required', 'integer'],
                'destination_entity_id' => ['required', 'integer'],
                'destination_area_id' => ['nullable', 'integer'],
            ],
            default => $base,
        };
    }

    /** 404 si el activo no existe o es de una empresa fuera del alcance. */
    private function activo(int $id, Request $request): FixedAsset
    {
        $activo = FixedAsset::findOrFail($id);
        $this->alcance->autorizarActivo($activo, $request);

        return $activo;
    }

    /**
     * Empresas a las que el usuario puede traspasar: visibles para él, distintas de la del activo y
     * con `activos.create` en ellas (el traspaso da de alta un activo ahí). Fuera de GE no hay ninguna.
     *
     * @return array<int, int>
     */
    private function destinosPermitidos(Request $request, FixedAsset $activo): array
    {
        return $this->alcance->empresasVisibles($request)
            ->filter(fn (Enterprise $e) => $e->id !== $activo->enterprise_id
                && $this->permisos->puede($request->user(), $e, 'activos', 'create'))
            ->pluck('id')
            ->all();
    }

    private function detalle(FixedAssetDisposal $salida): FixedAssetDisposal
    {
        return $salida->load(['enterprise:id,name,slug', 'destinationEnterprise:id,name,slug', 'destinationAsset:id,code,name', 'reversedBy:id,name']);
    }

    /** @return array{count: int, profit: string, loss: string, net: string} */
    private function totales(Builder $query): array
    {
        $fila = $query->toBase()->selectRaw(
            'COUNT(*) as total, '
            .'COALESCE(SUM(CASE WHEN result_amount > 0 THEN result_amount ELSE 0 END), 0) as utilidad, '
            .'COALESCE(SUM(CASE WHEN result_amount < 0 THEN result_amount ELSE 0 END), 0) as perdida, '
            .'COALESCE(SUM(result_amount), 0) as neto'
        )->first();
        $decimal = fn ($valor) => number_format((float) $valor, 2, '.', '');

        return [
            'count' => (int) $fila->total,
            'profit' => $decimal($fila->utilidad),
            'loss' => $decimal($fila->perdida),
            'net' => $decimal($fila->neto),
        ];
    }

    /** Un Reverb caído no debe convertir un guardado exitoso en un 500. */
    private function emitir(FixedAssetDisposal $salida): void
    {
        $slugs = array_filter([
            $salida->enterprise?->slug,
            $salida->destinationEnterprise?->slug,
        ]);

        try {
            foreach (array_unique($slugs) as $slug) {
                broadcast(new FixedAssetUpdated('disposal', ['id' => $salida->fixed_asset_id], $slug));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
