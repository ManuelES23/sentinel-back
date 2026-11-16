<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Variedad;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProductoCalibre;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liga cada variedad de Abastecimiento con el producto del CRM de la misma empresa del que salen sus
 * calibres (`abs_variedades.producto_id`). En el CRM cada producto es una variedad comercial
 * («Kent Mango», «Yellow Corn») con sus calibres propios (`crm_producto_calibres`); Abastecimiento no
 * tiene catálogo de calibres ni depende del de Splendid Farms.
 *
 * Propuesta automática (solo para variedades sin liga; una liga ya puesta nunca se cambia sola):
 *  1. un producto activo de la empresa cuyo nombre contenga el de la variedad como palabras completas
 *     («Kent» → «Kent Mango»); con varios, el de commodity igual al cultivo y luego el de menor id
 *     y luego el nombre más corto («Kent Mango» antes que «Kent Organic Mango»);
 * *  2. solo en la migración (`aplicar(true)`): el producto ligado a la variedad homónima de Splendid
 *     Farms (`crm_productos.variedad_id`), para casar «Amarillo» con «Yellow Corn».
 */
class EnlaceProductosCrm
{
    public static function proponer(Variedad $variedad, int $empresaId, bool $usarLigaSf = false): ?CrmProducto
    {
        $variedad->loadMissing('cultivo');
        $productos = CrmProducto::query()->where('empresa_id', $empresaId)->where('activo', true)
            ->orderBy('id')->get(['id', 'nombre', 'commodity', 'variedad_id']);
        $nombre = self::clave($variedad->nombre);
        $cultivo = self::clave((string) $variedad->cultivo?->nombre);

        $porNombre = $productos->filter(fn (CrmProducto $p) => $nombre !== ''
            && preg_match('/(^| )'.preg_quote($nombre, '/').'( |$)/u', self::clave($p->nombre)));
        if ($elegido = self::mejor($porNombre, $cultivo)) {
            return $elegido;
        }

        return $usarLigaSf ? self::mejor(self::porLigaSf($productos, $nombre, $cultivo), $cultivo) : null;
    }

    /** Liga las variedades sin producto; devuelve cuántas quedaron ligadas. */
    public static function aplicar(bool $usarLigaSf = false): int
    {
        $ligadas = 0;
        Variedad::query()->whereNull('producto_id')->with('cultivo')->get()
            ->each(function (Variedad $v) use (&$ligadas, $usarLigaSf) {
                $empresaId = (int) $v->cultivo?->enterprise_id;
                if ($empresaId && ($p = self::proponer($v, $empresaId, $usarLigaSf))) {
                    $v->forceFill(['producto_id' => $p->id])->saveQuietly();
                    $ligadas++;
                }
            });

        return $ligadas;
    }

    /**
     * Productos de la empresa para elegir la liga a mano: `[{id, nombre, commodity, calibres, activo}]`
     * (calibres activos), por commodity y nombre.
     *
     * @return list<array{id: int, nombre: string, commodity: ?string, calibres: int, activo: bool}>
     */
    public static function opciones(int $empresaId): array
    {
        $productos = CrmProducto::query()->where('empresa_id', $empresaId)->get(['id', 'nombre', 'commodity', 'activo']);
        $activos = CrmProductoCalibre::query()->whereIn('producto_id', $productos->pluck('id'))->where('activo', true)
            ->selectRaw('producto_id, COUNT(*) as n')->groupBy('producto_id')->pluck('n', 'producto_id');

        return $productos
            ->map(fn (CrmProducto $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'commodity' => $p->commodity,
                'calibres' => (int) ($activos[$p->id] ?? 0),
                'activo' => (bool) $p->activo,
            ])
            ->sortBy([fn ($a, $b) => strcasecmp((string) $a['commodity'], (string) $b['commodity']), fn ($a, $b) => strcasecmp($a['nombre'], $b['nombre'])])
            ->values()
            ->all();
    }

    /**
     * Con varios candidatos: commodity igual al cultivo primero, luego el nombre más corto («Kent Mango»
     * antes que «Kent Organic Mango») y al final el de menor id.
     */
    private static function mejor(Collection $candidatos, string $cultivo): ?CrmProducto
    {
        return $candidatos
            ->sortBy([
                fn (CrmProducto $a, CrmProducto $b) => (self::clave((string) $b->commodity) === $cultivo) <=> (self::clave((string) $a->commodity) === $cultivo),
                fn (CrmProducto $a, CrmProducto $b) => mb_strlen(self::clave($a->nombre)) <=> mb_strlen(self::clave($b->nombre)),
                ['id', 'asc'],
            ])
            ->first();
    }

    /** Productos ligados a la variedad homónima de SF (cultivo igual o que empiece igual: «Elote» → «Elote Dulce»). */
    private static function porLigaSf(Collection $productos, string $nombre, string $cultivo): Collection
    {
        $ids = $productos->pluck('variedad_id')->filter()->unique();
        if ($ids->isEmpty() || $cultivo === '') {
            return collect();
        }
        $validas = DB::table('variedades as v')->join('cultivos as c', 'c.id', '=', 'v.cultivo_id')
            ->whereIn('v.id', $ids)->get(['v.id', 'v.nombre', 'c.nombre as cultivo'])
            ->filter(fn ($v) => self::clave($v->nombre) === $nombre
                && (self::clave($v->cultivo) === $cultivo || str_starts_with(self::clave($v->cultivo), $cultivo.' ')))
            ->pluck('id')->all();

        return $productos->filter(fn (CrmProducto $p) => in_array($p->variedad_id, $validas));
    }

    private static function clave(string $texto): string
    {
        return (string) Str::of($texto)->ascii()->lower()->squish();
    }
}
