<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Abono;
use App\Models\Abastecimiento\AbonoRecepcion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * Pagos de la cuenta de un convenio (spec 5.2 y 5.6): por recepción, abono, adelanto y saldo inicial, y su
 * cancelación. Cada método público corre en su propia `TransaccionDinero::ejecutar` y bloquea en el orden único
 * productor → convenio → recepciones (por id) → pagos (por id) → liquidación.
 *
 * Reglas del dinero: los montos se comparan en centavos enteros; cada pago va en la moneda de su convenio (lo que
 * mande el cliente se ignora); ningún pago pasa por aprobación (`aplicado` en cuanto se guarda); cancelar conserva
 * el reparto entre recepciones (el pago deja de contar porque queda `cancelado`).
 */
class PagosCuenta
{
    /** Tipos que se registran desde «Pagar» / «Abonar»; `liquidacion` y `saldo_inicial` tienen su propia acción. */
    public const TIPOS_REGISTRABLES = ['recepcion', 'abono', 'adelanto'];

    public function __construct(private GeneradorFolio $folios)
    {
    }

    /**
     * Registra un pago por recepción, abono o adelanto.
     *
     * @param  array{tipo: string, fecha: string, metodo_pago: string, referencia?: ?string, notas?: ?string, monto?: float|string, recepciones?: list<array{recepcion_id: int, monto: float|string}>}  $datos
     */
    public function registrar(Enterprise $empresa, User $usuario, int $convenioId, array $datos): Abono
    {
        if (! in_array($datos['tipo'] ?? null, self::TIPOS_REGISTRABLES, true)) {
            throw ValidationException::withMessages(['tipo' => 'El tipo de pago no es válido.']);
        }

        return TransaccionDinero::ejecutar(function () use ($empresa, $usuario, $convenioId, $datos) {
            $convenio = TransaccionDinero::bloquearConvenio($empresa->id, $convenioId);
            if ($datos['tipo'] === 'recepcion') {
                TransaccionDinero::bloquearRecepciones($convenio, array_column($datos['recepciones'] ?? [], 'recepcion_id'));
            }

            return $this->crear($convenio, $usuario, $datos);
        });
    }

    /**
     * Saldo inicial (deuda sin recepciones, spec 5.6): un pago `saldo_inicial` que suma a lo que se le debe.
     * El motivo se guarda en `notas`.
     *
     * @param  array{monto: float|string, fecha: string, motivo: string}  $datos
     */
    public function saldoInicial(Enterprise $empresa, User $usuario, int $convenioId, array $datos): Abono
    {
        return TransaccionDinero::ejecutar(function () use ($empresa, $usuario, $convenioId, $datos) {
            $convenio = TransaccionDinero::bloquearConvenio($empresa->id, $convenioId);

            return $this->crear($convenio, $usuario, [
                'tipo' => 'saldo_inicial',
                'monto' => $datos['monto'],
                'fecha' => $datos['fecha'],
                'notas' => $datos['motivo'],
            ]);
        });
    }

    /** Cancela un pago (cualquiera salvo el de una liquidación) mientras la cuenta esté abierta. */
    public function cancelar(Enterprise $empresa, User $usuario, int $pagoId, string $motivo): Abono
    {
        return TransaccionDinero::ejecutar(function () use ($empresa, $usuario, $pagoId, $motivo) {
            [$convenio, $pago] = TransaccionDinero::bloquearPago($empresa->id, $pagoId);

            if ($pago->status === 'cancelado') {
                throw ValidationException::withMessages(['id' => 'El pago ya está cancelado.']);
            }
            if ($pago->tipo === 'liquidacion') {
                $folio = Liquidacion::withTrashed()->whereKey($pago->liquidacion_id)->value('folio');
                throw ValidationException::withMessages([
                    'id' => "Este pago es de la liquidación {$folio}: se cancela al cancelar la liquidación.",
                ]);
            }
            $totales = $this->totales($convenio);
            if ($totales['estado'] === 'liquidada') {
                $folio = Liquidacion::whereKey($totales['liquidacion_id'])->value('folio');
                throw ValidationException::withMessages([
                    'id' => "El convenio está liquidado: cancela primero la liquidación {$folio}.",
                ]);
            }

            // El reparto en `abs_abono_recepciones` se conserva: el pago deja de contar por su status.
            $pago->update([
                'status' => 'cancelado',
                'cancelado_motivo' => $motivo,
                'cancelado_by' => $usuario->id,
                'cancelado_at' => now(),
            ]);

            return $pago;
        });
    }

    /**
     * Crea un pago sobre un convenio que quien llama YA bloqueó (y, para el tipo `recepcion`, también sus
     * recepciones): no abre transacción ni bloquea nada. Valida contra el estado de la cuenta en este momento,
     * toma el folio `AB-`, escribe el pago y, si es por recepción, su reparto.
     *
     * Tipos: `recepcion` (con `recepciones`), `abono`, `adelanto`, `saldo_inicial` y `liquidacion` (con
     * `liquidacion_id`; lo usa «Liquidar total», que decide su monto, por eso aquí no tiene tope).
     *
     * @param  array{tipo: string, fecha: string, metodo_pago?: ?string, referencia?: ?string, notas?: ?string, monto?: float|string, recepciones?: list<array{recepcion_id: int, monto: float|string}>, liquidacion_id?: int}  $datos
     */
    public function crear(Convenio $convenio, User $usuario, array $datos): Abono
    {
        $tipo = $datos['tipo'] ?? null;
        if (! in_array($tipo, Abono::TIPOS, true)) {
            throw ValidationException::withMessages(['tipo' => 'El tipo de pago no es válido.']);
        }
        if ($convenio->status === 'borrador') {
            throw ValidationException::withMessages(['convenio' => 'El convenio está en borrador: no admite pagos.']);
        }
        if ($convenio->status === 'cancelado') {
            throw ValidationException::withMessages(['convenio' => 'El convenio está cancelado: no admite pagos.']);
        }

        $totales = $this->totales($convenio);
        $liquidada = $totales['estado'] === 'liquidada';
        $reparto = [];

        switch ($tipo) {
            case 'recepcion':
                if ($liquidada) {
                    throw ValidationException::withMessages(['convenio' => 'El convenio ya está liquidado: no se pagan recepciones.']);
                }
                $reparto = $this->reparto($convenio, $datos['recepciones'] ?? []);
                $montoCts = array_sum($reparto);
                break;

            case 'abono':
            case 'adelanto':
                $montoCts = self::centavos($datos['monto'] ?? 0);
                $restanteCts = self::centavos($totales['por_pagar']);
                if ($liquidada && $montoCts > $restanteCts) {
                    $restante = self::dinero(max(0, $restanteCts));
                    throw ValidationException::withMessages([
                        'monto' => "El abono pasa de lo que falta por pagar ({$restante} {$convenio->moneda}).",
                    ]);
                }
                break;

            case 'saldo_inicial':
                if ($liquidada) {
                    throw ValidationException::withMessages(['convenio' => 'El convenio ya está liquidado: no admite saldo inicial.']);
                }
                $montoCts = self::centavos($datos['monto'] ?? 0);
                break;

            default: // liquidacion
                if (empty($datos['liquidacion_id'])) {
                    throw ValidationException::withMessages(['liquidacion_id' => 'El pago de liquidación necesita su liquidación.']);
                }
                $montoCts = self::centavos($datos['monto'] ?? 0);
        }

        if ($montoCts <= 0) {
            throw ValidationException::withMessages(['monto' => 'El monto debe ser mayor a cero.']);
        }

        $empresa = Enterprise::findOrFail($convenio->enterprise_id);
        $pago = Abono::create([
            'enterprise_id' => $convenio->enterprise_id,
            'folio' => $this->folios->siguiente($empresa, 'AB'),
            'productor_id' => $convenio->productor_id,
            'convenio_id' => $convenio->id,
            'tipo' => $tipo,
            'liquidacion_id' => $tipo === 'liquidacion' ? (int) $datos['liquidacion_id'] : null,
            'fecha' => $datos['fecha'],
            'monto' => self::pesos($montoCts),
            'moneda' => $convenio->moneda,
            'metodo_pago' => $datos['metodo_pago'] ?? null,
            'referencia' => $datos['referencia'] ?? null,
            'notas' => $datos['notas'] ?? null,
            'status' => 'aplicado',
            'created_by' => $usuario->id,
        ]);

        foreach ($reparto as $recepcionId => $centavos) {
            AbonoRecepcion::create(['abono_id' => $pago->id, 'recepcion_id' => $recepcionId, 'monto' => self::pesos($centavos)]);
        }

        return $pago;
    }

    /**
     * Valida las filas de un pago por recepción contra la cuenta viva del convenio y devuelve el reparto
     * (recepcion_id => centavos, en el orden recibido). Junta todos los errores por fila en una sola respuesta.
     *
     * @param  list<array{recepcion_id: int, monto: float|string}>  $filas
     * @return array<int, int>
     */
    private function reparto(Convenio $convenio, array $filas): array
    {
        if ($filas === []) {
            throw ValidationException::withMessages(['recepciones' => 'Elige al menos una recepción.']);
        }

        $cuenta = array_column(CuentaConvenio::recepciones($convenio), null, 'id');
        $reparto = [];
        $errores = [];

        foreach (array_values($filas) as $n => $fila) {
            $id = (int) ($fila['recepcion_id'] ?? 0);
            $r = $cuenta[$id] ?? null;
            if ($r === null) {
                $errores["recepciones.{$n}.recepcion_id"] = 'La recepción no es de este convenio.';

                continue;
            }
            if (isset($reparto[$id])) {
                $errores["recepciones.{$n}.recepcion_id"] = "La recepción {$r['folio']} está repetida.";

                continue;
            }
            if ($r['motivo'] !== null) {
                $errores["recepciones.{$n}.recepcion_id"] = "{$r['folio']}: {$r['motivo']}.";

                continue;
            }

            $montoCts = self::centavos($fila['monto'] ?? 0);
            $faltaCts = self::centavos($r['falta']);
            if ($montoCts > $faltaCts) {
                $falta = self::dinero($faltaCts);
                $errores["recepciones.{$n}.monto"] = "El monto de {$r['folio']} pasa de lo que le falta ({$falta} {$convenio->moneda}).";
            }
            $reparto[$id] = $montoCts;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $reparto;
    }

    /** Totales de la cuenta del convenio (estado, por pagar y liquidación vigente) leídos ahora, dentro del bloqueo. */
    private function totales(Convenio $convenio): array
    {
        return CuentaConvenio::totales(new EloquentCollection([$convenio]))[$convenio->id];
    }

    private static function centavos(float|int|string $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    private static function pesos(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /** `1,234.50`: el formato de los montos en los mensajes. */
    private static function dinero(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', ',');
    }
}
