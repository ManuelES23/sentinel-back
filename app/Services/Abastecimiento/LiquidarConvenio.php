<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * «Liquidar total» de un convenio (spec 5.4 y 5.6): la vista previa, la liquidación final y su cancelación.
 *
 * Una sola cuenta para la previa y para liquidar (`calculo`): todas las recepciones no borradas del convenio por
 * la `CalculadoraLiquidacion` (una rezaga real del convenio, % productor, redondeo por renglón), más los saldos
 * iniciales vigentes; lo pagado sale de `CuentaConvenio` (la única regla del saldo). Lo que muestra la previa es
 * lo que escribe `liquidar` si nada cambió entremedio.
 *
 * Las escrituras corren en `TransaccionDinero::ejecutar` con el orden único de bloqueo
 * productor → convenio → recepciones (por id) → pagos (por id) → liquidación.
 *
 * `liquidar` y `cancelar` devuelven la liquidación con la relación `pagos` puesta en los pagos `liquidacion` que
 * crearon o cancelaron (para que quien llama emita sus eventos después del commit).
 */
class LiquidarConvenio
{
    public function __construct(private GeneradorFolio $folios, private PagosCuenta $pagos)
    {
    }

    /**
     * Cálculo sin guardar: el de la calculadora más `saldo_inicial`, `monto_final` = (ajustado ?? calculado) +
     * saldo inicial, `pagado`, `restante` = monto_final − pagado, `moneda` y `unidad`. Cada detalle lleva además
     * `folio`, `fecha`, `tipo_carga` y, en convenios por calibre, `calibre`.
     *
     * @throws ValidationException `convenio` si ya está liquidado, no tiene recepciones o alguna no se puede liquidar; `motivo_ajuste`
     */
    public function previa(Convenio $convenio, ?float $montoAjustado, ?string $motivoAjuste): array
    {
        $recepciones = Recepcion::where('convenio_id', $convenio->id)->get();

        return $this->calculo($convenio, $recepciones, $montoAjustado, $motivoAjuste);
    }

    /**
     * Liquida el convenio completo: crea la liquidación `liquidada` (folio `LQ-`, sin periodo) con el detalle
     * congelado, liga todas las recepciones y, si se pide y hay restante, crea el pago `liquidacion` por el restante.
     *
     * @param  array{monto_ajustado?: float|string|null, motivo_ajuste?: ?string, notas?: ?string, registrar_pago: bool, pago?: ?array{fecha: string, metodo_pago: string, referencia?: ?string, notas?: ?string}}  $datos
     */
    public function liquidar(Enterprise $empresa, User $usuario, int $convenioId, array $datos): Liquidacion
    {
        return TransaccionDinero::ejecutar(function () use ($empresa, $usuario, $convenioId, $datos) {
            $convenio = TransaccionDinero::bloquearConvenio($empresa->id, $convenioId);
            $recepciones = TransaccionDinero::bloquearRecepciones(
                $convenio,
                Recepcion::where('convenio_id', $convenio->id)->pluck('id')->all(),
            );

            $ajuste = isset($datos['monto_ajustado']) ? (float) $datos['monto_ajustado'] : null;
            $calculo = $this->calculo($convenio, $recepciones, $ajuste, $datos['motivo_ajuste'] ?? null);

            $restanteCts = self::centavos($calculo['restante']);
            $pagar = ! empty($datos['registrar_pago']) && $restanteCts > 0;
            if ($pagar && empty($datos['pago'])) {
                throw ValidationException::withMessages(['pago' => 'Faltan la fecha y el método del pago.']);
            }

            $liquidacion = Liquidacion::create([
                'enterprise_id' => $empresa->id,
                'folio' => $this->folios->siguiente($empresa, 'LQ'),
                'convenio_id' => $convenio->id,
                'productor_id' => $convenio->productor_id,
                'moneda' => $convenio->moneda,
                'periodo_inicio' => null,
                'periodo_fin' => null,
                'cantidad' => $calculo['cantidad'],
                'kilos' => $calculo['kilos'],
                'bruto' => $calculo['bruto'],
                'rezaga_real_pct' => $calculo['rezaga_real_pct'],
                'rezaga_aceptada_pct' => $calculo['rezaga_aceptada_pct'],
                'descuento' => $calculo['descuento'],
                'monto_calculado' => $calculo['monto_calculado'],
                'monto_ajustado' => $calculo['monto_ajustado'],
                'motivo_ajuste' => $calculo['motivo_ajuste'],
                'saldo_inicial' => $calculo['saldo_inicial'],
                'monto_final' => $calculo['monto_final'],
                'status' => 'liquidada',
                'origen' => 'normal',
                'liquidada_at' => now(),
                'liquidada_by' => $usuario->id,
                'created_by' => $usuario->id,
                'notas' => $datos['notas'] ?? null,
            ]);

            $ahora = now();
            $campos = array_flip(['recepcion_id', 'calibre_id', 'cantidad', 'kilos', 'rezaga_kg', 'precio', 'porcentaje_productor', 'subtotal', 'descuento', 'a_pagar']);
            $filas = array_map(fn (array $d) => array_intersect_key($d, $campos) + [
                'calibre_id' => null,
                'liquidacion_id' => $liquidacion->id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], $calculo['detalles']);
            foreach (array_chunk($filas, 200) as $tramo) {
                LiquidacionDetalle::insert($tramo);
            }

            Recepcion::whereIn('id', $recepciones->modelKeys())->update(['liquidacion_id' => $liquidacion->id]);

            $pagosCreados = new EloquentCollection;
            if ($pagar) {
                $pagosCreados->push($this->pagos->crear($convenio, $usuario, [
                    'tipo' => 'liquidacion',
                    'liquidacion_id' => $liquidacion->id,
                    'monto' => self::pesos($restanteCts),
                    'fecha' => $datos['pago']['fecha'],
                    'metodo_pago' => $datos['pago']['metodo_pago'],
                    'referencia' => $datos['pago']['referencia'] ?? null,
                    'notas' => $datos['pago']['notas'] ?? null,
                ]));
            }

            return $liquidacion->setRelation('pagos', $pagosCreados);
        });
    }

    /**
     * Cancela la liquidación: `cancelada` con quién, cuándo y motivo; libera sus recepciones y cancela su pago
     * `liquidacion` vigente. Los demás pagos siguen; la cuenta vuelve a abierta y se puede liquidar de nuevo.
     */
    public function cancelar(Enterprise $empresa, User $usuario, int $liquidacionId, string $motivo): Liquidacion
    {
        return TransaccionDinero::ejecutar(function () use ($empresa, $usuario, $liquidacionId, $motivo) {
            [, $liquidacion] = TransaccionDinero::bloquearLiquidacion($empresa->id, $liquidacionId);

            if ($liquidacion->status === 'cancelada') {
                throw ValidationException::withMessages(['id' => 'La liquidación ya está cancelada.']);
            }

            $ahora = now();
            $liquidacion->update([
                'status' => 'cancelada',
                'cancelada_at' => $ahora,
                'cancelada_by' => $usuario->id,
                'cancelada_motivo' => $motivo,
            ]);

            Recepcion::withTrashed()->where('liquidacion_id', $liquidacion->id)->update(['liquidacion_id' => null]);

            $cancelados = Abono::where('liquidacion_id', $liquidacion->id)
                ->where('tipo', 'liquidacion')
                ->where('status', '!=', 'cancelado')
                ->orderBy('id')
                ->get();
            foreach ($cancelados as $pago) {
                $pago->update([
                    'status' => 'cancelado',
                    'cancelado_motivo' => "Se canceló la liquidación {$liquidacion->folio}: {$motivo}",
                    'cancelado_by' => $usuario->id,
                    'cancelado_at' => $ahora,
                ]);
            }

            return $liquidacion->setRelation('pagos', $cancelados);
        });
    }

    /**
     * La cuenta de la previa y de `liquidar` (una sola, para que no se separen): reglas, calculadora, saldo inicial,
     * pagado y restante sobre `$recepciones` (las no borradas del convenio; en `liquidar`, ya bloqueadas).
     *
     * @param  EloquentCollection<int, Recepcion>  $recepciones
     */
    private function calculo(Convenio $convenio, EloquentCollection $recepciones, ?float $montoAjustado, ?string $motivoAjuste): array
    {
        $totales = CuentaConvenio::totales(new EloquentCollection([$convenio]))[$convenio->id];
        if ($totales['estado'] === 'liquidada') {
            $folio = Liquidacion::whereKey($totales['liquidacion_id'])->value('folio');
            throw ValidationException::withMessages(['convenio' => "El convenio ya está liquidado ({$folio})."]);
        }

        $recepciones = $this->preparar($convenio, $recepciones);
        if ($recepciones->isEmpty()) {
            throw ValidationException::withMessages(['convenio' => 'El convenio no tiene recepciones para liquidar.']);
        }

        $conMotivo = [];
        foreach ($recepciones as $r) {
            $motivo = ValorACuenta::de($convenio, $r)['motivo'];
            if ($motivo !== null) {
                $conMotivo[] = "{$r->folio} ({$motivo})";
            }
        }
        if ($conMotivo !== []) {
            throw ValidationException::withMessages(['convenio' => 'No se puede liquidar: '.implode(', ', $conMotivo).'.']);
        }

        $calculo = CalculadoraLiquidacion::calcular($convenio, $recepciones, $montoAjustado, $motivoAjuste);

        $saldoCts = self::centavos($totales['cargos_iniciales']);
        $finalCts = self::centavos($calculo['monto_final']) + $saldoCts;
        $pagadoCts = self::centavos($totales['pagado']);

        $porId = $recepciones->keyBy('id');
        $porCalibre = (bool) $convenio->usa_calibres;
        $calculo['detalles'] = array_map(function (array $d) use ($porId, $porCalibre) {
            $r = $porId[$d['recepcion_id']];
            $extra = [
                'folio' => $r->folio,
                'fecha' => $r->fecha->format('Y-m-d'),
                'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
            ];
            if ($porCalibre) {
                $calibre = $r->renglones->firstWhere('calibre_id', $d['calibre_id'])?->calibre;
                $extra['calibre'] = $calibre ? ['id' => $calibre->id, 'nombre' => $calibre->nombre] : null;
            }

            return $d + $extra;
        }, $calculo['detalles']);

        return array_merge($calculo, [
            'saldo_inicial' => self::pesos($saldoCts),
            'monto_final' => self::pesos($finalCts),
            'pagado' => self::pesos($pagadoCts),
            'restante' => self::pesos($finalCts - $pagadoCts),
            'moneda' => $convenio->moneda,
            'unidad' => $convenio->unidad,
        ]);
    }

    /**
     * Recepciones listas para la calculadora: por fecha e id, con `tipoCarga`, los `renglones.calibre` en un
     * convenio por calibre (vacíos sin consultar en uno clásico) y los precios del convenio cargados.
     *
     * @param  EloquentCollection<int, Recepcion>  $recepciones
     * @return EloquentCollection<int, Recepcion>
     */
    private function preparar(Convenio $convenio, EloquentCollection $recepciones): EloquentCollection
    {
        $convenio->loadMissing('precios');
        $recepciones = $recepciones->sortBy([
            fn (Recepcion $a, Recepcion $b) => $a->fecha <=> $b->fecha,
            fn (Recepcion $a, Recepcion $b) => $a->id <=> $b->id,
        ])->values();

        $recepciones->load('tipoCarga:id,nombre');
        if ($convenio->usa_calibres) {
            $recepciones->load(['renglones' => fn ($q) => $q->orderBy('id'), 'renglones.calibre' => fn ($q) => $q->select('id', 'nombre')]);
        } else {
            $recepciones->each(fn (Recepcion $r) => $r->setRelation('renglones', new EloquentCollection));
        }

        return $recepciones;
    }

    private static function centavos(float|int|string $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    private static function pesos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
