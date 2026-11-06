<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Liquidación de carga inicial (spec 5.7, plan 3 ruling 7): deuda con un productor que ya existía antes de usar el
 * módulo. Se crea directamente `aprobada` (`origen = carga_inicial`), sin cadena ni avisos y a nombre de quien la
 * captura. Dos modos:
 *  - con recepciones (`recepcion_ids`): mismo cálculo y selección que un borrador; las recepciones quedan ligadas;
 *  - solo monto (`monto` + `motivo`): sin recepciones. Cuenta en el saldo (`liquidado`, `por_pagar`) pero no en el
 *    recibido de los indicadores ni en «sin liquidar» (esos salen de las recepciones).
 *
 * Es una escritura de dinero: corre en `TransaccionDinero::ejecutar` y bloquea PRIMERO al productor (orden único
 * productor → abonos → liquidaciones); `AplicadorAbonos::aplicarAnticiposA` bloquea después los abonos. Los datos
 * llegan ya con su forma validada por el controlador; aquí se revisan las reglas de negocio.
 */
class CargaInicialLiquidacion
{
    public const MOTIVO_MINIMO = 10;

    /**
     * @param  array{convenio_id: int, periodo_inicio: string, periodo_fin: string, recepcion_ids?: array<int, int|string>, monto_ajustado?: float|int|string|null, motivo_ajuste?: string|null, monto?: float|int|string|null, motivo?: string|null, notas?: string|null}  $datos
     *
     * @throws ValidationException `convenio_id` (borrador o cancelado), `recepcion_ids`, `monto`, `motivo`, `motivo_ajuste`
     */
    public static function crear(Enterprise $empresa, User $usuario, array $datos): Liquidacion
    {
        $conRecepciones = ! empty($datos['recepcion_ids']);
        $soloMonto = isset($datos['monto']);
        if ($conRecepciones === $soloMonto) {
            self::fallar($conRecepciones ? 'monto' : 'recepcion_ids', 'Elige recepciones o captura un monto, pero no ambos.');
        }

        return TransaccionDinero::ejecutar(function () use ($empresa, $usuario, $datos, $conRecepciones) {
            // Primero el productor (sin leer nada que dependa de él), después el convenio y lo demás.
            $productorId = Convenio::deEmpresa($empresa->id)->whereKey($datos['convenio_id'])->value('productor_id');
            if ($productorId === null) {
                self::fallar('convenio_id', 'El convenio no existe.');
            }
            TransaccionDinero::bloquearProductor($empresa->id, (int) $productorId, true);

            // Con bloqueo (orden productor → convenio): `ConvenioController::estado` bloquea el convenio y revisa sus
            // liquidaciones; así no se cuela una carga inicial mientras se cancela el convenio. `estado` no bloquea
            // productores, de modo que no hay ciclo.
            $convenio = Convenio::deEmpresa($empresa->id)->with('precios')->lockForUpdate()->findOrFail($datos['convenio_id']);
            if (in_array($convenio->status, ['borrador', 'cancelado'], true)) {
                self::fallar('convenio_id', 'El convenio está en borrador o cancelado: no se le puede registrar una carga inicial.');
            }

            $recepciones = null;
            if ($conRecepciones) {
                $recepciones = ReglasLiquidacion::validarSeleccion(
                    $convenio, $datos['recepcion_ids'], $datos['periodo_inicio'], $datos['periodo_fin'],
                );
                $calculo = CalculadoraLiquidacion::calcular(
                    $convenio, $recepciones,
                    isset($datos['monto_ajustado']) ? (float) $datos['monto_ajustado'] : null,
                    $datos['motivo_ajuste'] ?? null,
                );
                // Misma regla que `FlujoLiquidacion::enviar`: una liquidación en cero no se puede aprobar.
                if ($calculo['monto_final'] <= 0) {
                    self::fallar(
                        $calculo['monto_ajustado'] !== null ? 'monto_ajustado' : 'recepcion_ids',
                        'El monto final de la liquidación debe ser mayor a cero.',
                    );
                }
            } else {
                $calculo = self::calculoSoloMonto($datos);
            }

            $liquidacion = Liquidacion::create([
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'LQ'),
                'convenio_id' => $convenio->id,
                'productor_id' => $convenio->productor_id,
                'moneda' => $convenio->moneda,
                'periodo_inicio' => $datos['periodo_inicio'],
                'periodo_fin' => $datos['periodo_fin'],
                'cantidad' => $calculo['cantidad'],
                'kilos' => $calculo['kilos'],
                'bruto' => $calculo['bruto'],
                'rezaga_real_pct' => $calculo['rezaga_real_pct'],
                'rezaga_aceptada_pct' => $calculo['rezaga_aceptada_pct'],
                'descuento' => $calculo['descuento'],
                'monto_calculado' => $calculo['monto_calculado'],
                'monto_ajustado' => $calculo['monto_ajustado'],
                'motivo_ajuste' => $calculo['motivo_ajuste'],
                'monto_final' => $calculo['monto_final'],
                'status' => 'aprobada',
                'origen' => 'carga_inicial',
                'aprobada_at' => now(),
                'aprobada_by' => $usuario->id,
                'notas' => $datos['notas'] ?? null,
                'created_by' => $usuario->id,
                'intervinieron' => [(int) $usuario->id],
            ]);

            if ($recepciones !== null) {
                foreach ($calculo['detalles'] as $detalle) {
                    LiquidacionDetalle::create($detalle + ['liquidacion_id' => $liquidacion->id]);
                }
                Recepcion::whereIn('id', $recepciones->pluck('id'))->update(['liquidacion_id' => $liquidacion->id]);
            }

            // Ya es deuda: se le aplican los anticipos del productor (puede quedar pagada).
            AplicadorAbonos::aplicarAnticiposA($liquidacion);

            return $liquidacion->refresh();
        }, 3);
    }

    /**
     * Importes del modo solo monto: calculado = ajustado = final = monto, y todo lo que sale de recepciones en cero.
     *
     * @return array<string, mixed>
     */
    private static function calculoSoloMonto(array $datos): array
    {
        $monto = round((float) $datos['monto'], 2);
        if ($monto <= 0) {
            self::fallar('monto', 'El monto debe ser mayor a cero.');
        }
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            self::fallar('motivo', 'Explica el motivo en al menos '.self::MOTIVO_MINIMO.' caracteres.');
        }

        return [
            'cantidad' => 0, 'kilos' => 0, 'bruto' => 0, 'rezaga_real_pct' => 0, 'rezaga_aceptada_pct' => 0,
            'descuento' => 0, 'monto_calculado' => $monto, 'monto_ajustado' => $monto,
            'motivo_ajuste' => $motivo, 'monto_final' => $monto, 'detalles' => [],
        ];
    }

    private static function fallar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
