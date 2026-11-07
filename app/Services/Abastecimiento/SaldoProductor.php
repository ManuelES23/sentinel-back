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
    /** Estados de liquidación que cuentan como deuda con el productor. */
    private const ESTADOS_DEUDA = ['aprobada', 'pagada'];

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
        foreach (self::liquidaciones($empresa->id, $productorIds, $temporadaId)
            ->groupBy('l.productor_id', 'l.moneda')
            ->selectRaw('l.productor_id, l.moneda, SUM(l.monto_final) as total')
            ->get() as $fila) {
            $c = $celda((int) $fila->productor_id, $fila->moneda);
            $c['liquidado'] = round((float) $fila->total, 2);
            $saldos[(int) $fila->productor_id][$fila->moneda] = $c;
        }

        // 2. Aplicado a esas liquidaciones.
        foreach (self::liquidaciones($empresa->id, $productorIds, $temporadaId)
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

    /**
     * Por pagar (liquidado − aplicado) de los convenios de UNA temporada, por moneda y sumando a todos los
     * productores: la cifra del tablero (Ruling 10). Mismas definiciones y mismas dos consultas agrupadas
     * que `porProductores` con `$temporadaId` (sin el costoso `sin_liquidar`); un test compara ambas.
     *
     * @return array<string, float> moneda => por pagar
     */
    public static function porPagarDeTemporada(Enterprise $empresa, int $temporadaId): array
    {
        $liquidado = self::liquidaciones($empresa->id, null, $temporadaId)
            ->groupBy('l.moneda')->selectRaw('l.moneda, SUM(l.monto_final) as total')->pluck('total', 'moneda');
        $aplicado = self::liquidaciones($empresa->id, null, $temporadaId)
            ->join('abs_abono_aplicaciones as ap', 'ap.liquidacion_id', '=', 'l.id')
            ->groupBy('l.moneda')->selectRaw('l.moneda, SUM(ap.monto) as total')->pluck('total', 'moneda');

        $porPagar = [];
        foreach ($liquidado as $moneda => $total) {
            $porPagar[$moneda] = round(round((float) $total, 2) - round((float) ($aplicado[$moneda] ?? 0), 2), 2);
        }
        ksort($porPagar);

        return $porPagar;
    }

    /**
     * Liquidado, aplicado y por pagar de UN convenio (sus liquidaciones aprobadas y pagadas), en la moneda del
     * convenio. Mismas definiciones que `porProductores`: con un solo convenio da el mismo `por_pagar`.
     *
     * @return array{moneda: string, liquidado: float, aplicado: float, por_pagar: float}
     */
    public static function deConvenio(Convenio $convenio): array
    {
        $base = fn () => self::liquidaciones($convenio->enterprise_id, null, null, $convenio->id);

        $liquidado = round((float) $base()->sum('l.monto_final'), 2);
        $aplicado = round((float) $base()->join('abs_abono_aplicaciones as ap', 'ap.liquidacion_id', '=', 'l.id')->sum('ap.monto'), 2);

        return [
            'moneda' => $convenio->moneda,
            'liquidado' => $liquidado,
            'aplicado' => $aplicado,
            'por_pagar' => round($liquidado - $aplicado, 2),
        ];
    }

    /**
     * Por pagar (liquidado − aplicado) de VARIOS convenios en dos consultas agrupadas, sin importar cuántos sean
     * (para el Excel del tablero). Mismas definiciones que `deConvenio`: un convenio sin liquidaciones da 0.
     *
     * @param  array<int, int>  $convenioIds
     * @return array<int, float> convenio_id => por pagar
     */
    public static function porPagarDeConvenios(Enterprise $empresa, array $convenioIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $convenioIds)));
        if ($ids === []) {
            return [];
        }

        $liquidado = self::liquidaciones($empresa->id, null, null)->whereIn('l.convenio_id', $ids)
            ->groupBy('l.convenio_id')->selectRaw('l.convenio_id, SUM(l.monto_final) as total')->pluck('total', 'convenio_id');
        $aplicado = self::liquidaciones($empresa->id, null, null)->whereIn('l.convenio_id', $ids)
            ->join('abs_abono_aplicaciones as ap', 'ap.liquidacion_id', '=', 'l.id')
            ->groupBy('l.convenio_id')->selectRaw('l.convenio_id, SUM(ap.monto) as total')->pluck('total', 'convenio_id');

        $porPagar = [];
        foreach ($ids as $id) {
            $porPagar[$id] = round(round((float) ($liquidado[$id] ?? 0), 2) - round((float) ($aplicado[$id] ?? 0), 2), 2);
        }

        return $porPagar;
    }

    /** Liquidaciones que cuentan como deuda (aprobadas y pagadas), de la empresa y, si se pide, de la temporada o de un convenio. */
    private static function liquidaciones(int $empresaId, ?array $productorIds, ?int $temporadaId, ?int $convenioId = null)
    {
        return DB::table('abs_liquidaciones as l')
            ->where('l.enterprise_id', $empresaId)
            ->when($convenioId !== null, fn ($q) => $q->where('l.convenio_id', $convenioId))
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', self::ESTADOS_DEUDA)
            ->when($productorIds !== null, fn ($q) => $q->whereIn('l.productor_id', $productorIds))
            ->when($temporadaId !== null, fn ($q) => $q
                ->join('abs_convenios as c', 'c.id', '=', 'l.convenio_id')
                ->where('c.temporada_id', $temporadaId));
    }
}
