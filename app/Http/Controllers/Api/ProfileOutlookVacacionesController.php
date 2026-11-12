<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\CRM\OutlookIntegracionController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VacationOutlookConnection;
use App\Services\RH\OutlookVacaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Mi Perfil: vincular, sincronizar y desvincular el Outlook de quien autoriza vacaciones.
 *
 * Reutiliza el flujo OAuth del CRM (mismo Azure, mismo redirect, nonce propio en Cache porque el front
 * autentica con Bearer y la vuelta de Microsoft no lo trae). El callback es el público del CRM
 * (OutlookIntegracionController::callback), que reparte por `proposito` del nonce y llama a
 * completarVinculo(). Los tokens jamás salen en el JSON.
 */
class ProfileOutlookVacacionesController extends Controller
{
    public const PROPOSITO = 'vacaciones';

    public function __construct(private readonly OutlookVacaciones $outlook) {}

    /** GET /api/profile/outlook-vacaciones */
    public function estado(Request $request): JsonResponse
    {
        return $this->respuestaEstado($request->user());
    }

    /** GET /api/profile/outlook-vacaciones/conectar */
    public function conectar(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if (! $this->outlook->disponible($usuario)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo quien autoriza vacaciones como usuario específico puede vincular Outlook aquí.',
                'data' => null,
            ], 403);
        }

        $nonce = Str::random(40);
        Cache::put(OutlookIntegracionController::CACHE_PREFIX.$nonce, [
            'user_id' => $usuario->id,
            'proposito' => self::PROPOSITO,
        ], now()->addMinutes(OutlookIntegracionController::NONCE_TTL_MINUTOS));

        $url = Socialite::driver('microsoft')
            ->stateless()
            ->scopes(OutlookIntegracionController::SCOPES)
            ->with(['state' => $nonce])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['success' => true, 'message' => 'Operación exitosa', 'data' => ['url' => $url]]);
    }

    /** POST /api/profile/outlook-vacaciones/sincronizar */
    public function sincronizar(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $conexion = VacationOutlookConnection::where('user_id', $usuario->id)->first();

        if (! $conexion) {
            return response()->json(['success' => false, 'message' => 'No tienes Outlook vinculado.', 'data' => null], 404);
        }

        $this->outlook->sincronizar($conexion);

        return $this->respuestaEstado($usuario);
    }

    /** DELETE /api/profile/outlook-vacaciones */
    public function desconectar(Request $request): JsonResponse
    {
        $conexion = VacationOutlookConnection::where('user_id', $request->user()->id)->first();

        if ($conexion) {
            $this->outlook->desvincular($conexion);
        }

        return response()->json(['success' => true, 'message' => 'Outlook desvinculado.', 'data' => null]);
    }

    /**
     * Termina la vinculación desde el callback público del CRM (el nonce ya se consumió y trae
     * `{user_id, proposito: 'vacaciones'}`). Siempre redirige al perfil con `outlook=ok|error`.
     */
    public function completarVinculo(array $contexto): RedirectResponse
    {
        $usuario = User::find($contexto['user_id'] ?? null);

        // Pudo dejar de ser «usuario específico» entre conectar y volver de Microsoft.
        if (! $usuario || ! $this->outlook->disponible($usuario)) {
            return $this->redirigir('error');
        }

        try {
            $microsoft = Socialite::driver('microsoft')->stateless()->user();
        } catch (\Throwable) {
            return $this->redirigir('error');
        }

        try {
            $conexion = $this->outlook->vincular(
                $usuario->id,
                (string) $microsoft->getEmail(),
                (string) $microsoft->token,
                $microsoft->refreshToken,
                (int) ($microsoft->expiresIn ?? 3600),
            );
        } catch (\Throwable $e) {
            Log::error("No se pudo vincular Outlook (vacaciones) del usuario #{$usuario->id} (".get_class($e).").");

            return $this->redirigir('error');
        }

        // Primera sincronización: agenda lo que ya aprobó, fuera de la respuesta.
        defer(function () use ($conexion) {
            try {
                app(OutlookVacaciones::class)->sincronizar($conexion);
            } catch (\Throwable $e) {
                Log::warning("No se pudo sincronizar Outlook (vacaciones) de la conexión #{$conexion->id} tras vincular (".get_class($e).").");
            }
        });

        return $this->redirigir('ok');
    }

    private function redirigir(string $resultado): RedirectResponse
    {
        $frontendUrl = rtrim(config('services.microsoft.frontend_url'), '/');

        return redirect()->away("{$frontendUrl}/profile?tab=approvals&outlook={$resultado}");
    }

    private function respuestaEstado(User $usuario): JsonResponse
    {
        $conexion = VacationOutlookConnection::where('user_id', $usuario->id)->first();

        return response()->json([
            'success' => true,
            'message' => 'Operación exitosa',
            'data' => [
                'disponible' => $this->outlook->disponible($usuario),
                'conectado' => (bool) $conexion,
                'email' => $conexion?->email_outlook,
                'ultimo_sync' => $conexion?->ultimo_sync_at?->toIso8601String(),
                'ultimo_error' => $conexion?->ultimo_error,
                // Solo ids y fecha: ni el id del evento de Outlook ni los tokens salen de aquí.
                'agendadas' => $conexion
                    ? $conexion->eventos()->orderBy('vacation_request_id')->get(['vacation_request_id', 'enviado_at'])
                        ->map(fn ($m) => [
                            'vacation_request_id' => $m->vacation_request_id,
                            'enviado_at' => $m->enviado_at?->toIso8601String(),
                        ])->values()->all()
                    : [],
            ],
        ]);
    }
}
