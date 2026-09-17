<?php

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Política de contraseñas configurada en /admin/settings.
 * Se registra como Password::defaults() en AppServiceProvider.
 */
class PasswordPolicy
{
    public static function toArray(): array
    {
        $s = app(SettingsService::class);

        return [
            'min_length' => (int) $s->get('password.min_length'),
            'require_mixed_case' => (bool) $s->get('password.require_mixed_case'),
            'require_numbers' => (bool) $s->get('password.require_numbers'),
            'require_symbols' => (bool) $s->get('password.require_symbols'),
        ];
    }

    public static function rule(): Password
    {
        $p = self::toArray();
        $rule = Password::min($p['min_length']);

        if ($p['require_mixed_case']) {
            $rule->mixedCase();
        }
        if ($p['require_numbers']) {
            $rule->numbers();
        }
        if ($p['require_symbols']) {
            $rule->symbols();
        }

        return $rule;
    }

    /** Mensajes en español (la app usa APP_LOCALE=en y no tiene lang/). */
    public static function messages(string $campo = 'password'): array
    {
        return [
            "{$campo}.min" => 'La contraseña debe tener al menos :min caracteres.',
            'password.mixed' => 'La contraseña debe incluir al menos una mayúscula y una minúscula.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
            'password.symbols' => 'La contraseña debe incluir al menos un símbolo.',
        ];
    }

    /**
     * Contraseña temporal que cumple la política. Sin símbolos salvo que se
     * exijan (suele dictarse o copiarse a mano). Str::password solo garantiza
     * una letra, así que se valida y se reintenta.
     */
    public static function generate(): string
    {
        $p = self::toArray();
        $longitud = max(12, $p['min_length']);

        for ($intento = 0; $intento < 50; $intento++) {
            $candidata = Str::password($longitud, symbols: $p['require_symbols']);
            if (Validator::make(['password' => $candidata], ['password' => self::rule()])->passes()) {
                return $candidata;
            }
        }

        throw new \RuntimeException('No se pudo generar una contraseña que cumpla la política.');
    }
}
