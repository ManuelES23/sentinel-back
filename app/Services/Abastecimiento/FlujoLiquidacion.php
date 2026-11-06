<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\AbonoAplicacion;
use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Flujo de una liquidación (spec 6.5 y 6.6, plan 2): enviar a revisión, aprobar, rechazar y cancelar.
 *
 * Todos los métodos corren DENTRO de la transacción de quien llama (`TransaccionDinero::ejecutar`; no abren la
 * suya, igual que `AplicadorAbonos`) y empiezan bloqueando al productor y luego la liquidación con `lockForUpdate`: el estado y el paso se
 * revalidan contra la fila bloqueada, así que un doble clic o dos aprobadores del mismo paso no avanzan
 * la cadena dos veces (el segundo recibe 422 en `liquidacion`). `$pasoEsperado` (obligatorio) es el
 * `step_order` que quien decide tenía a la vista: si ya no es el paso actual, 422 y no se decide nada.
 * Orden de bloqueo: el productor, la liquidación y, al aprobar, los abonos (`AplicadorAbonos::aplicarAnticiposA`).
 * Como todo escritor de dinero bloquea primero al productor, dos escritores del mismo productor se turnan y el
 * orden de lo que sigue no puede cruzarse; las lecturas de precondición (paso actual, quién decidió, abonos
 * aplicados) se hacen DESPUÉS de esos bloqueos.
 *
 * Todo se valida ANTES de escribir, así un 422 no deja cambios a medias aunque la transacción no
 * se revierta.
 */
class FlujoLiquidacion
{
    public const MAX_TEXTO = 500;

    /**
     * Borrador → en revisión: recalcula con el mismo cálculo de la vista previa y congela totales, detalle y
     * cadena (ronda + 1). Exige monto final > 0 y que cada paso tenga a alguien que lo decida (rulings 2, 3 y 9).
     *
     * @throws ValidationException `liquidacion`, `recepcion_ids`, `monto_ajustado`
     */
    public static function enviar(Liquidacion $liquidacion, User $usuario): Liquidacion
    {
        $l = self::bloquear($liquidacion);
        if ($l->status !== 'borrador') {
            self::fallar('liquidacion', 'Solo se puede enviar a revisión una liquidación en borrador.');
        }

        $convenio = Convenio::with('precios')->findOrFail($l->convenio_id);
        $ids = LiquidacionDetalle::where('liquidacion_id', $l->id)->orderBy('id')->pluck('recepcion_id')->all();
        $recepciones = ReglasLiquidacion::validarSeleccion(
            $convenio, $ids, $l->periodo_inicio->format('Y-m-d'), $l->periodo_fin->format('Y-m-d'), $l->id,
        );
        $calculo = CalculadoraLiquidacion::calcular(
            $convenio, $recepciones,
            $l->monto_ajustado === null ? null : (float) $l->monto_ajustado,
            $l->motivo_ajuste,
        );

        if ($calculo['monto_final'] <= 0) {
            self::fallar(
                $l->monto_ajustado !== null ? 'monto_ajustado' : 'recepcion_ids',
                'El monto final de la liquidación debe ser mayor a cero para enviarla a revisión.',
            );
        }

        // Cadena de la ronda nueva: se comprueba en memoria, antes de escribir nada.
        $l->ronda = (int) $l->ronda + 1;
        $l->enviada_by = $usuario->id;
        // Quien envía (en cualquier ronda) queda excluido de decidir, aunque otro reenvíe después.
        $l->registrarIntervencion((int) $usuario->id);
        $pasos = CadenaLiquidacion::definir($l);
        self::exigirQuienDecida($l, $pasos);

        LiquidacionDetalle::where('liquidacion_id', $l->id)->delete();
        foreach ($calculo['detalles'] as $detalle) {
            LiquidacionDetalle::create($detalle + ['liquidacion_id' => $l->id]);
        }

        $l->fill([
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
            'status' => 'en_revision',
            'enviada_at' => now(),
        ]);
        CadenaLiquidacion::construir($l, $pasos);

        return $l->refresh();
    }

    /**
     * Aprueba el paso actual. Si era el último, la liquidación queda `aprobada` y se le aplican los anticipos
     * del productor (puede quedar `pagada`).
     *
     * @throws ValidationException `liquidacion`, `comentario`
     */
    public static function aprobar(Liquidacion $liquidacion, User $usuario, ?string $comentario, int $pasoEsperado): Liquidacion
    {
        $l = self::bloquear($liquidacion);
        $comentario = self::textoOpcional($comentario, 'comentario', 'El comentario');
        $paso = self::pasoADecidir($l, $usuario, 'aprobar', $pasoEsperado);

        $paso->update([
            'decision' => 'approved',
            'decided_by' => $usuario->id,
            'comment' => $comentario,
            'decided_at' => now(),
        ]);

        $siguiente = LiquidacionAprobacion::where('liquidacion_id', $l->id)
            ->where('ronda', $l->ronda)->where('decision', 'waiting')
            ->orderBy('step_order')->first();

        if ($siguiente !== null) {
            $l->paso_actual = $siguiente->step_order;
            $l->save();

            return $l->refresh();
        }

        $l->fill([
            'status' => 'aprobada',
            'paso_actual' => null,
            'aprobada_at' => now(),
            'aprobada_by' => $usuario->id,
        ])->save();

        // Una liquidación aprobada ya es deuda: se le aplican los anticipos vigentes (puede quedar pagada).
        AplicadorAbonos::aplicarAnticiposA($l);

        return $l->refresh();
    }

    /**
     * Rechaza el paso actual: la liquidación regresa a borrador con el motivo (conserva sus recepciones y los
     * pasos de esta ronda; el siguiente envío crea la ronda siguiente).
     *
     * @throws ValidationException `liquidacion`, `motivo`
     */
    public static function rechazar(Liquidacion $liquidacion, User $usuario, string $motivo, int $pasoEsperado): Liquidacion
    {
        $l = self::bloquear($liquidacion);
        $motivo = self::textoOpcional($motivo, 'motivo', 'El motivo');
        if ($motivo === null) {
            self::fallar('motivo', 'Escribe el motivo del rechazo.');
        }
        $paso = self::pasoADecidir($l, $usuario, 'rechazar', $pasoEsperado);

        $paso->update([
            'decision' => 'rejected',
            'decided_by' => $usuario->id,
            'comment' => $motivo,
            'decided_at' => now(),
        ]);
        self::cerrarPendientes($l, 'Rechazada en otro paso');

        $l->fill([
            'status' => 'borrador',
            'paso_actual' => null,
            'rechazo_motivo' => $motivo,
            'rechazada_at' => now(),
            'rechazada_by' => $usuario->id,
        ])->save();

        return $l->refresh();
    }

    /**
     * Cancela una liquidación en borrador, en revisión o aprobada SIN abonos aplicados y libera sus recepciones.
     *
     * @throws ValidationException `liquidacion`, `motivo`
     */
    public static function cancelar(Liquidacion $liquidacion, User $usuario, string $motivo): Liquidacion
    {
        $l = self::bloquear($liquidacion);
        $motivo = self::textoOpcional($motivo, 'motivo', 'El motivo');
        if ($motivo === null) {
            self::fallar('motivo', 'Escribe el motivo de la cancelación.');
        }

        if ($l->status === 'cancelada') {
            self::fallar('liquidacion', 'La liquidación ya está cancelada.');
        }
        if ($l->status === 'pagada') {
            self::fallar('liquidacion', 'Una liquidación pagada no se puede cancelar: cancela primero los abonos aplicados.');
        }
        if (! in_array($l->status, ['borrador', 'en_revision', 'aprobada'], true)) {
            self::fallar('liquidacion', 'La liquidación no se puede cancelar en su estado actual.');
        }
        if ($l->status === 'aprobada' && AbonoAplicacion::where('liquidacion_id', $l->id)->exists()) {
            self::fallar('liquidacion', 'La liquidación tiene abonos aplicados y no se puede cancelar: cancela primero los abonos.');
        }

        self::cerrarPendientes($l, 'Liquidación cancelada');
        $l->fill([
            'status' => 'cancelada',
            'paso_actual' => null,
            'cancelada_at' => now(),
            'cancelada_by' => $usuario->id,
            'cancelada_motivo' => $motivo,
        ])->save();
        ReglasLiquidacion::liberarRecepciones($l);

        return $l->refresh();
    }

    /** @return Collection<int, User> quienes hoy pueden decidir el paso actual (para avisarles); vacío si no está en revisión */
    public static function decididoresDe(Liquidacion $liquidacion): Collection
    {
        $paso = CadenaLiquidacion::pasoActual($liquidacion);

        return $paso === null ? collect() : AprobadorLiquidaciones::elegibles($liquidacion, $paso);
    }

    // ---------------------------------------------------------------- internos

    /** Productor primero y luego la liquidación (orden único de bloqueo de `TransaccionDinero`). */
    private static function bloquear(Liquidacion $liquidacion): Liquidacion
    {
        return TransaccionDinero::bloquearLiquidacion((int) $liquidacion->enterprise_id, (int) $liquidacion->id);
    }

    /** El paso actual en espera, ya comprobado que este usuario puede decidirlo. */
    private static function pasoADecidir(Liquidacion $l, User $usuario, string $accion, int $pasoEsperado): LiquidacionAprobacion
    {
        if ($l->status !== 'en_revision') {
            self::fallar('liquidacion', 'La liquidación no está en revisión.');
        }

        $paso = CadenaLiquidacion::pasoActual($l);
        if ($paso === null) {
            self::fallar('liquidacion', 'Este paso de la liquidación ya fue decidido; recarga para ver su estado.');
        }
        if ($pasoEsperado !== (int) $paso->step_order) {
            self::fallar('liquidacion', 'La liquidación ya cambió de paso; recarga la pantalla.');
        }

        if (AprobadorLiquidaciones::esCreadorOEnviador($usuario, $l)) {
            self::fallar('liquidacion', 'Quien creó, editó o envió una liquidación no puede aprobarla ni rechazarla.');
        }
        if (AprobadorLiquidaciones::decidioOtroPaso($usuario, $paso)) {
            self::fallar('liquidacion', 'Ya decidiste otro paso de esta liquidación: nadie decide dos pasos.');
        }
        if (! AprobadorLiquidaciones::puedeDecidir($usuario, $l, $paso, $accion)) {
            self::fallar('liquidacion', 'No puedes '.($accion === 'rechazar' ? 'rechazar' : 'aprobar').' este paso de la liquidación.');
        }

        return $paso;
    }

    /** Marca como omitidos los pasos que seguían esperando en la ronda actual. */
    private static function cerrarPendientes(Liquidacion $l, string $motivo): void
    {
        LiquidacionAprobacion::where('liquidacion_id', $l->id)->where('ronda', $l->ronda)->where('decision', 'waiting')
            ->update(['decision' => 'skipped', 'comment' => $motivo, 'decided_at' => now()]);
    }

    /**
     * Cada paso necesita a alguien distinto de quien creó y de quien envía, y nadie decide dos pasos:
     * se busca una persona distinta por paso (emparejamiento). Sin esto una cadena quedaría atorada para siempre.
     *
     * @param  Collection<int, LiquidacionAprobacion>  $pasos
     */
    private static function exigirQuienDecida(Liquidacion $l, Collection $pasos): void
    {
        $candidatos = [];
        foreach ($pasos->values() as $i => $paso) {
            $ids = AprobadorLiquidaciones::elegibles($l, $paso)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                self::fallar('liquidacion', "El paso {$paso->step_order} ({$paso->description}) no tiene a nadie que pueda decidirlo; quien creó, editó o envió la liquidación no cuenta. Revisa la cadena de aprobación.");
            }
            $candidatos[$i] = $ids;
        }

        $asignado = []; // usuario => índice de paso
        $emparejar = function (int $i, array &$visitados) use (&$emparejar, $candidatos, &$asignado): bool {
            foreach ($candidatos[$i] as $usuarioId) {
                if (isset($visitados[$usuarioId])) {
                    continue;
                }
                $visitados[$usuarioId] = true;
                if (! isset($asignado[$usuarioId]) || $emparejar($asignado[$usuarioId], $visitados)) {
                    $asignado[$usuarioId] = $i;

                    return true;
                }
            }

            return false;
        };

        foreach (array_keys($candidatos) as $i) {
            $visitados = [];
            if (! $emparejar($i, $visitados)) {
                self::fallar('liquidacion', 'Hay pasos de la cadena que solo puede decidir la misma persona y nadie decide dos pasos: agrega a otra persona o puesto. Revisa la cadena de aprobación.');
            }
        }
    }

    /** Recorta el texto; vacío = null; más de 500 caracteres = 422 en `$campo`. */
    private static function textoOpcional(?string $texto, string $campo, string $nombre): ?string
    {
        $texto = $texto === null ? null : trim($texto);
        if ($texto === null || $texto === '') {
            return null;
        }
        if (mb_strlen($texto) > self::MAX_TEXTO) {
            self::fallar($campo, $nombre.' no puede pasar de '.self::MAX_TEXTO.' caracteres.');
        }

        return $texto;
    }

    private static function fallar(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => [$mensaje]]);
    }
}
