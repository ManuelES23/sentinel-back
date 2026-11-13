<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Recepcion;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de negocio de una recepción (spec 6.4). El controlador ya validó los
 * tipos del cuerpo (fechas con formato, números, empresa de las llaves);
 * aquí viven las reglas que dependen del convenio. Junta todos los errores
 * en una sola ValidationException.
 */
class ReglasRecepcion
{
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public const MENSAJE_LIQUIDADA = 'Ya está en una liquidación: no se puede cambiar.';

    public const MENSAJE_POR_CALIBRE = 'Este convenio es por calibre: la captura de recepciones por calibre llega en la siguiente entrega.';

    /**
     * Valida el estado FINAL de la recepción contra el convenio al que quedará ligada
     * (en una edición, el del registro o el nuevo si cambió `convenio_id`). El convenio
     * debe estar activo en el alta y al pasar la recepción a otro convenio.
     *
     * `$precios` permite pasar un PrecioVigente con los precios ya cargados (la importación
     * valida cientos de renglones sin una consulta por cada uno).
     *
     * @param  array{fecha: string, tipo_carga_id: int|string, cantidad: int|float|string, kilos?: mixed, rezaga_kg?: mixed}  $datos
     */
    public static function validar(Convenio $convenio, array $datos, ?Recepcion $actual = null, ?PrecioVigente $precios = null): void
    {
        if ($actual !== null) {
            self::exigirEditable($actual);
        }

        // Mientras no exista la captura por calibre (plan 2), un convenio por calibre no recibe
        // recepciones: ni en el alta, ni en la edición, ni en la importación. Se corta aquí para
        // que el mensaje sea el único y no se mezcle con errores de precio por calibre.
        if ($convenio->usa_calibres) {
            throw ValidationException::withMessages(['convenio_id' => self::MENSAJE_POR_CALIBRE]);
        }

        $errores = [];

        // Solo el alta y el cambio de convenio exigen un convenio activo: corregir una recepción
        // que se queda en su convenio (aunque ya esté suspendido o finalizado) sigue permitido.
        $cambiaDeConvenio = $actual === null || (int) $actual->convenio_id !== (int) $convenio->id;
        if ($cambiaDeConvenio && $convenio->status !== 'activo') {
            $errores['convenio_id'] = 'El convenio no está activo.';
        }

        $fecha = (string) $datos['fecha'];
        $inicio = $convenio->fecha_inicio->format('Y-m-d');
        $fin = $convenio->fecha_fin->format('Y-m-d');
        if ($fecha > Semanas::hoy()->toDateString()) {
            $errores['fecha'] = 'La fecha no puede ser futura.';
        } elseif ($fecha < $inicio || $fecha > $fin) {
            $errores['fecha'] = 'La fecha está fuera del convenio ('.self::corta($inicio).' a '.self::corta($fin).').';
        }

        if (! isset($errores['fecha'])
            && ($precios ?? app(PrecioVigente::class))->para($convenio, (int) $datos['tipo_carga_id'], $fecha) === null) {
            $errores['tipo_carga_id'] = 'Este tipo de carga no tiene precio en el convenio para esa fecha.';
        }

        // Piezas del tipo de carga (cajas, bins): solo enteros. Los kilos llevan hasta 2 decimales.
        $cantidad = (float) $datos['cantidad'];
        if ($cantidad <= 0) {
            $errores['cantidad'] = 'La cantidad debe ser mayor a cero.';
        } elseif ($cantidad != floor($cantidad)) {
            $errores['cantidad'] = 'La cantidad va en números enteros.';
        }

        $kilos = $datos['kilos'] ?? null;
        $rezaga = $datos['rezaga_kg'] ?? null;
        $hayKilos = $kilos !== null && $kilos !== '';
        $hayRezaga = $rezaga !== null && $rezaga !== '';

        if ($hayKilos) {
            if (! self::dosDecimales((float) $kilos)) {
                $errores['kilos'] = 'Los kilos aceptan hasta 2 decimales.';
            } elseif ((float) $kilos <= 0 && ($convenio->unidad === 'kg' || $hayRezaga)) {
                $errores['kilos'] = 'Los kilos deben ser mayores a cero.';
            } elseif ((float) $kilos < 0) {
                $errores['kilos'] = 'Los kilos no pueden ser negativos.';
            }
        } elseif ($convenio->unidad === 'kg') {
            $errores['kilos'] = 'Este convenio se paga por kilo: captura los kilos.';
        } elseif ($hayRezaga) {
            $errores['kilos'] = 'Captura los kilos de báscula para registrar la rezaga.';
        }

        if ($hayRezaga) {
            if (! self::dosDecimales((float) $rezaga)) {
                $errores['rezaga_kg'] = 'La rezaga acepta hasta 2 decimales.';
            } elseif ((float) $rezaga < 0) {
                $errores['rezaga_kg'] = 'La rezaga no puede ser negativa.';
            } elseif ($hayKilos && (float) $rezaga > (float) $kilos) {
                $errores['rezaga_kg'] = 'La rezaga no puede ser mayor que los kilos.';
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /** Una recepción en una liquidación ya no se edita ni se borra. */
    public static function exigirEditable(Recepcion $recepcion): void
    {
        if ($recepcion->estaLiquidada()) {
            throw ValidationException::withMessages(['id' => self::MENSAJE_LIQUIDADA]);
        }
    }

    /**
     * Importe estimado: la cantidad en la unidad del convenio (kilos si se paga por
     * kilo, si no las piezas) × precio vigente × % del productor ÷ 100 (100 % en
     * compra directa), redondeado a centavos. Nulo si no hay precio.
     */
    public static function importeEstimado(Convenio $convenio, Recepcion $recepcion, ?ConvenioPrecio $precio): ?float
    {
        if ($precio === null) {
            return null;
        }

        $cantidad = (float) ($convenio->unidad === 'kg' ? $recepcion->kilos : $recepcion->cantidad);
        $porcentaje = $precio->porcentaje_productor === null ? 100.0 : (float) $precio->porcentaje_productor;

        return round($cantidad * (float) $precio->precio * $porcentaje / 100, 2);
    }

    /** «22 jun». */
    private static function corta(string $fecha): string
    {
        [, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return $dia.' '.self::MESES[$mes - 1];
    }

    private static function dosDecimales(float $valor): bool
    {
        return round($valor, 2) == $valor;
    }
}
