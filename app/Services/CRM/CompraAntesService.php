<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Productos que el cliente de una oportunidad ya compró (renglones de
 * cotizaciones aprobadas en los últimos 12 meses), para ofrecerlos primero
 * al cotizar.
 */
class CompraAntesService
{
    public const MESES = 12;

    /** @return Collection<int, CrmProducto> */
    public function para(CrmOportunidad $oportunidad, int $limite = 8): Collection
    {
        if (! $oportunidad->cliente_id) {
            return collect();
        }

        $filas = DB::table('crm_oportunidad_productos as r')
            ->join('crm_cotizaciones as c', 'c.id', '=', 'r.cotizacion_id')
            ->join('crm_oportunidades as o', 'o.id', '=', 'c.oportunidad_id')
            ->join('crm_productos as p', 'p.id', '=', 'r.producto_id')
            ->where('o.cliente_id', $oportunidad->cliente_id)
            ->where('o.empresa_id', $oportunidad->empresa_id)
            ->where('c.empresa_id', $oportunidad->empresa_id)
            ->where('p.empresa_id', $oportunidad->empresa_id)
            ->where('c.estado', 'aprobado')
            ->whereDate('c.fecha_emision', '>=', now()->subMonths(self::MESES)->toDateString())
            ->where('p.activo', true)
            ->groupBy('r.producto_id')
            ->orderByRaw('COUNT(DISTINCT c.id) DESC')
            ->orderByRaw('MAX(c.fecha_emision) DESC')
            ->orderBy('r.producto_id')
            ->limit($limite)
            ->pluck('r.producto_id');

        if ($filas->isEmpty()) {
            return collect();
        }

        $productos = CrmProducto::query()
            ->select('id', 'nombre', 'clave', 'unidad_medida', 'marca_id', 'linea_id')
            ->with(['marca:id,nombre', 'linea:id,nombre'])
            ->whereIn('id', $filas)
            ->get()
            ->keyBy('id');

        return $filas->map(fn ($id) => $productos->get($id))->filter()->values();
    }
}
