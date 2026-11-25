<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmOportunidad;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cascada de la meta en SBP (spec 2026-11-25 §8): lo vendido, lo rechazado, los
 * ajustes de precio y lo que queda en firme, en dinero y en cajas, sobre las
 * oportunidades ganadas con `fecha_cierre_real` en el rango. Es EXACTAMENTE el
 * mismo conjunto que usa `montoReal` (`DashboardResumenService::ganadasEntre`),
 * así que `firme` coincide con `montoReal`. Lo que se rechaza o se ajusta
 * después pega al mes de la venta, no al mes en que se captura.
 *
 * Consultas agregadas (cuatro, sin importar cuántas oportunidades haya); los
 * centavos se suman como enteros y la cascada se arma con
 * AjustesOportunidadService, la misma definición que el resumen de una
 * oportunidad, para que nunca discrepen.
 *
 * Una ganada SIN venta vigente (ganada antes de esta función, o a mano) cuenta
 * su `monto_esperado` como vendido, las cajas de su cotización aprobada más
 * reciente (0 si no tiene) y ningún rechazo. Una venta con cero renglones
 * vendidos pertenece a una oportunidad perdida y por eso nunca entra aquí.
 */
class VentaMetaService
{
    public function __construct(private readonly AjustesOportunidadService $ajustes) {}

    /**
     * @return array{vendido: float, rechazado: float, ajustesPrecio: float, firme: float, cajasVendidas: int, cajasRechazadas: int, cajasFirmes: int}|null
     *         null cuando la empresa no usa cotización por caja (SBP)
     */
    public function para(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): ?array
    {
        if (! $this->cotizaPorCaja($empresaId)) {
            return null;
        }

        $ganadas = $this->ganadasEntre($empresaId, $vendedorId, $inicio, $fin);
        $montos = (clone $ganadas)->pluck('monto_esperado', 'id');
        if ($montos->isEmpty()) {
            return $this->camel($this->ajustes->cifras(0, 0, 0, 0, 0));
        }
        $ids = fn () => (clone $ganadas)->select('crm_oportunidades.id');

        // Lo vendido de la venta vigente: bruto (Σ cajas × precio en centavos) y cajas por oportunidad.
        $ventas = DB::table('crm_oportunidad_ventas as v')
            ->join('crm_oportunidad_venta_renglones as r', 'r.venta_id', '=', 'v.id')
            ->where('v.vigente', true)
            ->where('r.vendido', true)
            ->whereIn('v.oportunidad_id', $ids())
            ->groupBy('v.oportunidad_id', 'v.descuento_global_pct')
            ->selectRaw('v.oportunidad_id, v.descuento_global_pct, SUM(r.cantidad_vendida * ROUND(r.precio_vendido * 100)) as bruto, SUM(r.cantidad_vendida) as cajas')
            ->get();

        // Ajustes sin anular de los renglones de la venta vigente, por oportunidad y tipo.
        $ajustes = DB::table('crm_oportunidad_ajustes as a')
            ->join('crm_oportunidad_venta_renglones as r', 'r.id', '=', 'a.venta_renglon_id')
            ->join('crm_oportunidad_ventas as v', 'v.id', '=', 'r.venta_id')
            ->where('v.vigente', true)
            ->whereNull('a.anulado_at')
            ->whereIn('a.oportunidad_id', $ids())
            ->groupBy('a.oportunidad_id', 'a.tipo')
            ->selectRaw('a.oportunidad_id, a.tipo, SUM(ROUND(a.importe * 100)) as importe, COALESCE(SUM(a.cajas), 0) as cajas')
            ->get();

        $vendido = $rechazado = $precio = $cajasVendidas = $cajasRechazadas = 0;
        $conVenta = [];

        foreach ($ventas as $fila) {
            $conVenta[(int) $fila->oportunidad_id] = true;
            $vendido += $this->ajustes->neto((int) round((float) $fila->bruto), $this->ajustes->factorDe($fila->descuento_global_pct));
            $cajasVendidas += (int) $fila->cajas;
        }
        foreach ($ajustes as $fila) {
            if (! isset($conVenta[(int) $fila->oportunidad_id])) {
                continue;
            }
            if ($fila->tipo === 'rechazo') {
                $rechazado += (int) round((float) $fila->importe);
                $cajasRechazadas += (int) $fila->cajas;
            } else {
                $precio += (int) round((float) $fila->importe);
            }
        }

        // Ganadas sin venta: su monto y las cajas de la cotización aprobada más reciente.
        $sinVenta = $montos->reject(fn ($monto, $id) => isset($conVenta[(int) $id]));
        if ($sinVenta->isNotEmpty()) {
            $vendido += (int) $sinVenta->sum(fn ($monto) => (int) round((float) $monto * 100));
            $cajasVendidas += $this->cajasDeCotizacionAprobada($ids(), $sinVenta->keys()->all());
        }

        return $this->camel($this->ajustes->cifras($vendido, $rechazado, $precio, $cajasVendidas, $cajasRechazadas));
    }

    /**
     * Lo mismo que CrmConfiguracionComercial::cotizacionPorCaja, pero solo lee:
     * `paraEmpresa` crea la fila de configuración la primera vez y este cálculo
     * corre en cada GET del dashboard y de Mi día. Sin fila, apagada.
     */
    private function cotizaPorCaja(int $empresaId): bool
    {
        return (bool) CrmConfiguracionComercial::where('empresa_id', $empresaId)->value('cotizacion_por_caja');
    }

    /**
     * Las cifras de la oportunidad vienen en snake_case; el dashboard las
     * entrega en camelCase como el resto de `cumplimiento-metas`.
     *
     * @param  array<string, int|float>  $cifras
     * @return array{vendido: float, rechazado: float, ajustesPrecio: float, firme: float, cajasVendidas: int, cajasRechazadas: int, cajasFirmes: int}
     */
    private function camel(array $cifras): array
    {
        return [
            'vendido' => $cifras['vendido'],
            'rechazado' => $cifras['rechazado'],
            'ajustesPrecio' => $cifras['ajustes_precio'],
            'firme' => $cifras['firme'],
            'cajasVendidas' => $cifras['cajas_vendidas'],
            'cajasRechazadas' => $cifras['cajas_rechazadas'],
            'cajasFirmes' => $cifras['cajas_firmes'],
        ];
    }

    /** Mismo filtro que DashboardResumenService::ganadasEntre. */
    private function ganadasEntre(int $empresaId, ?int $vendedorId, CarbonInterface $inicio, CarbonInterface $fin): Builder
    {
        return CrmOportunidad::where('empresa_id', $empresaId)
            ->where('etapa', 'cerrado_ganado')
            ->whereBetween('fecha_cierre_real', [$inicio, $fin])
            ->when($vendedorId !== null, fn ($q) => $q->where('vendedor_id', $vendedorId));
    }

    /**
     * Σ cajas de la cotización aprobada más reciente de cada oportunidad en
     * `$sinVenta` (0 si no tiene), con una sola consulta.
     *
     * @param  mixed  $ids  subconsulta con las ganadas del rango
     * @param  list<int|string>  $sinVenta
     */
    private function cajasDeCotizacionAprobada(mixed $ids, array $sinVenta): int
    {
        $sinVenta = array_flip(array_map('intval', $sinVenta));

        $cotizaciones = DB::table('crm_cotizaciones as c')
            ->join('crm_oportunidad_productos as l', 'l.cotizacion_id', '=', 'c.id')
            ->where('c.estado', 'aprobado')
            ->whereNull('c.deleted_at')
            ->whereIn('c.oportunidad_id', $ids)
            ->groupBy('c.id', 'c.oportunidad_id')
            ->selectRaw('c.id, c.oportunidad_id, SUM(l.cantidad) as cajas')
            ->get();

        $recientes = [];
        foreach ($cotizaciones as $fila) {
            $op = (int) $fila->oportunidad_id;
            if (isset($sinVenta[$op]) && (! isset($recientes[$op]) || (int) $fila->id > $recientes[$op]['id'])) {
                $recientes[$op] = ['id' => (int) $fila->id, 'cajas' => (int) round((float) $fila->cajas)];
            }
        }

        return array_sum(array_column($recientes, 'cajas'));
    }
}
