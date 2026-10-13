<?php

namespace App\Support\CRM;

/**
 * Normaliza un teléfono capturado como texto libre (string(50)) al formato
 * de https://wa.me/: solo dígitos con código de país. Devuelve null si no es
 * utilizable; nunca adivina (una extensión o letras dejan el número en null).
 * Spec 2026-10-12 §3.
 *
 * Reglas: se quitan espacios, guiones, paréntesis y puntos. Con `+` o `00`
 * es internacional (8 a 15 dígitos). Si no: 10 dígitos → 52 + número;
 * 12 dígitos que empiezan con 52 → tal cual; 13 que empiezan con 521
 * (formato móvil antiguo de México) → 52 + los últimos 10. Antes se quitan
 * los caracteres invisibles de formato; un código de país que empieza con 0
 * nunca es válido.
 */
final class TelefonoWhatsApp
{
    /** País por defecto (México). No es configurable por empresa en Fase 0. */
    public const PAIS_DEFECTO = '52';

    public static function normalizar(?string $telefono): ?string
    {
        $texto = self::limpiarFormato((string) $telefono);
        if ($texto === null || $texto === '') {
            return null;
        }

        // Solo dígitos y separadores comunes; un "+" solo al inicio.
        if (preg_match('/[^\d\s\-().+]/', $texto) === 1) {
            return null;
        }
        if (substr_count($texto, '+') > 1 || (str_contains($texto, '+') && ! str_starts_with($texto, '+'))) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $texto);
        if ($digitos === '') {
            return null;
        }

        if (str_starts_with($texto, '+')) {
            return self::internacional($digitos);
        }
        if (str_starts_with($digitos, '00')) {
            return self::internacional(substr($digitos, 2));
        }

        $largo = strlen($digitos);
        if ($largo === 10) {
            return self::PAIS_DEFECTO.$digitos;
        }
        if ($largo === 12 && str_starts_with($digitos, self::PAIS_DEFECTO)) {
            return $digitos;
        }
        if ($largo === 13 && str_starts_with($digitos, self::PAIS_DEFECTO.'1')) {
            return self::PAIS_DEFECTO.substr($digitos, 3);
        }

        return null;
    }

    /**
     * Quita los caracteres invisibles de formato (\p{Cf}: marcas de dirección
     * U+202A/U+202C, BOM, etc.) que llegan al copiar y pegar, y convierte el
     * espacio duro (U+00A0) en espacio y los guiones tipográficos
     * (U+2010 a U+2015) en guion. Devuelve null si el texto no es UTF-8 válido.
     */
    private static function limpiarFormato(string $texto): ?string
    {
        $limpio = preg_replace(
            ['/\p{Cf}+/u', '/\x{00A0}/u', '/[\x{2010}-\x{2015}]/u'],
            ['', ' ', '-'],
            $texto,
        );

        return $limpio === null ? null : trim($limpio);
    }

    private static function internacional(string $digitos): ?string
    {
        $largo = strlen($digitos);
        // Ningún código de país empieza con 0 (p. ej. "+0052" o "000052").
        if ($largo < 8 || $largo > 15 || str_starts_with($digitos, '0')) {
            return null;
        }

        // +52 1 XXXXXXXXXX: el "1" de los móviles mexicanos ya no se marca.
        if ($largo === 13 && str_starts_with($digitos, self::PAIS_DEFECTO.'1')) {
            return self::PAIS_DEFECTO.substr($digitos, 3);
        }

        return $digitos;
    }
}
