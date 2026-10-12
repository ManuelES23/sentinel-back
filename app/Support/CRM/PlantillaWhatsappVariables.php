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

    private const PATRON = '/\{\{\s*([^{}]*?)\s*\}\}/u';

    /** @return string[] nombres usados, en orden de aparición y sin repetir */
    public static function usadas(string $cuerpo): array
    {
        preg_match_all(self::PATRON, $cuerpo, $coincidencias);

        return array_values(array_unique($coincidencias[1]));
    }

    /** @return string[] usadas que no están en VARIABLES */
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
     */
    public static function sustituir(string $cuerpo, array $valores): array
    {
        $faltantes = [];

        $mensaje = preg_replace_callback(self::PATRON, function (array $coincidencia) use ($valores, &$faltantes) {
            $valor = $valores[$coincidencia[1]] ?? null;
            if ($valor === null || trim($valor) === '') {
                $faltantes[] = $coincidencia[1];

                return '';
            }

            return $valor;
        }, $cuerpo);

        return ['mensaje' => $mensaje, 'variables_faltantes' => array_values(array_unique($faltantes))];
    }
}
