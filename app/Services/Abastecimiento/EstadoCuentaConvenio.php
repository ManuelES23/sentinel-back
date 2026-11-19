<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoRecepcion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Productor;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Estado de cuenta de un convenio (spec 6): los movimientos de su cuenta con saldo corrido. Solo lectura.
 *
 * Se arma con los mismos datos y reglas de `CuentaConvenio` (única fuente del saldo), así el último saldo SIEMPRE
 * es `totales.por_pagar`:
 *  - cargos: cada recepción no borrada por su valor a cuenta, y los pagos `saldo_inicial`;
 *  - abonos: los demás pagos (por recepción, abono, adelanto y pago de liquidación);
 *  - con la cuenta liquidada, una fila `ajuste_liquidacion` por `monto_final − (valor_recibido + cargos_iniciales)`
 *    (cargo si es positivo, abono si es negativo; no sale si es cero) con la fecha de la liquidación. Con ella el
 *    saldo corrido queda en `monto_final − pagado`, que es el `por_pagar` de una cuenta liquidada.
 * Un pago cancelado se muestra (`cancelado` true) pero no mueve el saldo.
 * Orden: fecha; a igual fecha los cargos antes que los abonos; luego id. El dinero va en centavos enteros.
 */
class EstadoCuentaConvenio
{
    /**
     * @return array{convenio: array, productor: array, moneda: string, estado: string, totales: array, movimientos: list<array>, saldo_final: float, empresa: array}
     */
    public static function armar(PresentadorCuenta $presentador, Enterprise $empresa, Convenio $convenio): array
    {
        $convenio->loadMissing('variedad:id,nombre');
        $totales = CuentaConvenio::totales(new EloquentCollection([$convenio]))[$convenio->id];
        $productor = Productor::withTrashed()->find($convenio->productor_id);

        $filas = [
            ...self::recepciones($convenio),
            ...self::pagos($convenio),
            ...self::ajuste($totales),
        ];
        usort($filas, fn ($x, $y) => $x['orden'] <=> $y['orden']);

        $corriente = 0;
        $movimientos = [];
        foreach ($filas as $f) {
            if (! $f['cancelado']) {
                $corriente += $f['cargo'] - $f['abono'];
            }
            $movimientos[] = [
                'tipo' => $f['tipo'],
                'fecha' => $f['fecha'],
                'folio' => $f['folio'],
                'concepto' => $f['concepto'],
                'cargo' => self::pesos($f['cargo']),
                'abono' => self::pesos($f['abono']),
                'saldo' => self::pesos($corriente),
                'cancelado' => $f['cancelado'],
            ];
        }

        return [
            'convenio' => $presentador->convenio($convenio),
            'productor' => [
                'id' => $productor?->id,
                'nombre' => $productor?->nombre,
                'tipo' => $productor?->tipo,
                'rfc' => $productor?->rfc,
                'contacto' => $productor?->contacto,
                'telefono' => $productor?->telefono,
                'email' => $productor?->email,
                'ubicacion' => $productor?->ubicacion,
            ],
            'moneda' => $convenio->moneda,
            'estado' => $totales['estado'],
            'totales' => [
                'valor_recibido' => $totales['valor_recibido'],
                'pagado' => $totales['pagado'],
                'cargos_iniciales' => $totales['cargos_iniciales'],
                'por_pagar' => $totales['por_pagar'],
            ],
            'movimientos' => $movimientos,
            'saldo_final' => $totales['por_pagar'],
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

    /** Cargo por cada recepción no borrada, a su valor a cuenta (el de `CuentaConvenio::recepciones`). */
    private static function recepciones(Convenio $convenio): array
    {
        return array_map(function (array $r) {
            $calibres = array_values(array_unique(array_filter(array_column($r['renglones'], 'calibre'))));
            $detalle = $calibres !== [] ? implode(', ', $calibres) : ($r['tipo_carga']['nombre'] ?? null);

            return self::fila(
                'recepcion', $r['fecha'], $r['id'], $r['folio'],
                'Recepción'.($detalle !== null ? ': '.$detalle : ''),
                self::centavos($r['valor']), 0, false,
            );
        }, CuentaConvenio::recepciones($convenio));
    }

    /** Todos los pagos del convenio, también los cancelados: `saldo_inicial` es cargo y el resto, abono. */
    private static function pagos(Convenio $convenio): array
    {
        $pagos = Abono::where('convenio_id', $convenio->id)
            ->with([
                'recepciones' => fn ($q) => $q->orderBy('id'),
                'recepciones.recepcion' => fn ($q) => $q->withTrashed()->select('id', 'folio'),
            ])
            ->orderBy('fecha')->orderBy('id')
            ->get();

        return $pagos->map(function (Abono $p) {
            $centavos = self::centavos($p->monto);
            $esCargo = $p->tipo === 'saldo_inicial';
            $concepto = match ($p->tipo) {
                'saldo_inicial' => $p->notas ?: 'Saldo inicial',
                'recepcion' => 'Pago por recepción: '.$p->recepciones
                    ->map(fn (AbonoRecepcion $r) => $r->recepcion?->folio)->filter()->unique()->implode(', '),
                'adelanto' => 'Adelanto',
                'liquidacion' => 'Pago de liquidación',
                default => 'Abono',
            };

            return self::fila(
                $esCargo ? 'saldo_inicial' : 'pago', $p->fecha->format('Y-m-d'), $p->id, $p->folio, $concepto,
                $esCargo ? $centavos : 0, $esCargo ? 0 : $centavos, $p->status === 'cancelado',
            );
        })->all();
    }

    /** Fila de ajuste de la liquidación vigente (solo con la cuenta liquidada y si hay diferencia). */
    private static function ajuste(array $totales): array
    {
        if ($totales['liquidacion_id'] === null) {
            return [];
        }
        $liquidacion = Liquidacion::find($totales['liquidacion_id'], ['id', 'folio', 'monto_final', 'liquidada_at', 'created_at']);
        if ($liquidacion === null) {
            return [];
        }

        $diferencia = self::centavos($liquidacion->monto_final)
            - (self::centavos($totales['valor_recibido']) + self::centavos($totales['cargos_iniciales']));
        if ($diferencia === 0) {
            return [];
        }

        $fecha = ($liquidacion->liquidada_at ?? $liquidacion->created_at)->copy()->setTimezone(Semanas::ZONA)->format('Y-m-d');

        return [self::fila(
            'ajuste_liquidacion', $fecha, $liquidacion->id, $liquidacion->folio,
            "Liquidación {$liquidacion->folio}: rezaga y ajustes",
            max($diferencia, 0), max(-$diferencia, 0), false,
        )];
    }

    /** Fila interna: `orden` = fecha, cargos antes que abonos, tipo de fila e id (desempate estable). */
    private static function fila(string $tipo, string $fecha, int $id, string $folio, string $concepto, int $cargo, int $abono, bool $cancelado): array
    {
        // Los ids son de tablas distintas: primero el tipo de fila y luego el id.
        $rango = ['recepcion' => 0, 'saldo_inicial' => 1, 'ajuste_liquidacion' => 2, 'pago' => 3][$tipo];

        return [
            'orden' => [$fecha, $abono > 0 ? 1 : 0, $rango, $id],
            'tipo' => $tipo,
            'fecha' => $fecha,
            'folio' => $folio,
            'concepto' => $concepto,
            'cargo' => $cargo,
            'abono' => $abono,
            'cancelado' => $cancelado,
        ];
    }

    private static function centavos(mixed $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }

    private static function pesos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
