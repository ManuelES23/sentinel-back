<?php

namespace App\Services\RH;

use App\Models\Employee;
use App\Models\VacationPortalSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sesiones del portal de vacaciones (spec §3.3). El token crudo solo existe en
 * la respuesta del login; en la base se guarda su sha256. Caduca a las 2 h o
 * tras 15 min sin uso, y deja de valer si el empleado ya no está activo.
 */
class SesionPortalVacaciones
{
    public const HORAS_MAXIMAS = 2;

    public const MINUTOS_INACTIVIDAD = 15;

    public const DIAS_RETENCION = 7;

    public const LONGITUD_TOKEN = 64;

    /** @return array{token: string, sesion: VacationPortalSession} */
    public function iniciar(Employee $empleado, Request $request): array
    {
        $token = Str::random(self::LONGITUD_TOKEN);

        $sesion = VacationPortalSession::create([
            'employee_id' => $empleado->id,
            'token_hash' => self::hashDe($token),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(self::HORAS_MAXIMAS),
        ]);

        return ['token' => $token, 'sesion' => $sesion];
    }

    /** Sesión vigente del token, con su empleado, marcada como usada; null si no vale. */
    public function validar(?string $token): ?VacationPortalSession
    {
        if ($token === null || strlen($token) !== self::LONGITUD_TOKEN) {
            return null;
        }

        $sesion = VacationPortalSession::with('employee')->where('token_hash', self::hashDe($token))->first();
        if (! $sesion || ! $this->vigente($sesion)) {
            return null;
        }

        $sesion->updateQuietly(['last_activity_at' => now()]);

        return $sesion;
    }

    public function revocar(VacationPortalSession $sesion): void
    {
        $sesion->updateQuietly(['revoked_at' => now()]);
    }

    /** Al regenerar el PIN o dar de baja/borrar al empleado. Devuelve cuántas revocó. */
    public function revocarTodas(int $empleadoId): int
    {
        return VacationPortalSession::where('employee_id', $empleadoId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** Borra las sesiones vencidas o revocadas hace más de DIAS_RETENCION días. */
    public function limpiar(): int
    {
        $limite = now()->subDays(self::DIAS_RETENCION);

        return VacationPortalSession::where('expires_at', '<', $limite)
            ->orWhere('revoked_at', '<', $limite)
            ->delete();
    }

    public static function hashDe(string $token): string
    {
        return hash('sha256', $token);
    }

    private function vigente(VacationPortalSession $sesion): bool
    {
        return $sesion->revoked_at === null
            && $sesion->expires_at->isFuture()
            && $sesion->last_activity_at->gte(now()->subMinutes(self::MINUTOS_INACTIVIDAD))
            && $sesion->employee !== null
            && $sesion->employee->status === Employee::STATUS_ACTIVE;
    }
}
