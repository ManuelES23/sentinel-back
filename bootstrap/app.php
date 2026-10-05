<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Env;

// PHP corre como módulo de Apache multihilo (ZTS) y el adaptador putenv escribe en
// el entorno del PROCESO, compartido por todas las peticiones simultáneas. Al terminar
// una petición, PHP restaura (borra) esas variables mientras otra aún está cargando su
// configuración: esa petición se queda sin DB_CONNECTION/APP_KEY, cae en la base sqlite
// por defecto y responde 401 "Unauthenticated" con un token válido (~5 % con carga
// concurrente). Sin putenv, el .env se lee a $_ENV/$_SERVER, que son por petición.
Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CORS global: se ejecuta antes del routing y cubre respuestas de error
        // de Apache (OOM, timeout) donde el pipeline de Laravel no alcanza a correr.
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Bloquea la API a usuarios con cambio de contraseña pendiente.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsurePasswordIsChanged::class);

        $middleware->alias([
            'device.token' => \App\Http\Middleware\AuthenticateDeviceToken::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordIsChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
