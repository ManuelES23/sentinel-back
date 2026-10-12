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
 * (formato móvil antiguo de México) → 52 + los últimos 10.
 */
final class TelefonoWhatsApp
{
    /** País por defecto (México). No es configurable por empresa en Fase 0. */
    public const PAIS_DEFECTO = '52';

    public static function normalizar(?string $telefono): ?string
    {
        $texto = trim((string) $telefono);
        if ($texto === '') {
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

    private static function internacional(string $digitos): ?string
    {
        $largo = strlen($digitos);
        if ($largo < 8 || $largo > 15) {
            return null;
        }

        // +52 1 XXXXXXXXXX: el "1" de los móviles mexicanos ya no se marca.
        if ($largo === 13 && str_starts_with($digitos, self::PAIS_DEFECTO.'1')) {
            return self::PAIS_DEFECTO.substr($digitos, 3);
        }

        return $digitos;
    }
}
