<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Support\CRM\RangoDashboard;
use Illuminate\Support\Facades\DB;

/**
 * «Ventas por línea» del Dashboard (catálogo por líneas, CanAgro; spec 2026-11-10):
 * oportunidades ganadas en el periodo (fecha_cierre_real), cada una con su
 * cotización aprobada más reciente, agrupadas por la línea y sublínea ACTUALES
 * del producto (reclasificar un producto cambia el reporte, decisión 10).
 */
class VentasPorLineaService
{
    public const SIN_LINEA = 'Sin línea';

    public const SIN_SUBLINEA = 'Sin sublínea';

    private const LOTE = 500;

    /**
     * @return array{aplica: bool, total?: float, lineas?: array<int, array{id: ?int, nombre: string, importe: float, sublineas: array<int, array{id: ?int, nombre: string, importe: float}>}>, ganadasSinCotizacion?: array{cantidad: int, monto: float}}
     */
    public function resumen(int $empresaId, ?int $vendedorId, RangoDashboard $rango): array
    {
        if (! CrmConfiguracionComercial::catalogoPorLineas($empresaId)) {
            return ['aplica' => false];
        }

        // Eloquent respeta SoftDeletes: las oportunidades eliminadas no cuentan.
        $ganadas = CrmOportunidad::where('empresa_id', $empresaId)
            ->where('etapa', 'cerrado_ganado')
            ->whereBetween('fecha_cierre_real', [$rango->inicio, $rango->fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->get(['id', 'monto_esperado']);

        // Cotización aprobada más reciente por oportunidad: fecha_emision desc, luego id desc.
        $cotizacionPorOportunidad = [];
        foreach ($ganadas->pluck('id')->chunk(self::LOTE) as $lote) {
            $cotizaciones = CrmCotizacion::where('empresa_id', $empresaId)
                ->where('estado', 'aprobado')
                ->whereIn('oportunidad_id', $lote->all())
                ->orderByDesc('fecha_emision')
                ->orderByDesc('id')
                ->get(['id', 'oportunidad_id']);
            foreach ($cotizaciones as $c) {
                $cotizacionPorOportunidad[$c->oportunidad_id] ??= $c->id;
            }
        }

        $sinCotizacion = ['cantidad' => 0, 'monto' => 0.0];
        foreach ($ganadas as $o) {
            if (! isset($cotizacionPorOportunidad[$o->id])) {
                $sinCotizacion['cantidad']++;
                $sinCotizacion['monto'] += (float) $o->monto_esperado;
            }
        }
        $sinCotizacion['monto'] = round($sinCotizacion['monto'], 2);

        $lineas = [];
        $total = 0.0;
        foreach (array_chunk(array_values($cotizacionPorOportunidad), self::LOTE) as $lote) {
            $renglones = DB::table('crm_oportunidad_productos as r')
                ->join('crm_productos as p', 'p.id', '=', 'r.producto_id')
                ->leftJoin('crm_lineas as l', 'l.id', '=', 'p.linea_id')
                ->leftJoin('crm_sublineas as s', 's.id', '=', 'p.sublinea_id')
                ->whereIn('r.cotizacion_id', $lote)
                ->get(['r.cantidad', 'r.precio_unitario', 'l.id as linea_id', 'l.nombre as linea', 's.id as sublinea_id', 's.nombre as sublinea']);

            foreach ($renglones as $r) {
                $importe = (float) $r->cantidad * (float) $r->precio_unitario;
                $total += $importe;
                $lid = $r->linea_id !== null ? (int) $r->linea_id : null;
                $sid = $r->sublinea_id !== null ? (int) $r->sublinea_id : null;
                $lineas[$lid ?? 0] ??= ['id' => $lid, 'nombre' => $r->linea ?? self::SIN_LINEA, 'importe' => 0.0, 'sublineas' => []];
                $lineas[$lid ?? 0]['importe'] += $importe;
                $lineas[$lid ?? 0]['sublineas'][$sid ?? 0] ??= ['id' => $sid, 'nombre' => $r->sublinea ?? self::SIN_SUBLINEA, 'importe' => 0.0];
                $lineas[$lid ?? 0]['sublineas'][$sid ?? 0]['importe'] += $importe;
            }
        }

        $porImporte = fn (array $a, array $b) => [$b['importe'], $a['nombre']] <=> [$a['importe'], $b['nombre']];
        $lineas = array_values($lineas);
        foreach ($lineas as &$linea) {
            $linea['importe'] = round($linea['importe'], 2);
            $linea['sublineas'] = array_values($linea['sublineas']);
            foreach ($linea['sublineas'] as &$sub) {
                $sub['importe'] = round($sub['importe'], 2);
            }
            unset($sub);
            usort($linea['sublineas'], $porImporte);
        }
        unset($linea);
        usort($lineas, $porImporte);

        return [
            'aplica' => true,
            'total' => round($total, 2),
            'lineas' => $lineas,
            'ganadasSinCotizacion' => $sinCotizacion,
        ];
    }
}
