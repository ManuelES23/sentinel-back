<?php

namespace App\Providers;

use App\Services\MailSettings;
use App\Services\SettingsService;
use App\Support\PasswordPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftSocialiteProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\SettingsService::class);
        $this->app->singleton(\App\Services\MailSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // socialiteproviders/microsoft no se auto-registra: hay que
        // enganchar el driver 'microsoft' al evento que dispara Socialite
        // la primera vez que se le pide un driver que no conoce nativamente.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', MicrosoftSocialiteProvider::class);
        });

        // Sesión por inactividad: Sanctum llama a este callback antes de
        // actualizar last_used_at, así que ve el último uso real del token.
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $ultimoUso = $token->last_used_at ?? $token->created_at;
            $limite = (int) app(SettingsService::class)->get('session.idle_minutes');

            return $ultimoUso !== null && $ultimoUso->gt(now()->subMinutes($limite));
        });

        // Política de contraseñas configurada en /admin/settings (Password::defaults()
        // se aplica automáticamente a cualquier regla 'password' o Password::defaults()).
        Password::defaults(fn () => PasswordPolicy::rule());

        // Límite fijo de intentos de login (no configurable): 5/min por
        // correo+IP y 20/min por IP.
        RateLimiter::for('login', function (Request $request) {
            $respuesta = fn (Request $request, array $headers) => response()->json([
                'status' => 'error',
                'message' => 'Demasiados intentos. Intenta de nuevo en '.($headers['Retry-After'] ?? 60).' segundos.',
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);

            return [
                Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip())->response($respuesta),
                Limit::perMinute(20)->by('ip:'.$request->ip())->response($respuesta),
            ];
        });

        // SMTP configurado desde /admin/settings (si está activado). Se omite bajo
        // config:cache/optimize: si no, la contraseña SMTP desencriptada quedaría
        // en texto plano en bootstrap/cache/config.php, y un "desactivar SMTP"
        // posterior no se reflejaría hasta un config:clear manual.
        if (! $this->app->runningConsoleCommand(['config:cache', 'optimize'])) {
            $this->app->make(MailSettings::class)->apply();
        }
    }
}
