<?php

namespace App\Services\RH;

use App\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Identifica a un empleado por número + PIN (portal de vacaciones y
 * emparejamiento personal del checador; el PIN es el mismo). Tras 5 errores
 * seguidos bloquea al empleado 15 minutos. Hacia afuera todas las fallas se
 * ven igual: devuelve null y siempre cuesta un Hash::check, para que no se
 * pueda saber si un número existe, está inactivo o está bloqueado.
 */
class VerificadorPinEmpleado
{
    public const MAX_INTENTOS = 5;

    public const MINUTOS_BLOQUEO = 15;

    public const MENSAJE_ERROR = 'Número de empleado o PIN incorrectos.';

    private static ?string $hashFicticio = null;

    public function verificar(string $numeroEmpleado, string $pin): ?Employee
    {
        $numero = self::normalizarNumero($numeroEmpleado);
        $empleado = $numero === '' ? null : Employee::where('employee_number', $numero)->first();

        $valido = $empleado
            && $empleado->status === Employee::STATUS_ACTIVE
            && $empleado->pin
            && Hash::isHashed($empleado->pin)
            && ! $this->estaBloqueado($empleado);

        if (! $valido) {
            Hash::check($pin, self::$hashFicticio ??= Hash::make(Str::random(32)));

            return null;
        }

        if (! Hash::check($pin, $empleado->pin)) {
            $this->registrarFallo($empleado);

            return null;
        }

        if ($empleado->pin_failed_attempts > 0 || $empleado->pin_locked_until !== null) {
            $this->desbloquear($empleado);
        }

        return $empleado;
    }

    public function desbloquear(Employee $empleado): void
    {
        Employee::whereKey($empleado->id)->update(['pin_failed_attempts' => 0, 'pin_locked_until' => null]);
        $empleado->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->syncOriginal();
    }

    public function estaBloqueado(Employee $empleado): bool
    {
        return $empleado->pin_locked_until !== null && $empleado->pin_locked_until->isFuture();
    }

    public static function normalizarNumero(string $numero): string
    {
        return strtoupper(preg_replace('/\s+/u', '', $numero) ?? '');
    }

    /** Con el query builder para no disparar Loggable en cada intento. */
    private function registrarFallo(Employee $empleado): void
    {
        Employee::whereKey($empleado->id)->increment('pin_failed_attempts');
        $intentos = (int) Employee::whereKey($empleado->id)->value('pin_failed_attempts');

        if ($intentos >= self::MAX_INTENTOS) {
            Employee::whereKey($empleado->id)->update([
                'pin_failed_attempts' => 0,
                'pin_locked_until' => now()->addMinutes(self::MINUTOS_BLOQUEO),
            ]);
        }
    }
}
