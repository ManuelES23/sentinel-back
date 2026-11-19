<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Saldo de los productores (spec 5.3): la suma de las cuentas de sus convenios no cancelados, por moneda
 * (nunca se suman MXN y USD). Única fuente para el tablero, la ficha del convenio, la lista de productores
 * y el Excel del tablero. Solo lectura, sobre `CuentaConvenio::totales` (misma regla y mismas cifras):
 * `valor_recibido`, `pagado` (sin saldo inicial), `cargos_iniciales` y `por_pagar`.
 *
 * Todas las lecturas hacen un número fijo de consultas: una para los convenios y las de `CuentaConvenio`,
 * sin importar cuántos productores o convenios haya.
 */
class SaldoProductor
{
    private const CAMPOS = ['valor_recibido', 'pagado', 'cargos_iniciales', 'por_pagar'];

    /**
     * @param  array<int, int>|null  $productorIds  null = todos los de la empresa con movimiento
     * @return Collection<int, array<string, array{moneda: string, valor_recibido: float, pagado: float, cargos_iniciales: float, por_pagar: float}>>
     *                                                                                                                                                productor_id => [moneda => saldo]; un productor pedido sin movimiento regresa `[]`
     */
    public static function porProductores(Enterprise $empresa, ?array $productorIds = null, ?int $temporadaId = null): Collection
    {
        $productorIds = $productorIds === null ? null : array_values(array_unique(array_map('intval', $productorIds)));
        if ($productorIds === []) {
            return collect();
        }

        $convenios = self::convenios($empresa, $temporadaId, $productorIds);

        /** @var array<int, array<string, array<string, int>>> $centavos */
        $centavos = [];
        foreach (self::conMovimiento($convenios) as [$convenio, $t]) {
            $actual = $centavos[$convenio->productor_id][$t['moneda']] ?? [];
            foreach (self::CAMPOS as $campo) {
                $actual[$campo] = ($actual[$campo] ?? 0) + self::centavos($t[$campo]);
            }
            $centavos[$convenio->productor_id][$t['moneda']] = $actual;
        }

        $saldos = [];
        foreach ($centavos as $productor => $monedas) {
            ksort($monedas);
            foreach ($monedas as $moneda => $c) {
                $saldos[$productor][$moneda] = ['moneda' => $moneda]
                    + array_map(fn (int $v) => round($v / 100, 2), $c);
            }
        }
        // Los pedidos sin movimiento regresan vacíos para que quien llama no tenga que adivinar.
        foreach ($productorIds ?? [] as $id) {
            $saldos[$id] ??= [];
        }
        ksort($saldos);

        return collect($saldos);
    }

    /**
     * Por pagar de los convenios de UNA temporada, por moneda y sumando a todos los productores: la cifra del
     * tablero. Misma regla que `porProductores` con `$temporadaId`; un test compara ambas.
     *
     * @return array<string, float> moneda => por pagar
     */
    public static function porPagarDeTemporada(Enterprise $empresa, int $temporadaId): array
    {
        $centavos = [];
        foreach (self::conMovimiento(self::convenios($empresa, $temporadaId)) as [, $t]) {
            $centavos[$t['moneda']] = ($centavos[$t['moneda']] ?? 0) + self::centavos($t['por_pagar']);
        }
        ksort($centavos);

        return array_map(fn (int $c) => round($c / 100, 2), $centavos);
    }

    /**
     * Saldo de UN convenio, en su moneda. Con un solo convenio da lo mismo que `porProductores`.
     *
     * @return array{moneda: string, valor_recibido: float, pagado: float, cargos_iniciales: float, por_pagar: float}
     */
    public static function deConvenio(Convenio $convenio): array
    {
        $t = CuentaConvenio::totales(new EloquentCollection([$convenio]))[$convenio->id];

        return ['moneda' => $t['moneda']] + array_intersect_key($t, array_flip(self::CAMPOS));
    }

    /**
     * Por pagar de VARIOS convenios de la empresa con un número fijo de consultas (para el Excel del tablero),
     * en el orden pedido y sin repetidos. Un convenio sin movimiento, o que no es de la empresa, da 0.
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

        $totales = CuentaConvenio::totales(Convenio::deEmpresa($empresa->id)->whereIn('id', $ids)->get());

        $porPagar = [];
        foreach ($ids as $id) {
            $porPagar[$id] = $totales[$id]['por_pagar'] ?? 0.0;
        }

        return $porPagar;
    }

    /**
     * Convenios no cancelados de la empresa; si se pide, solo de la temporada o de esos productores.
     *
     * @param  array<int, int>|null  $productorIds
     */
    private static function convenios(Enterprise $empresa, ?int $temporadaId, ?array $productorIds = null): EloquentCollection
    {
        return Convenio::deEmpresa($empresa->id)
            ->where('status', '!=', 'cancelado')
            ->when($temporadaId !== null, fn ($q) => $q->where('temporada_id', $temporadaId))
            ->when($productorIds !== null, fn ($q) => $q->whereIn('productor_id', $productorIds))
            ->orderBy('id')
            ->get();
    }

    /**
     * Pares [convenio, totales] de los convenios con movimiento: recepciones, pagos o cargos vigentes, o una
     * liquidación vigente. Uno sin nada de eso no aparece en el saldo.
     *
     * @return list<array{0: Convenio, 1: array}>
     */
    private static function conMovimiento(EloquentCollection $convenios): array
    {
        $totales = CuentaConvenio::totales($convenios);
        $pares = [];
        foreach ($convenios as $convenio) {
            $t = $totales[$convenio->id];
            if ($t['recepciones'] > 0 || $t['pagado'] != 0.0 || $t['cargos_iniciales'] != 0.0 || $t['estado'] === 'liquidada') {
                $pares[] = [$convenio, $t];
            }
        }

        return $pares;
    }

    private static function centavos(float $monto): int
    {
        return (int) round($monto * 100);
    }
}
