<?php

namespace App\Support\CRM;

/**
 * Variables de las plantillas de WhatsApp (spec 2026-10-12 D8): llaves dobles
 * y nombres en español. El conjunto es cerrado: una variable fuera de él no
 * se puede guardar (422). Se aceptan espacios dentro de las llaves
 * (`{{ nombre }}`); las mayúsculas cuentan (`{{Nombre}}` no existe).
 */
final class PlantillaWhatsappVariables
{
    public const VARIABLES = ['nombre', 'entidad', 'vendedor', 'empresa', 'oportunidad', 'monto', 'folio', 'total'];

    /** Mensaje de error para un texto que no es UTF-8 válido. */
    public const MENSAJE_TEXTO_INVALIDO = 'El texto contiene caracteres no válidos. Vuelve a escribirlo o pégalo como texto simple.';

    private const PATRON = '/\{\{\s*([^{}]*?)\s*\}\}/u';

    /** true si el texto es UTF-8 válido (las expresiones /u fallan si no lo es). */
    public static function esTextoValido(string $texto): bool
    {
        return mb_check_encoding($texto, 'UTF-8');
    }

    /**
     * @return string[] nombres usados, en orden de aparición y sin repetir
     *
     * @throws \InvalidArgumentException si el cuerpo no es UTF-8 válido
     */
    public static function usadas(string $cuerpo): array
    {
        if (preg_match_all(self::PATRON, $cuerpo, $coincidencias) === false) {
            throw new \InvalidArgumentException(self::MENSAJE_TEXTO_INVALIDO);
        }

        return array_values(array_unique($coincidencias[1]));
    }

    /**
     * @return string[] usadas que no están en VARIABLES
     *
     * @throws \InvalidArgumentException si el cuerpo no es UTF-8 válido
     */
    public static function desconocidas(string $cuerpo): array
    {
        return array_values(array_diff(self::usadas($cuerpo), self::VARIABLES));
    }

    /** @param  string[]  $desconocidas */
    public static function mensajeDesconocidas(array $desconocidas): string
    {
        $formato = fn (string $variable) => '{{'.$variable.'}}';
        $lista = implode(', ', array_map($formato, $desconocidas));
        $validas = implode(', ', array_map($formato, self::VARIABLES));

        return count($desconocidas) === 1
            ? "La variable {$lista} no existe. Usa solo: {$validas}."
            : "Las variables {$lista} no existen. Usa solo: {$validas}.";
    }

    /**
     * Sustituye cada variable por su valor. Una sin dato (null o en blanco)
     * queda como cadena vacía y se reporta en `variables_faltantes`.
     *
     * @param  array<string, string|null>  $valores
     * @return array{mensaje: string, variables_faltantes: string[]}
     *
     * @throws \InvalidArgumentException si el cuerpo no es UTF-8 válido (nunca devuelve mensaje null)
     */
    public static function sustituir(string $cuerpo, array $valores): array
    {
        if (! self::esTextoValido($cuerpo)) {
            throw new \InvalidArgumentException(self::MENSAJE_TEXTO_INVALIDO);
        }

        $faltantes = [];

        $mensaje = preg_replace_callback(self::PATRON, function (array $coincidencia) use ($valores, &$faltantes) {
            $valor = $valores[$coincidencia[1]] ?? null;
            if ($valor === null || trim($valor) === '') {
                $faltantes[] = $coincidencia[1];

                return '';
            }

            return $valor;
        }, $cuerpo);

        if ($mensaje === null) {
            throw new \InvalidArgumentException(self::MENSAJE_TEXTO_INVALIDO);
        }

        return ['mensaje' => $mensaje, 'variables_faltantes' => array_values(array_unique($faltantes))];
    }
}
