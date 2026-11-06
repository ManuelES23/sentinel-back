<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saldo de los productores (spec 6.7): única fuente para tablero, abonos, ficha y PDF. Solo lectura.
 *
 * Por productor y por moneda (nunca se suman MXN y USD):
 *  - `liquidado`: Σ monto_final de liquidaciones aprobadas y pagadas.
 *  - `aplicado`: Σ de las aplicaciones de abonos a esas liquidaciones (las filas de un abono
 *    cancelado ya no existen, Ruling 8, así que no hay que filtrar por estado del abono).
 *  - `por_pagar` = liquidado − aplicado.
 *  - `anticipos_sin_aplicar`: Σ (monto − aplicado) de los abonos en estado `aplicado`.
 *  - `sin_liquidar`: valor de las recepciones que no están en ninguna liquidación vigente.
 *
 * Con `$temporadaId` solo cuentan los convenios de esa temporada y los anticipos se omiten (0):
 * un anticipo es del productor, no de una temporada (Ruling 10).
 *
 * El número de consultas no depende del de productores: tres agrupadas para el dinero, y las de
 * `sin_liquidar` (convenios con precios y recepciones agrupadas) leídas una sola vez.
 */
class SaldoProductor
{
    /**
     * @param  array<int, int>|null  $productorIds  null = todos los de la empresa con movimiento
     * @return Collection<int, array<string, array{moneda: string, liquidado: float, aplicado: float, por_pagar: float, anticipos_sin_aplicar: float, sin_liquidar: float}>>
     *         productor_id => [moneda => saldo]; un productor pedido sin movimiento regresa `[]`
     */
    public static function porProductores(Enterprise $empresa, ?array $productorIds = null, ?int $temporadaId = null): Collection
    {
        $productorIds = $productorIds === null ? null : array_values(array_unique(array_map('intval', $productorIds)));
        if ($productorIds === []) {
            return collect();
        }

        /** @var array<int, array<string, array>> $saldos */
        $saldos = [];
        $celda = function (int $productor, string $moneda) use (&$saldos): array {
            return $saldos[$productor][$moneda] ??= [
                'moneda' => $moneda, 'liquidado' => 0.0, 'aplicado' => 0.0, 'por_pagar' => 0.0,
                'anticipos_sin_aplicar' => 0.0, 'sin_liquidar' => 0.0,
            ];
        };

        // 1. Liquidado.
        foreach (self::liquidaciones($empresa, $productorIds, $temporadaId)
            ->groupBy('l.productor_id', 'l.moneda')
            ->selectRaw('l.productor_id, l.moneda, SUM(l.monto_final) as total')
            ->get() as $fila) {
            $c = $celda((int) $fila->productor_id, $fila->moneda);
            $c['liquidado'] = round((float) $fila->total, 2);
            $saldos[(int) $fila->productor_id][$fila->moneda] = $c;
        }

        // 2. Aplicado a esas liquidaciones.
        foreach (self::liquidaciones($empresa, $productorIds, $temporadaId)
            ->join('abs_abono_aplicaciones as ap', 'ap.liquidacion_id', '=', 'l.id')
            ->groupBy('l.productor_id', 'l.moneda')
            ->selectRaw('l.productor_id, l.moneda, SUM(ap.monto) as total')
            ->get() as $fila) {
            $c = $celda((int) $fila->productor_id, $fila->moneda);
            $c['aplicado'] = round((float) $fila->total, 2);
            $saldos[(int) $fila->productor_id][$fila->moneda] = $c;
        }

        // 3. Anticipos sin aplicar (abonos vigentes menos lo que ya se aplicó de cada uno).
        if ($temporadaId === null) {
            foreach (DB::table('abs_abonos as a')
                ->where('a.enterprise_id', $empresa->id)
                ->whereNull('a.deleted_at')
                ->where('a.status', 'aplicado')
                ->when($productorIds !== null, fn ($q) => $q->whereIn('a.productor_id', $productorIds))
                ->groupBy('a.productor_id', 'a.moneda')
                ->selectRaw('a.productor_id, a.moneda, SUM(a.monto - COALESCE((SELECT SUM(x.monto) FROM abs_abono_aplicaciones x WHERE x.abono_id = a.id), 0)) as total')
                ->get() as $fila) {
                $c = $celda((int) $fila->productor_id, $fila->moneda);
                $c['anticipos_sin_aplicar'] = round((float) $fila->total, 2);
                $saldos[(int) $fila->productor_id][$fila->moneda] = $c;
            }
        }

        // 4. Sin liquidar: valor de las recepciones libres, con los precios vigentes de cada convenio.
        $convenios = new EloquentCollection(
            Convenio::deEmpresa($empresa->id)
                ->where('status', '!=', 'cancelado')
                ->when($productorIds !== null, fn ($q) => $q->whereIn('productor_id', $productorIds))
                ->when($temporadaId !== null, fn ($q) => $q->where('temporada_id', $temporadaId))
                ->with('precios')
                ->get()
                ->all()
        );
        $libres = IndicadoresConvenio::lineas($convenios, soloSinLiquidar: true);
        foreach ($convenios as $convenio) {
            $valor = IndicadoresConvenio::valorDe($convenio, $libres->get($convenio->id, collect()));
            if ($valor == 0.0) {
                continue;
            }
            $c = $celda($convenio->productor_id, $convenio->moneda);
            $c['sin_liquidar'] = round($c['sin_liquidar'] + $valor, 2);
            $saldos[$convenio->productor_id][$convenio->moneda] = $c;
        }

        foreach ($saldos as $productor => $monedas) {
            foreach ($monedas as $moneda => $c) {
                $saldos[$productor][$moneda]['por_pagar'] = round($c['liquidado'] - $c['aplicado'], 2);
            }
            ksort($saldos[$productor]);
        }

        // Los pedidos sin movimiento regresan vacíos para que quien llama no tenga que adivinar.
        foreach ($productorIds ?? [] as $id) {
            $saldos[$id] ??= [];
        }
        ksort($saldos);

        return collect($saldos);
    }

    /** Liquidaciones que cuentan como deuda (aprobadas y pagadas), de la empresa y, si se pide, de la temporada. */
    private static function liquidaciones(Enterprise $empresa, ?array $productorIds, ?int $temporadaId)
    {
        return DB::table('abs_liquidaciones as l')
            ->where('l.enterprise_id', $empresa->id)
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', ['aprobada', 'pagada'])
            ->when($productorIds !== null, fn ($q) => $q->whereIn('l.productor_id', $productorIds))
            ->when($temporadaId !== null, fn ($q) => $q
                ->join('abs_convenios as c', 'c.id', '=', 'l.convenio_id')
                ->where('c.temporada_id', $temporadaId));
    }
}
