<?php

namespace App\Support\CRM;

use Illuminate\Support\Str;

/** Normalización de nombres del catálogo (marca, línea, sublínea, clave). */
class NombreCatalogo
{
    /** Texto recortado con los espacios repetidos colapsados; es lo que se guarda. */
    public static function limpio(?string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $texto));
    }

    /** Forma de comparación: limpio, sin acentos y en minúsculas. */
    public static function clave(?string $texto): string
    {
        return mb_strtolower(Str::ascii(self::limpio($texto)));
    }
}
