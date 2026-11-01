<?php

namespace App\Services\CRM;

use App\Models\Calibre;
use App\Models\CRM\CrmPresentacion;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProductoCalibre;

/**
 * Opciones que ofrece el formulario de cotización por caja: por commodity,
 * sus presentaciones y las variedades (productos activos) con sus calibres.
 */
class OpcionesCotizacionService
{
    private const OTROS = 'Otros';

    /** @return array{productos: array<int, array<string, mixed>>} */
    public function paraEmpresa(int $empresaId): array
    {
        $productos = CrmProducto::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'commodity', 'variedad_id', 'precio']);

        $calibresAgricolas = $this->calibresAgricolas($productos->pluck('variedad_id')->filter()->unique());
        $calibresPropios = $this->calibresPropios($productos->pluck('id'));

        $presentaciones = CrmPresentacion::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('orden')->orderBy('nombre')
            ->get(['commodity', 'nombre'])
            ->groupBy(fn (CrmPresentacion $p) => self::clave($p->commodity));

        // Misma regla que MySQL (sin distinguir mayúsculas ni espacios); se muestra la escritura del producto.
        $porCommodity = $productos->groupBy(fn (CrmProducto $p) => self::clave($p->commodity));

        $grupos = $porCommodity->map(fn ($variedades, string $clave) => [
            'commodity'      => $clave === self::clave(self::OTROS) ? self::OTROS : trim((string) $variedades->first()->commodity),
            'presentaciones' => ($presentaciones[$clave] ?? collect())->pluck('nombre')->values()->all(),
            'variedades'     => $variedades->map(fn (CrmProducto $p) => [
                'id'       => $p->id,
                'nombre'   => $p->nombre,
                'precio'   => (float) $p->precio,
                'calibres' => $this->unir(
                    $p->variedad_id ? ($calibresAgricolas[$p->variedad_id] ?? []) : [],
                    $calibresPropios[$p->id] ?? [],
                ),
            ])->values()->all(),
        ]);

        // Alfabético, con «Otros» siempre al final.
        $ordenados = $grupos->sort(function (array $a, array $b) {
            if ($a['commodity'] === self::OTROS || $b['commodity'] === self::OTROS) {
                return ($a['commodity'] === self::OTROS) <=> ($b['commodity'] === self::OTROS);
            }

            return strcasecmp($a['commodity'], $b['commodity']);
        })->values()->all();

        return ['productos' => $ordenados];
    }

    private static function clave(?string $commodity): string
    {
        return mb_strtolower(trim((string) $commodity)) ?: mb_strtolower(self::OTROS);
    }

    /** @return array<int, array<int, string>> variedad_id => nombres de calibres activos */
    private function calibresAgricolas($variedadIds): array
    {
        if ($variedadIds->isEmpty()) {
            return [];
        }

        return Calibre::query()
            ->whereIn('variedad_id', $variedadIds)
            ->where('is_active', true)
            ->orderBy('order')->orderBy('nombre')
            ->get(['variedad_id', 'nombre'])
            ->groupBy('variedad_id')
            ->map(fn ($c) => $c->pluck('nombre')->all())
            ->all();
    }

    /** @return array<int, array<int, string>> producto_id => nombres de calibres propios activos */
    private function calibresPropios($productoIds): array
    {
        if ($productoIds->isEmpty()) {
            return [];
        }

        return CrmProductoCalibre::query()
            ->whereIn('producto_id', $productoIds)
            ->where('activo', true)
            ->orderBy('orden')->orderBy('nombre')
            ->get(['producto_id', 'nombre'])
            ->groupBy('producto_id')
            ->map(fn ($c) => $c->pluck('nombre')->all())
            ->all();
    }

    /** Une las listas sin repetir (sin distinguir mayúsculas); gana el primero. */
    private function unir(array ...$listas): array
    {
        $vistos = [];
        $resultado = [];
        foreach ($listas as $lista) {
            foreach ($lista as $nombre) {
                $clave = mb_strtolower(trim($nombre));
                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;
                $resultado[] = $nombre;
            }
        }

        return $resultado;
    }
}
