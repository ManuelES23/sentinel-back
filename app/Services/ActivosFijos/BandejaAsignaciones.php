<?php
// sentinel-back/app/Services/ActivosFijos/BandejaAsignaciones.php

namespace App\Services\ActivosFijos;

use App\Models\Area;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
