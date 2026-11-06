<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Enterprise;
use Illuminate\Support\Facades\DB;

/**
 * Estado de cuenta de un productor en una moneda (spec 5.5): indicadores y línea de tiempo con
 * liquidaciones (cargos), abonos y anticipos, con saldo corriente. Solo lectura.
 *
 * Los indicadores salen de `SaldoProductor` (única fuente de saldos): no se recalculan aquí.
 *
 * «Abonado» = Σ de las aplicaciones de abonos a liquidaciones (`SaldoProductor::aplicado`). NO incluye los
 * anticipos sin aplicar: un abono de 700 con 400 aplicados suma 400 a «Abonado» y 300 a «Anticipos sin
 * aplicar». Por eso `por_pagar` = liquidado − abonado, y el dinero ya entregado al productor es
 * abonado + anticipos_sin_aplicar.
 *
 * Saldo corriente de la línea de tiempo = Σ cargos − Σ abonos vigentes (el monto COMPLETO de cada abono,
 * aplicado o no). Un abono cancelado se muestra pero no mueve el saldo. El último saldo es por tanto
 * `por_pagar − anticipos_sin_aplicar` (negativo = saldo a favor del productor).
 * Orden: fecha; a igual fecha los cargos antes que los abonos; luego id.
 */
class EstadoCuentaProductor
{
    public const MONEDAS = ['MXN', 'USD'];

    /**
     * @return array{productor: array, moneda: string, monedas_disponibles: array<int, string>, indicadores: array, saldo_final: float, movimientos: array<int, array>, empresa: array}
     */
    public static function armar(Enterprise $empresa, Productor $productor, ?string $moneda = null): array
    {
        $saldos = SaldoProductor::porProductores($empresa, [$productor->id])->get($productor->id, []);

        $disponibles = collect(array_keys($saldos))
            ->merge(Liquidacion::deEmpresa($empresa->id)->where('productor_id', $productor->id)
                ->whereIn('status', ['aprobada', 'pagada'])->distinct()->pluck('moneda'))
            ->merge(Abono::deEmpresa($empresa->id)->where('productor_id', $productor->id)->distinct()->pluck('moneda'))
            ->unique()->sort()->values()->all();

        $moneda ??= $disponibles[0] ?? 'MXN';
        $saldo = $saldos[$moneda] ?? ['liquidado' => 0.0, 'aplicado' => 0.0, 'por_pagar' => 0.0, 'sin_liquidar' => 0.0, 'anticipos_sin_aplicar' => 0.0];

        $movimientos = self::movimientos($empresa, $productor, $moneda);

        return [
            'productor' => [
                'id' => $productor->id,
                'nombre' => $productor->nombre,
                'tipo' => $productor->tipo,
                'rfc' => $productor->rfc,
                'contacto' => $productor->contacto,
                'telefono' => $productor->telefono,
                'email' => $productor->email,
                'ubicacion' => $productor->ubicacion,
            ],
            'moneda' => $moneda,
            'monedas_disponibles' => $disponibles,
            'indicadores' => [
                'liquidado' => (float) $saldo['liquidado'],
                'abonado' => (float) $saldo['aplicado'],
                'por_pagar' => (float) $saldo['por_pagar'],
                'sin_liquidar' => (float) $saldo['sin_liquidar'],
                'anticipos_sin_aplicar' => (float) $saldo['anticipos_sin_aplicar'],
            ],
            'saldo_final' => $movimientos === [] ? 0.0 : (float) end($movimientos)['saldo'],
            'movimientos' => $movimientos,
            'empresa' => [
                'id' => $empresa->id,
                'nombre' => $empresa->name,
                'razon_social' => $empresa->razon_social,
                'rfc' => $empresa->rfc,
                'direccion' => $empresa->direccion,
                'ciudad' => $empresa->ciudad,
                'pais' => $empresa->pais,
                'telefono' => $empresa->telefono,
            ],
        ];
    }

    private static function movimientos(Enterprise $empresa, Productor $productor, string $moneda): array
    {
        $liquidaciones = Liquidacion::deEmpresa($empresa->id)
            ->where('productor_id', $productor->id)->where('moneda', $moneda)
            ->whereIn('status', ['aprobada', 'pagada'])
            ->with('convenio:id,folio')
            ->get();
        $aplicadoPorLiquidacion = $liquidaciones->isEmpty() ? collect() : DB::table('abs_abono_aplicaciones')
            ->whereIn('liquidacion_id', $liquidaciones->pluck('id')->all())
            ->groupBy('liquidacion_id')->selectRaw('liquidacion_id, SUM(monto) as total')
            ->pluck('total', 'liquidacion_id');

        $abonos = Abono::deEmpresa($empresa->id)
            ->where('productor_id', $productor->id)->where('moneda', $moneda)
            ->with(['aplicaciones' => fn ($q) => $q->orderBy('id'), 'aplicaciones.liquidacion:id,folio', 'convenio:id,folio'])
            ->get();

        // Folios de las liquidaciones a las que se aplicaron abonos ya cancelados (sus filas ya no existen).
        $idsCanceladas = $abonos->where('status', 'cancelado')
            ->flatMap(fn (Abono $a) => array_column($a->aplicaciones_canceladas ?? [], 'liquidacion_id'))->unique()->values()->all();
        $foliosCancelados = $idsCanceladas === [] ? collect() : Liquidacion::withTrashed()->whereIn('id', $idsCanceladas)->pluck('folio', 'id');

        $filas = [];
        foreach ($liquidaciones as $l) {
            $fecha = ($l->aprobada_at ?? $l->created_at)?->copy()->setTimezone(Semanas::ZONA)->format('Y-m-d') ?? $l->periodo_fin->format('Y-m-d');
            $filas[] = [
                'orden' => [$fecha, 0, $l->id],
                'centavos' => self::centavos($l->monto_final),
                'cancelado' => false,
                'mov' => [
                    'tipo' => 'liquidacion',
                    'id' => $l->id,
                    'fecha' => $fecha,
                    'folio' => $l->folio,
                    'status' => $l->status,
                    'origen' => $l->origen,
                    'convenio' => $l->convenio ? ['id' => $l->convenio->id, 'folio' => $l->convenio->folio] : null,
                    'monto' => (float) $l->monto_final,
                    'aplicado' => round((float) $aplicadoPorLiquidacion->get($l->id, 0), 2),
                    'cancelado' => false,
                ],
            ];
        }

        foreach ($abonos as $a) {
            $cancelado = $a->status === 'cancelado';
            $aplicaciones = $cancelado
                ? array_map(fn ($f) => [
                    'liquidacion_id' => (int) $f['liquidacion_id'],
                    'folio' => $foliosCancelados->get($f['liquidacion_id']),
                    'monto' => round((float) $f['monto'], 2),
                ], $a->aplicaciones_canceladas ?? [])
                : $a->aplicaciones->map(fn ($f) => [
                    'liquidacion_id' => $f->liquidacion_id,
                    'folio' => $f->liquidacion?->folio,
                    'monto' => round((float) $f->monto, 2),
                ])->all();
            $aplicado = self::centavos(array_sum(array_column($aplicaciones, 'monto')));
            // Un abono cancelado no tiene efecto: nunca es «anticipo» ni deja nada sin aplicar (la etiqueta llega al PDF).
            $sinAplicar = $cancelado ? 0 : max(self::centavos($a->monto) - $aplicado, 0);
            $fecha = $a->fecha->format('Y-m-d');

            $filas[] = [
                'orden' => [$fecha, 1, $a->id],
                'centavos' => -self::centavos($a->monto),
                'cancelado' => $cancelado,
                'mov' => [
                    'tipo' => $sinAplicar > 0 ? 'anticipo' : 'abono',
                    'id' => $a->id,
                    'fecha' => $fecha,
                    'folio' => $a->folio,
                    'status' => $a->status,
                    'convenio' => $a->convenio ? ['id' => $a->convenio->id, 'folio' => $a->convenio->folio] : null,
                    'monto' => (float) $a->monto,
                    'metodo_pago' => $a->metodo_pago,
                    'referencia' => $a->referencia,
                    'aplicaciones' => $aplicaciones,
                    'aplicado' => $aplicado / 100,
                    'sin_aplicar' => $sinAplicar / 100,
                    'cancelado' => $cancelado,
                    'cancelado_motivo' => $cancelado ? $a->cancelado_motivo : null,
                    'cancelado_at' => $cancelado ? $a->cancelado_at?->copy()->setTimezone(Semanas::ZONA)->format('Y-m-d') : null,
                ],
            ];
        }

        usort($filas, fn ($x, $y) => $x['orden'] <=> $y['orden']);

        $corriente = 0;
        $movimientos = [];
        foreach ($filas as $f) {
            if (! $f['cancelado']) {
                $corriente += $f['centavos'];
            }
            $movimientos[] = $f['mov'] + ['saldo' => $corriente / 100];
        }

        return $movimientos;
    }

    private static function centavos(mixed $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }
}
