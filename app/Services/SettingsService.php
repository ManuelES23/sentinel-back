<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Ajustes del sistema editables desde /admin/settings.
 *
 * Única fuente de claves, valores por defecto y reglas. La tabla solo guarda
 * lo que el admin cambió; todo lo demás sale de DEFINITIONS.
 */
class SettingsService
{
    public const CACHE_KEY = 'system_settings';

    public const DEFINITIONS = [
        'session.idle_minutes' => ['default' => 120, 'rules' => ['integer', 'min:5', 'max:1440']],
        'password.min_length' => ['default' => 8, 'rules' => ['integer', 'min:8', 'max:64']],
        'password.require_mixed_case' => ['default' => false, 'rules' => ['boolean']],
        'password.require_numbers' => ['default' => false, 'rules' => ['boolean']],
        'password.require_symbols' => ['default' => false, 'rules' => ['boolean']],
        'mail.enabled' => ['default' => false, 'rules' => ['boolean']],
        'mail.host' => ['default' => '', 'rules' => ['nullable', 'string', 'max:255']],
        'mail.port' => ['default' => 587, 'rules' => ['integer', 'min:1', 'max:65535']],
        'mail.encryption' => ['default' => 'tls', 'rules' => ['string', 'in:tls,ssl,none']],
        'mail.username' => ['default' => '', 'rules' => ['nullable', 'string', 'max:255']],
        'mail.password' => ['default' => '', 'rules' => ['nullable', 'string', 'max:255'], 'secret' => true],
        'mail.from_address' => ['default' => '', 'rules' => ['nullable', 'email', 'max:255']],
        'mail.from_name' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255']],
    ];

    /** Reglas extra que solo aplican al validar la petición completa. */
    private const REQUEST_EXTRA_RULES = [
        'mail.host' => ['required_if_accepted:mail.enabled'],
        'mail.from_address' => ['required_if_accepted:mail.enabled'],
    ];

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? $this->defaultFor($key);
    }

    public function all(): array
    {
        // Sin memoria en la instancia: un worker de cola la dejaría obsoleta.
        try {
            $guardados = Cache::rememberForever(self::CACHE_KEY, fn () => SystemSetting::pluck('value', 'key')->all());
        } catch (Throwable) {
            // Tabla inexistente (p. ej. durante migrate): solo valores por defecto, sin cachear.
            return $this->defaults();
        }

        $valores = [];
        foreach (self::DEFINITIONS as $key => $definicion) {
            $valores[$key] = array_key_exists($key, $guardados)
                ? $this->decode($key, $guardados[$key])
                : $this->defaultFor($key);
        }

        return $valores;
    }

    /**
     * Guarda las claves conocidas. mail.password vacía o ausente conserva la guardada.
     *
     * @return array{antes: array, despues: array}
     */
    public function update(array $values, ?User $actor = null): array
    {
        $actuales = $this->all();
        $antes = [];
        $despues = [];

        DB::transaction(function () use ($values, $actor, $actuales, &$antes, &$despues) {
            foreach (self::DEFINITIONS as $key => $definicion) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $nuevo = $this->cast($key, $values[$key]);
                $secreto = $definicion['secret'] ?? false;

                if ($secreto && ($nuevo === '' || $nuevo === null)) {
                    continue;
                }
                if ($nuevo === $actuales[$key]) {
                    continue;
                }

                $guardar = $secreto ? Crypt::encryptString($nuevo) : $nuevo;

                SystemSetting::updateOrCreate(
                    ['key' => $key],
                    ['value' => json_encode($guardar), 'updated_by' => $actor?->id],
                );

                $antes[$key] = $secreto ? '***' : $actuales[$key];
                $despues[$key] = $secreto ? '***' : $nuevo;
            }
        });

        $this->flush();

        return ['antes' => $antes, 'despues' => $despues];
    }

    public function forClient(): array
    {
        $datos = [];
        foreach ($this->all() as $key => $valor) {
            [$grupo, $campo] = explode('.', $key, 2);
            if (self::DEFINITIONS[$key]['secret'] ?? false) {
                $datos[$grupo][$campo.'_set'] = $valor !== '';

                continue;
            }
            $datos[$grupo][$campo] = $valor;
        }

        return $datos;
    }

    public static function rulesForRequest(): array
    {
        $reglas = [];
        foreach (self::DEFINITIONS as $key => $definicion) {
            // Sin 'sometimes' en las claves con reglas condicionales: deben
            // evaluarse aunque falten (p. ej. SMTP activo sin servidor).
            $extra = self::REQUEST_EXTRA_RULES[$key] ?? [];
            $reglas[$key] = array_merge($extra === [] ? ['sometimes'] : [], $definicion['rules'], $extra);
        }

        return $reglas;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function defaults(): array
    {
        $valores = [];
        foreach (array_keys(self::DEFINITIONS) as $key) {
            $valores[$key] = $this->defaultFor($key);
        }

        return $valores;
    }

    private function defaultFor(string $key): mixed
    {
        if ($key === 'mail.from_name') {
            return (string) config('app.name');
        }

        return self::DEFINITIONS[$key]['default'] ?? null;
    }

    /** Decodifica y valida un valor guardado; si no sirve, usa el por defecto. */
    private function decode(string $key, ?string $crudo): mixed
    {
        try {
            $valor = json_decode((string) $crudo, true, 512, JSON_THROW_ON_ERROR);
            if (self::DEFINITIONS[$key]['secret'] ?? false) {
                $valor = Crypt::decryptString($valor);
            }
            $valor = $this->cast($key, $valor);

            if (Validator::make(['v' => $valor], ['v' => self::DEFINITIONS[$key]['rules']])->fails()) {
                throw new \UnexpectedValueException('fuera de rango');
            }

            return $valor;
        } catch (Throwable $e) {
            Log::warning("Ajuste del sistema inválido, se usa el valor por defecto: {$key}");

            return $this->defaultFor($key);
        }
    }

    private function cast(string $key, mixed $valor): mixed
    {
        $default = self::DEFINITIONS[$key]['default'];

        return match (true) {
            is_bool($default) => filter_var($valor, FILTER_VALIDATE_BOOLEAN),
            is_int($default) => (int) $valor,
            default => $valor === null ? '' : (string) $valor,
        };
    }
}
