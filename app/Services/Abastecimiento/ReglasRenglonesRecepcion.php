<?php

namespace App\Services\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\RecepcionCalibre;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de los renglones de una recepción por calibre. Las de la cabecera que no dependen del
 * calibre (convenio activo, fecha, recepción editable) viven en ReglasRecepcion, que delega aquí
 * las de tipo de carga, cantidad, kilos y rezaga. Todo es estático y junta TODOS los errores
 * con la clave `renglones.N.campo`, igual que el formulario las pinta.
 */
class ReglasRenglonesRecepcion
{
    public const MENSAJE_SIN_RENGLONES = 'Agrega al menos un calibre.';

    public const MENSAJE_PRECIO = 'Este calibre no tiene precio vigente para ese tipo de carga en esa fecha.';

    public const MENSAJE_SUMA = 'La suma de los renglones es demasiado grande.';

    /** Tope de las columnas decimal(14,2) de la cabecera (cantidad, kilos, rezaga). */
    private const MAXIMO_CABECERA = 999999999999.99;

    /**
     * @param  array{fecha: string, tipo_carga_id: int|string, renglones?: array<int, array<string, mixed>>}  $datos
     */
    public static function validar(Convenio $convenio, array $datos, ?PrecioVigente $precios = null): void
    {
        $errores = self::errores($convenio, $datos, $precios);

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Los errores sin lanzar (ReglasRecepcion los mezcla con los de la cabecera). Con
     * `$fechaValida` falso no se revisan precios: la fecha ya está mal y solo estorbarían.
     *
     * @return array<string, string>
     */
    public static function errores(Convenio $convenio, array $datos, ?PrecioVigente $precios = null, bool $fechaValida = true): array
    {
        $renglones = array_values($datos['renglones'] ?? []);
        if ($renglones === []) {
            return ['renglones' => self::MENSAJE_SIN_RENGLONES];
        }

        $pactados = $convenio->calibres->pluck('calibre_id')->map(fn ($id) => (int) $id)->all();
        $precios ??= app(PrecioVigente::class);
        $fecha = (string) $datos['fecha'];
        $tipo = (int) $datos['tipo_carga_id'];
        $hayKilosEnAlguno = collect($renglones)->contains(fn ($r) => self::trae($r['kilos'] ?? null));
        $vistos = [];
        $errores = [];

        foreach ($renglones as $i => $r) {
            $clave = "renglones.{$i}";
            $calibre = isset($r['calibre_id']) && is_numeric($r['calibre_id']) ? (int) $r['calibre_id'] : null;

            if ($calibre === null || ! in_array($calibre, $pactados, true)) {
                $errores["{$clave}.calibre_id"] = 'El calibre no es de este convenio.';
            } elseif (isset($vistos[$calibre])) {
                $errores["{$clave}.calibre_id"] = 'Este calibre ya está en la recepción.';
            } elseif ($fechaValida && $precios->para($convenio, $tipo, $fecha, $calibre) === null) {
                $errores["{$clave}.calibre_id"] = self::MENSAJE_PRECIO;
            }
            if ($calibre !== null) {
                $vistos[$calibre] = true;
            }

            $cantidad = $r['cantidad'] ?? null;
            if (! is_numeric($cantidad) || (float) $cantidad <= 0 || (float) $cantidad != floor((float) $cantidad)) {
                $errores["{$clave}.cantidad"] = 'La cantidad debe ser un número entero mayor a cero.';
            }

            self::kilosYRezaga($convenio, $r, $hayKilosEnAlguno, $clave, $errores);
        }

        // La cabecera es decimal(14,2): la suma de los renglones no debe desbordarla.
        $suma = fn (string $campo) => array_sum(array_map(fn ($r) => is_numeric($r[$campo] ?? null) ? abs((float) $r[$campo]) : 0.0, $renglones));
        if ($suma('cantidad') > self::MAXIMO_CABECERA || $suma('kilos') > self::MAXIMO_CABECERA || $suma('rezaga_kg') > self::MAXIMO_CABECERA) {
            $errores['renglones'] = self::MENSAJE_SUMA;
        }

        return $errores;
    }

    /**
     * Totales de la cabecera: la suma de los renglones. Kilos y rezaga quedan nulos si
     * ningún renglón los trae (una recepción solo en piezas).
     *
     * @return array{cantidad: float, kilos: ?float, rezaga_kg: ?float}
     */
    public static function totalesCabecera(array $renglones): array
    {
        $suma = function (string $campo) use ($renglones): ?float {
            $con = array_filter($renglones, fn ($r) => self::trae($r[$campo] ?? null));

            return $con === [] ? null : round(array_sum(array_map(fn ($r) => (float) $r[$campo], $con)), 2);
        };

        return [
            'cantidad' => (float) array_sum(array_map(fn ($r) => (float) ($r['cantidad'] ?? 0), $renglones)),
            'kilos' => $suma('kilos'),
            'rezaga_kg' => $suma('rezaga_kg'),
        ];
    }

    /**
     * Importe estimado de una recepción por calibre: Σ de cada renglón (kilos si el convenio se
     * paga por kilo, si no las piezas) × precio vigente de (tipo, calibre, fecha) × % del
     * productor ÷ 100, cada renglón a centavos. Nulo si algún renglón no tiene precio.
     */
    public static function importe(Convenio $convenio, Recepcion $recepcion, ?PrecioVigente $precios = null): ?float
    {
        // Una cabecera sin renglones en un convenio por calibre no se valora (nunca se muestra como $0).
        if ($recepcion->renglones->isEmpty()) {
            return null;
        }

        $precios ??= app(PrecioVigente::class);
        $fecha = $recepcion->fecha->format('Y-m-d');
        $total = 0.0;

        foreach ($recepcion->renglones as $renglon) {
            $importe = self::importeRenglon(
                $convenio,
                $renglon,
                $precios->para($convenio, (int) $recepcion->tipo_carga_id, $fecha, (int) $renglon->calibre_id),
            );
            if ($importe === null) {
                return null;
            }
            $total += $importe;
        }

        return round($total, 2);
    }

    /**
     * Importe de UN renglón con su precio vigente ya resuelto: kilos si el convenio se paga por kilo
     * (si no, las piezas) × precio × % del productor ÷ 100 (100 % sin porcentaje), a centavos.
     * Nulo sin precio. Única implementación de la fórmula (la usan `importe` y la presentación).
     */
    public static function importeRenglon(Convenio $convenio, RecepcionCalibre $renglon, ?ConvenioPrecio $precio): ?float
    {
        if ($precio === null) {
            return null;
        }

        $cantidad = (float) ($convenio->unidad === 'kg' ? $renglon->kilos : $renglon->cantidad);
        $porcentaje = $precio->porcentaje_productor === null ? 100.0 : (float) $precio->porcentaje_productor;

        return round($cantidad * (float) $precio->precio * $porcentaje / 100, 2);
    }

    /** @param  array<string, string>  $errores */
    private static function kilosYRezaga(Convenio $convenio, array $r, bool $hayKilosEnAlguno, string $clave, array &$errores): void
    {
        $kilos = $r['kilos'] ?? null;
        $rezaga = $r['rezaga_kg'] ?? null;
        $hayKilos = self::trae($kilos);
        $hayRezaga = self::trae($rezaga);

        if ($hayKilos) {
            if (! is_numeric($kilos) || ! self::dosDecimales((float) $kilos)) {
                $errores["{$clave}.kilos"] = 'Los kilos aceptan hasta 2 decimales.';
            } elseif ((float) $kilos <= 0 && ($convenio->unidad === 'kg' || $hayRezaga)) {
                $errores["{$clave}.kilos"] = 'Los kilos deben ser mayores a cero.';
            } elseif ((float) $kilos < 0) {
                $errores["{$clave}.kilos"] = 'Los kilos no pueden ser negativos.';
            }
        } elseif ($convenio->unidad === 'kg' || $hayRezaga) {
            $errores["{$clave}.kilos"] = 'Indica los kilos de báscula.';
        } elseif ($hayKilosEnAlguno) {
            $errores["{$clave}.kilos"] = 'Captura los kilos de todos los calibres o de ninguno.';
        }

        if ($hayRezaga) {
            if (! is_numeric($rezaga) || ! self::dosDecimales((float) $rezaga)) {
                $errores["{$clave}.rezaga_kg"] = 'La rezaga acepta hasta 2 decimales.';
            } elseif ((float) $rezaga < 0) {
                $errores["{$clave}.rezaga_kg"] = 'La rezaga no puede ser negativa.';
            } elseif ($hayKilos && is_numeric($kilos) && (float) $rezaga > (float) $kilos) {
                $errores["{$clave}.rezaga_kg"] = 'La rezaga no puede superar los kilos.';
            }
        }
    }

    private static function trae(mixed $valor): bool
    {
        return $valor !== null && $valor !== '';
    }

    private static function dosDecimales(float $valor): bool
    {
        return round($valor, 2) == $valor;
    }
}
