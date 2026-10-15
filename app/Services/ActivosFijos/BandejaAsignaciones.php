<?php
// sentinel-back/app/Services/ActivosFijos/BandejaAsignaciones.php

namespace App\Services\ActivosFijos;

use App\Models\Area;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bandeja de Asignaciones: cifras de las tarjetas, cartas por firmar y
 * bitácora de movimientos (entregas y devoluciones). Solo lectura. Todo pasa
 * por el alcance de AlcanceActivos; un `enterprise_id` solo cuenta si es una
 * empresa visible. Los parámetros con formato inválido se ignoran.
 *
 * Fechas: `assigned_at` y `returned_at` son `date`, pero SQLite las guarda como
 * `Y-m-d 00:00:00`; por eso se comparan con rangos semiabiertos de cadenas `Y-m-d`.
 */
class BandejaAsignaciones
{
    /** Una carta está atrasada con MÁS de estos días sin firmar. */
    public const ATRASO_CARTA_DIAS = 30;

    public const TARJETAS = ['activas', 'cartas', 'entregas_mes', 'devoluciones_mes'];

    public const TIPOS = ['entrega', 'devolucion'];

    public const POR_PAGINA = 25;

    public const POR_PAGINA_MAX = 100;

    public const LIMITE_CARTAS = 12;

    public const LIMITE_CARTAS_MAX = 50;

    /** Lo que necesitan la ficha, la fila de cartas y la bitácora (sin N+1). */
    public const RELACIONES = [
        'asset:id,code,name,image,category_id,subcategory_id,brand_id,serial_number,status,enterprise_id',
        'asset.category:id,name,icon',
        'asset.subcategory:id,name',
        'asset.brand:id,name',
        'enterprise:id,name,slug',
        'area:id,name,code',
    ];

    public function __construct(private AlcanceActivos $alcance)
    {
    }

    public function resumen(Request $request): array
    {
        $ids = $this->idsEmpresas($request);
        [$desde, $hasta] = $this->mes();
        $limiteAtraso = today()->subDays(self::ATRASO_CARTA_DIAS)->toDateString();

        return [
            'activas' => $this->base($ids)->whereNull('returned_at')->count(),
            'cartas_por_firmar' => $this->pendientes($ids)->count(),
            'cartas_atrasadas' => $this->pendientes($ids)->where('assigned_at', '<', $limiteAtraso)->count(),
            'entregas_mes' => $this->base($ids)->where('assigned_at', '>=', $desde)->where('assigned_at', '<', $hasta)->count(),
            'devoluciones_mes' => $this->base($ids)->where('returned_at', '>=', $desde)->where('returned_at', '<', $hasta)->count(),
            'mes' => today()->format('Y-m'),
            'opciones' => $this->opciones($request, $ids),
        ];
    }

    /** @return array{data: Collection<int, FixedAssetAssignment>, total: int} */
    public function cartasPendientes(Request $request): array
    {
        $ids = $this->idsEmpresas($request);
        $limite = min($this->entero($request->query('limit')) ?? self::LIMITE_CARTAS, self::LIMITE_CARTAS_MAX);

        $filas = $this->pendientes($ids)
            ->with(self::RELACIONES)
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->limit($limite)
            ->get();
        $filas->each(fn (FixedAssetAssignment $a) => $a->setAttribute('dias_sin_firma', $this->diasDesde($a->assigned_at)));

        return ['data' => $filas->values(), 'total' => $this->pendientes($ids)->count()];
    }

    /**
     * Bitácora: cada asignación da una entrega (`assigned_at`) y, si terminó, una
     * devolución (`returned_at`). Más reciente primero; el mismo día la entrega va
     * antes que la devolución (en una reasignación lo último que pasó es la entrega).
     */
    public function movimientos(Request $request): array
    {
        $ids = $this->idsEmpresas($request);
        $tipo = $this->opcion($request->query('tipo'), self::TIPOS);
        $tarjeta = $this->opcion($request->query('tarjeta'), self::TARJETAS);
        $porPagina = min($this->entero($request->query('per_page')) ?? self::POR_PAGINA, self::POR_PAGINA_MAX);
        $pagina = $this->entero($request->query('page')) ?? 1;

        $partes = [];
        if ($this->incluyeEntregas($tipo, $tarjeta)) {
            $partes[] = $this->entregas($request, $ids, $tarjeta)->toBase()->selectRaw(
                "fixed_asset_assignments.id as asignacion_id, 'entrega' as tipo, fixed_asset_assignments.assigned_at as fecha, 1 as orden_dia"
            );
        }
        if ($this->incluyeDevoluciones($tipo, $tarjeta)) {
            $partes[] = $this->devoluciones($request, $ids, $tarjeta)->toBase()->selectRaw(
                "fixed_asset_assignments.id as asignacion_id, 'devolucion' as tipo, fixed_asset_assignments.returned_at as fecha, 0 as orden_dia"
            );
        }

        if ($partes === []) {
            return ['data' => collect(), 'current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => $porPagina, 'meses' => collect()];
        }

        /** @var QueryBuilder $union */
        $union = array_shift($partes);
        foreach ($partes as $parte) {
            $union->unionAll($parte);
        }

        $resultado = DB::query()
            ->fromSub($union, 'movimientos')
            ->orderByDesc('fecha')
            ->orderByDesc('orden_dia')
            ->orderByDesc('asignacion_id')
            ->paginate($porPagina, ['*'], 'page', $pagina);

        $filas = collect($resultado->items());
        $asignaciones = FixedAssetAssignment::with(self::RELACIONES)
            ->whereIn('id', $filas->pluck('asignacion_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $data = $filas->map(fn ($fila) => [
            'tipo' => $fila->tipo,
            'fecha' => substr((string) $fila->fecha, 0, 10),
            'asignacion' => $asignaciones->get((int) $fila->asignacion_id),
        ])->values();

        $meses = $data->map(fn (array $m) => substr($m['fecha'], 0, 7))
            ->unique()
            ->values()
            ->map(fn (string $mes) => $this->totalesDelMes($request, $ids, $tipo, $tarjeta, $mes));

        return [
            'data' => $data,
            'current_page' => $resultado->currentPage(),
            'last_page' => $resultado->lastPage(),
            'total' => $resultado->total(),
            'per_page' => $resultado->perPage(),
            'meses' => $meses,
        ];
    }

    /** Las empresas visibles, o solo la elegida si es una de ellas. @return array<int> */
    private function idsEmpresas(Request $request): array
    {
        $ids = $this->alcance->idsVisibles($request);
        $elegida = $this->entero($request->query('enterprise_id'));

        return $elegida !== null && in_array($elegida, $ids, true) ? [$elegida] : $ids;
    }

    private function base(array $ids): Builder
    {
        return FixedAssetAssignment::query()->whereIn('fixed_asset_assignments.enterprise_id', $ids);
    }

    /** Activas sin carta firmada. */
    private function pendientes(array $ids): Builder
    {
        return $this->base($ids)
            ->whereNull('fixed_asset_assignments.returned_at')
            ->whereNull('fixed_asset_assignments.signed_document_path');
    }

    private function incluyeEntregas(?string $tipo, ?string $tarjeta): bool
    {
        return $tipo !== 'devolucion' && $tarjeta !== 'devoluciones_mes';
    }

    private function incluyeDevoluciones(?string $tipo, ?string $tarjeta): bool
    {
        return $tipo !== 'entrega' && ! in_array($tarjeta, ['activas', 'cartas', 'entregas_mes'], true);
    }

    /** Asignaciones que aportan una entrega, con los filtros y la tarjeta. */
    private function entregas(Request $request, array $ids, ?string $tarjeta): Builder
    {
        $consulta = $this->filtrada($request, $ids);

        match ($tarjeta) {
            'activas' => $consulta->whereNull('fixed_asset_assignments.returned_at'),
            'cartas' => $consulta->whereNull('fixed_asset_assignments.returned_at')
                ->whereNull('fixed_asset_assignments.signed_document_path'),
            'entregas_mes' => $this->enRango($consulta, 'assigned_at', ...$this->mes()),
            default => null,
        };

        return $consulta;
    }

    /** Asignaciones que aportan una devolución, con los filtros y la tarjeta. */
    private function devoluciones(Request $request, array $ids, ?string $tarjeta): Builder
    {
        $consulta = $this->filtrada($request, $ids)->whereNotNull('fixed_asset_assignments.returned_at');

        if ($tarjeta === 'devoluciones_mes') {
            $this->enRango($consulta, 'returned_at', ...$this->mes());
        }

        return $consulta;
    }

    private function enRango(Builder $consulta, string $columna, string $desde, string $hasta): Builder
    {
        return $consulta
            ->where("fixed_asset_assignments.{$columna}", '>=', $desde)
            ->where("fixed_asset_assignments.{$columna}", '<', $hasta);
    }

    /** Alcance + área, tipo de activo y búsqueda (comunes a entregas y devoluciones). */
    private function filtrada(Request $request, array $ids): Builder
    {
        $consulta = $this->base($ids);

        if ($area = $this->entero($request->query('area_id'))) {
            $consulta->where('fixed_asset_assignments.area_id', $area);
        }
        if ($tipoActivo = $this->entero($request->query('category_id'))) {
            $consulta->whereHas('asset', fn ($a) => $a->where('fixed_assets.category_id', $tipoActivo));
        }

        $texto = $this->texto($request->query('search'));
        if ($texto !== '') {
            // '!' como carácter de escape: funciona igual en MySQL y SQLite.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto).'%';
            $consulta->where(function ($w) use ($term) {
                foreach (['assignee_name', 'assignee_position', 'assignee_department'] as $columna) {
                    $w->orWhereRaw("fixed_asset_assignments.{$columna} LIKE ? ESCAPE '!'", [$term]);
                }
                $w->orWhereHas('asset', fn ($a) => $a->where(function ($x) use ($term) {
                    foreach (['code', 'name', 'serial_number'] as $columna) {
                        $x->orWhereRaw("fixed_assets.{$columna} LIKE ? ESCAPE '!'", [$term]);
                    }
                    $x->orWhereHas('brand', fn ($b) => $b->whereRaw("brands.name LIKE ? ESCAPE '!'", [$term]));
                }));
                $w->orWhereHas('area', fn ($ar) => $ar->whereRaw("areas.name LIKE ? ESCAPE '!'", [$term]));
            });
        }

        return $consulta;
    }

    /** Entregas y devoluciones de un mes (`Y-m`) con los mismos filtros de la bitácora. */
    private function totalesDelMes(Request $request, array $ids, ?string $tipo, ?string $tarjeta, string $mes): array
    {
        $desde = $mes.'-01';
        $hasta = Carbon::parse($desde)->addMonth()->toDateString();

        return [
            'mes' => $mes,
            'entregas' => $this->incluyeEntregas($tipo, $tarjeta)
                ? $this->enRango($this->entregas($request, $ids, $tarjeta), 'assigned_at', $desde, $hasta)->count()
                : 0,
            'devoluciones' => $this->incluyeDevoluciones($tipo, $tarjeta)
                ? $this->enRango($this->devoluciones($request, $ids, $tarjeta), 'returned_at', $desde, $hasta)->count()
                : 0,
        ];
    }

    /** Mes en curso como rango semiabierto [día 1, día 1 del siguiente). @return array{0: string, 1: string} */
    private function mes(): array
    {
        $inicio = today()->startOfMonth();

        return [$inicio->toDateString(), $inicio->copy()->addMonth()->toDateString()];
    }

    private function opciones(Request $request, array $ids): array
    {
        $porNombre = fn (Collection $filas) => $filas
            ->sortBy(fn ($f) => Str::ascii($f->name))
            ->values()
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name]);

        $areas = Area::query()
            ->whereIn('id', $this->base($ids)->whereNotNull('area_id')->select('area_id'))
            ->get(['id', 'name']);
        $tipos = AssetCategory::query()
            ->whereIn('id', FixedAsset::withTrashed()
                ->whereIn('id', $this->base($ids)->select('fixed_asset_id'))
                ->select('category_id'))
            ->get(['id', 'name']);
        $empresas = $this->alcance->esCorporativo($request)
            ? $this->alcance->empresasVisibles($request)->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values()
            : collect();

        return ['areas' => $porNombre($areas), 'tipos' => $porNombre($tipos), 'empresas' => $empresas];
    }

    private function diasDesde(CarbonInterface $fecha): int
    {
        return (int) $fecha->copy()->startOfDay()->diffInDays(today(), true);
    }

    /** Entero positivo canónico (hasta 10 dígitos) o null. */
    private function entero(mixed $valor): ?int
    {
        return is_scalar($valor) && preg_match('/^[1-9]\d{0,9}$/', (string) $valor) ? (int) $valor : null;
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? mb_substr(trim((string) $valor), 0, 100) : '';
    }

    private function opcion(mixed $valor, array $validos): ?string
    {
        return is_scalar($valor) && in_array((string) $valor, $validos, true) ? (string) $valor : null;
    }
}
