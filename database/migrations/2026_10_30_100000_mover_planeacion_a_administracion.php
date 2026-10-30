<?php

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use Illuminate\Database\Migrations\Migration;

/**
 * Planeación pasa de Operación Agrícola a Administración en Splendid Farms:
 * junta varias temporadas y no depende de la que se elija al entrar a la
 * aplicación.
 *
 * Se MUEVE la fila del módulo, así que módulo, submódulo y permisos conservan
 * sus ids. El acceso a la aplicación vive en otra tabla y no viaja solo: se abre
 * Administración a quien ya tenía el módulo activo. Es idempotente; si el módulo
 * ya no está en Operación Agrícola, termina sin hacer nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $empresa = Enterprise::where('slug', 'splendidfarms')->first();
        $operacion = $empresa
            ? Application::where('enterprise_id', $empresa->id)->where('slug', 'operacion-agricola')->first()
            : null;
        $modulo = $operacion
            ? Module::where('application_id', $operacion->id)->where('slug', 'planeacion')->first()
            : null;

        if (! $modulo) {
            return;
        }

        // `applications` no tiene columna `order`.
        $administracion = Application::firstOrCreate(
            ['enterprise_id' => $empresa->id, 'slug' => 'administration'],
            [
                'name' => 'Administración',
                'path' => '/splendidfarms/administration',
                'description' => 'Administración',
                'icon' => 'Building2',
                'is_active' => true,
            ],
        );

        $modulo->update([
            'application_id' => $administracion->id,
            'order' => ((int) Module::where('application_id', $administracion->id)->max('order')) + 1,
        ]);

        // firstOrCreate a propósito: no resucita accesos desactivados a mano.
        $usuarios = UserModuleAccess::where('module_id', $modulo->id)->where('is_active', true)->pluck('user_id')->unique();
        foreach ($usuarios as $userId) {
            UserApplicationAccess::firstOrCreate(
                ['user_id' => $userId, 'application_id' => $administracion->id],
                ['is_active' => true, 'granted_at' => now()],
            );
        }
    }

    /**
     * Regresa el módulo a Operación Agrícola. El acceso a Administración se
     * queda: no se sabe si el usuario ya lo tenía por otro módulo.
     */
    public function down(): void
    {
        $empresa = Enterprise::where('slug', 'splendidfarms')->first();
        $administracion = $empresa
            ? Application::where('enterprise_id', $empresa->id)->where('slug', 'administration')->first()
            : null;
        $operacion = $empresa
            ? Application::where('enterprise_id', $empresa->id)->where('slug', 'operacion-agricola')->first()
            : null;
        $modulo = $administracion
            ? Module::where('application_id', $administracion->id)->where('slug', 'planeacion')->first()
            : null;

        if (! $modulo || ! $operacion) {
            return;
        }

        $modulo->update([
            'application_id' => $operacion->id,
            'order' => ((int) Module::where('application_id', $operacion->id)->max('order')) + 1,
        ]);

        $usuarios = UserModuleAccess::where('module_id', $modulo->id)->where('is_active', true)->pluck('user_id')->unique();
        foreach ($usuarios as $userId) {
            UserApplicationAccess::firstOrCreate(
                ['user_id' => $userId, 'application_id' => $operacion->id],
                ['is_active' => true, 'granted_at' => now()],
            );
        }
    }
};
