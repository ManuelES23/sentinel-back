<?php

namespace App\Providers;

use App\Services\SettingsService;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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
    }
}
