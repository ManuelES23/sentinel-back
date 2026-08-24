<?php
// app/Http/Controllers/Api/CRM/OutlookIntegracionController.php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmOutlookConexion;
use App\Models\CRM\CrmVendedor;
use App\Models\Enterprise;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Conectar/desconectar la cuenta de Outlook de un vendedor para la
 * sincronización unidireccional Agenda -> Outlook (el trabajo real de push
 * lo hace SincronizarOutlookCommand -- este controlador solo administra la
 * conexión).
 *
 * A diferencia del resto del CRM, aquí no existe "actuar sobre el vendedor
 * de otro": cada usuario solo conecta su propia cuenta, así que los 4
 * endpoints solo exigen el permiso 'ver' del submódulo integraciones/outlook.
 *
 * Nota de arquitectura -- por qué un nonce propio y no el state/sesión de
 * Socialite: el frontend autentica con un Bearer token guardado en
 * localStorage (no hay cookie de sesión), así que una navegación de página
 * completa hacia Microsoft y de regreso NUNCA lleva el header Authorization.
 * Por eso:
 *   1. conectar() SÍ es un endpoint autenticado normal (llamado por
 *      fetchAPI con el Bearer token) que solo devuelve la URL de
 *      consentimiento como JSON -- la navegación real la hace el frontend
 *      con window.location.href.
 *   2. Esa URL lleva como parámetro `state` un nonce propio (no el de
 *      Socialite), generado aquí y guardado en Cache junto con el
 *      user_id/empresa_id, con TTL corto.
 *   3. callback() es una ruta PÚBLICA (routes/api.php, fuera de
 *      auth:sanctum) porque Microsoft redirige sin ningún Bearer token.
 *      Resuelve la identidad leyendo el nonce del Cache, nunca de
 *      Auth::user().
 */
class OutlookIntegracionController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const CACHE_PREFIX = 'outlook_connect_nonce:';
    private const NONCE_TTL_MINUTOS = 10;

    private const SCOPES = [
        'openid', 'profile', 'email', 'offline_access',
        'https://graph.microsoft.com/Calendars.ReadWrite',
    ];

    /** GET /crm/integraciones/outlook/estado */
    public function estado(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver'),
            403,
            'No tienes permiso para ver la integración con Outlook.',
        );

        $conexion = $this->conexionDelUsuarioActual($empresaId);

        return $this->jsonSuccess([
            'conectado' => (bool) $conexion,
            'email' => $conexion?->email_outlook,
            'ultimoSync' => $conexion?->ultimo_sync_at?->toIso8601String(),
            'ultimoError' => $conexion?->ultimo_error,
        ]);
    }

    /** GET /crm/integraciones/outlook/conectar */
    public function conectar(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver'),
            403,
            'No tienes permiso para conectar Outlook.',
        );

        $vendedor = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->first();

        abort_unless($vendedor, 422, 'No tienes un perfil de vendedor en esta empresa; no hay nada que conectar.');

        $nonce = Str::random(40);
        Cache::put(self::CACHE_PREFIX.$nonce, [
            'user_id' => Auth::id(),
            'empresa_id' => $empresaId,
        ], now()->addMinutes(self::NONCE_TTL_MINUTOS));

        $url = Socialite::driver('microsoft')
            ->stateless()
            ->scopes(self::SCOPES)
            ->with(['state' => $nonce])
            ->redirect()
            ->getTargetUrl();

        return $this->jsonSuccess(['url' => $url]);
    }

    /** GET /crm/integraciones/outlook/callback -- ruta pública, ver routes/api.php */
    public function callback(Request $request): RedirectResponse
    {
        $nonce = $request->query('state');
        $contexto = $nonce ? Cache::pull(self::CACHE_PREFIX.$nonce) : null;

        if (! $contexto) {
            return $this->redirigirAlFrontend(null, 'error');
        }

        try {
            $microsoftUser = Socialite::driver('microsoft')->stateless()->user();
        } catch (\Throwable) {
            return $this->redirigirAlFrontend($contexto['empresa_id'] ?? null, 'error');
        }

        $vendedor = CrmVendedor::where('empresa_id', $contexto['empresa_id'])
            ->where('user_id', $contexto['user_id'])
            ->first();

        if (! $vendedor) {
            return $this->redirigirAlFrontend($contexto['empresa_id'], 'error');
        }

        CrmOutlookConexion::updateOrCreate(
            ['crm_vendedor_id' => $vendedor->id],
            [
                'empresa_id' => $contexto['empresa_id'],
                'email_outlook' => $microsoftUser->getEmail(),
                'access_token' => $microsoftUser->token,
                'refresh_token' => $microsoftUser->refreshToken,
                'token_expires_at' => now()->addSeconds($microsoftUser->expiresIn ?? 3600),
            ],
        );

        return $this->redirigirAlFrontend($contexto['empresa_id'], 'ok');
    }

    /** DELETE /crm/integraciones/outlook/desconectar */
    public function desconectar(): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');
        abort_unless(
            $this->tienePermisoSubmodulo($empresaId, 'integraciones', 'outlook', 'ver'),
            403,
            'No tienes permiso para desconectar Outlook.',
        );

        $conexion = $this->conexionDelUsuarioActual($empresaId);
        $conexion?->delete();

        return $this->jsonSuccess(null, 'Cuenta de Outlook desconectada.');
    }

    private function conexionDelUsuarioActual(int $empresaId): ?CrmOutlookConexion
    {
        $vendedor = CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $vendedor) {
            return null;
        }

        return CrmOutlookConexion::where('crm_vendedor_id', $vendedor->id)->first();
    }

    private function redirigirAlFrontend(?int $empresaId, string $resultado): RedirectResponse
    {
        $frontendUrl = rtrim(config('services.microsoft.frontend_url'), '/');
        $enterpriseSlug = $empresaId ? Enterprise::where('id', $empresaId)->value('slug') : null;

        if (! $enterpriseSlug) {
            return redirect()->away("{$frontendUrl}/inicio?outlook={$resultado}");
        }

        return redirect()->away("{$frontendUrl}/{$enterpriseSlug}/crm/integraciones/outlook?outlook={$resultado}");
    }
}
