<?php

namespace App\Services\ActivosFijos;

use App\Models\ActivityLog;
use App\Models\DepreciationClose;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Depreciación mensual de una empresa: vista previa del mes abierto, cierre que
 * congela las cifras y reapertura del último cierre. Cada cierre se calcula por
 * fórmula, así que no depende de que existan los cierres de meses anteriores.
 * `period` se maneja siempre como cadena `Y-m-d` del primer día del mes.
 */
class CierreDepreciacion
{
    public function __construct(
        private ResolutorDepreciacion $resolutor,
        private CalculadoraDepreciacion $calculadora,
    ) {
    }

    /**
     * Cálculo vivo del mes. Entran los activos de la empresa que no están en baja ni borrados.
     * «Por iniciar» no genera línea ni cuenta como excluido; «sin depreciar» va a `excluded`.
     *
     * @return array{lines: list<array<string, mixed>>, excluded: list<array<string, mixed>>}
     */
    public function calcular(Enterprise $empresa, CarbonImmutable $mes): array
    {
        $mes = $mes->startOfMonth();
        $lines = [];
        $excluded = [];

        $activos = FixedAsset::query()
            ->where('enterprise_id', $empresa->id)
            ->where('status', '!=', 'baja')
            ->with(['category:id,name,depreciation_rate,salvage_percent', 'subcategory:id,name,depreciation_rate,salvage_percent'])
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        foreach ($activos as $activo) {
            ['datos' => $datos, 'motivo' => $motivo] = $this->resolutor->resolver($activo);

            if (! $datos) {
                $excluded[] = [
                    'fixed_asset_id' => $activo->id,
                    'code' => $activo->code,
                    'name' => $activo->name,
                    'reason' => $motivo,
                ];
                continue;
            }

            $resultado = $this->calculadora->calcular($datos, $mes);
            if ($resultado->estado === ResultadoDepreciacion::POR_INICIAR) {
                continue;
            }

            $lines[] = [
                'fixed_asset_id' => $activo->id,
                'code' => $activo->code,
                'name' => $activo->name,
                'category_name' => $activo->category?->name,
                'subcategory_name' => $activo->subcategory?->name,
                'purchase_date' => $activo->purchase_date?->toDateString(),
                'purchase_value' => ResultadoDepreciacion::decimal($datos->valorCentavos),
                'rate' => number_format($datos->tasaAnual, 2, '.', ''),
                'salvage_percent' => number_format($datos->residualPct, 2, '.', ''),
                'start_month' => $datos->inicio->toDateString(),
                'month_amount' => $resultado->cuota(),
                'accumulated' => $resultado->acumulado(),
                'book_value' => $resultado->valorEnLibros(),
            ];
        }

        return ['lines' => $lines, 'excluded' => $excluded];
    }

    /** Mes cerrado: la foto guardada. Mes abierto: el cálculo vivo. */
    public function estadoDelMes(Enterprise $empresa, CarbonImmutable $mes): array
    {
        $mes = $mes->startOfMonth();
        $cierre = DepreciationClose::where('enterprise_id', $empresa->id)
            ->where('period', $mes->toDateString())
            ->first();

        if ($cierre) {
            $lines = $cierre->lines()->orderBy('code')->orderBy('id')->get()->map(fn ($l) => [
                'fixed_asset_id' => $l->fixed_asset_id,
                'code' => $l->code,
                'name' => $l->name,
                'category_name' => $l->category_name,
                'subcategory_name' => $l->subcategory_name,
                'purchase_date' => $l->purchase_date,
                'purchase_value' => $l->purchase_value,
                'rate' => $l->rate,
                'salvage_percent' => $l->salvage_percent,
                'start_month' => $l->start_month,
                'month_amount' => $l->month_amount,
                'accumulated' => $l->accumulated,
                'book_value' => $l->book_value,
            ])->all();
            $excluded = [];
            $excludedCount = $cierre->excluded_count;
        } else {
            ['lines' => $lines, 'excluded' => $excluded] = $this->calcular($empresa, $mes);
            $excludedCount = count($excluded);
        }

        return [
            'closed' => $cierre !== null,
            'month' => $mes->format('Y-m'),
            'close' => $cierre ? $this->aArreglo($cierre->loadMissing('closedBy:id,name')) : null,
            'lines' => $lines,
            'excluded' => $excluded,
            'excluded_count' => $excludedCount,
            'totals' => $this->totales($lines),
            'totals_by_category' => $this->totalesPorTipo($lines),
        ];
    }

    public function cerrar(Enterprise $empresa, CarbonImmutable $mes, User $usuario): DepreciationClose
    {
        $mes = $mes->startOfMonth();

        if ($mes->greaterThan(CarbonImmutable::instance(now())->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'No se puede cerrar un mes futuro.']);
        }

        // Dos cierres simultáneos del mismo mes toman gap lock en el `lockForUpdate()` y MySQL
        // lanza deadlock (1213) en el INSERT; con reintentos el segundo ya ve el cierre
        // ganador y responde 422 en vez de 500.
        return DB::transaction(function () use ($empresa, $mes, $usuario) {
            $yaCerrado = DepreciationClose::where('enterprise_id', $empresa->id)
                ->where('period', $mes->toDateString())
                ->lockForUpdate()
                ->exists();
            if ($yaCerrado) {
                throw ValidationException::withMessages(['month' => 'Ese mes ya está cerrado.']);
            }

            ['lines' => $lines, 'excluded' => $excluded] = $this->calcular($empresa, $mes);
            $totales = $this->totales($lines);

            try {
                $cierre = DepreciationClose::create([
                    'enterprise_id' => $empresa->id,
                    'period' => $mes->toDateString(),
                    'assets_count' => $totales['assets_count'],
                    'excluded_count' => count($excluded),
                    'month_total' => $totales['month_total'],
                    'accumulated_total' => $totales['accumulated_total'],
                    'book_total' => $totales['book_total'],
                    'closed_by' => $usuario->id,
                    'closed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Otro cierre del mismo mes ganó la carrera.
                throw ValidationException::withMessages(['month' => 'Ese mes ya está cerrado.']);
            }

            foreach (array_chunk($lines, 200) as $bloque) {
                $cierre->lines()->createMany($bloque);
            }

            return $cierre;
        }, 3);
    }

    /** Solo el último mes cerrado de la empresa. El motivo y el autor quedan en el log de actividad. */
    public function reabrir(DepreciationClose $cierre, string $motivo, User $usuario): void
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo de la reapertura.']);
        }

        DB::transaction(function () use ($cierre, $motivo, $usuario) {
            $ultimo = DepreciationClose::where('enterprise_id', $cierre->enterprise_id)
                ->orderByDesc('period')
                ->lockForUpdate()
                ->first();

            if (! $ultimo || $ultimo->id !== $cierre->id) {
                throw ValidationException::withMessages(['cierre' => 'Solo se puede reabrir el último mes cerrado.']);
            }

            ActivityLog::log(
                action: 'reopen',
                model: 'DepreciationClose',
                modelId: $cierre->id,
                oldValues: [
                    'enterprise_id' => $cierre->enterprise_id,
                    'period' => $cierre->period,
                    'assets_count' => $cierre->assets_count,
                    'excluded_count' => $cierre->excluded_count,
                    'month_total' => $cierre->month_total,
                    'accumulated_total' => $cierre->accumulated_total,
                    'book_total' => $cierre->book_total,
                ],
                newValues: ['reason' => $motivo, 'reopened_by' => $usuario->id],
            );

            $cierre->lines()->delete();
            $cierre->delete();
        });
    }

    /** @return array<string, mixed> */
    public function aArreglo(DepreciationClose $cierre): array
    {
        return [
            'id' => $cierre->id,
            'period' => substr((string) $cierre->period, 0, 7),
            'assets_count' => $cierre->assets_count,
            'excluded_count' => $cierre->excluded_count,
            'month_total' => $cierre->month_total,
            'accumulated_total' => $cierre->accumulated_total,
            'book_total' => $cierre->book_total,
            'closed_by' => $cierre->closedBy?->name,
            'closed_at' => $cierre->closed_at?->toIso8601String(),
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function totales(array $lines): array
    {
        return [
            'assets_count' => count($lines),
            'month_total' => $this->sumar($lines, 'month_amount'),
            'accumulated_total' => $this->sumar($lines, 'accumulated'),
            'book_total' => $this->sumar($lines, 'book_value'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function totalesPorTipo(array $lines): array
    {
        $grupos = [];
        foreach ($lines as $linea) {
            $grupos[$linea['category_name'] ?? 'Sin tipo'][] = $linea;
        }
        ksort($grupos);

        $resultado = [];
        foreach ($grupos as $tipo => $items) {
            $resultado[] = [
                'category_name' => $tipo,
                'assets_count' => count($items),
                'month_amount' => $this->sumar($items, 'month_amount'),
                'accumulated' => $this->sumar($items, 'accumulated'),
                'book_value' => $this->sumar($items, 'book_value'),
            ];
        }

        return $resultado;
    }

    /** Suma exacta en centavos de una columna decimal. */
    private function sumar(array $lines, string $campo): string
    {
        $centavos = 0;
        foreach ($lines as $linea) {
            $centavos += (int) round(((float) $linea[$campo]) * 100);
        }

        return ResultadoDepreciacion::decimal($centavos);
    }
}
