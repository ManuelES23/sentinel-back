<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Canales de broadcasting para comunicación en tiempo real.
| Los canales privados requieren autenticación.
|
| NOTA: Los canales solo se registran si el broadcasting está habilitado.
|
*/

// Solo registrar canales si el broadcasting está configurado correctamente
if (config('broadcasting.default') !== 'null' && config('broadcasting.default') !== null) {
    try {
        // Canal privado por usuario (notificaciones personales)
        Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
            return (int) $user->id === (int) $id;
        });

        // Canal privado por empresa
        Broadcast::channel('enterprise.{enterpriseId}', function ($user, $enterpriseId) {
            return $user->activeEnterprises()
                ->where('enterprises.id', $enterpriseId)
                ->exists();
        });

        // Canal privado por aplicación
        Broadcast::channel('application.{applicationId}', function ($user, $applicationId) {
            return $user->activeApplications()
                ->where('applications.id', $applicationId)
                ->exists();
        });

        // Canal de presencia por empresa (ver quién está conectado)
        Broadcast::channel('presence.enterprise.{enterpriseId}', function ($user, $enterpriseId) {
            if ($user->activeEnterprises()->where('enterprises.id', $enterpriseId)->exists()) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            }

            return false;
        });

        // Canal de presencia por aplicación
        Broadcast::channel('presence.application.{applicationId}', function ($user, $applicationId) {
            if ($user->activeApplications()->where('applications.id', $applicationId)->exists()) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                ];
            }

            return false;
        });

        // Canal para módulos específicos (ej: cultivos en tiempo real)
        Broadcast::channel('module.{enterpriseSlug}.{applicationSlug}.{moduleSlug}', function ($user, $enterpriseSlug, $applicationSlug, $moduleSlug) {
            Log::info("Canal autorizado: usuario {$user->id} en module.{$enterpriseSlug}.{$applicationSlug}.{$moduleSlug}");
            return true;
        });

        // Canal para vacaciones del empleado
        Broadcast::channel('employee.{employeeId}.vacations', function ($user, $employeeId) {
            // El usuario puede escuchar si tiene un empleado vinculado con ese ID
            return $user->employee && (int) $user->employee->id === (int) $employeeId;
        });

    } catch (\Exception $e) {
        Log::warning('Broadcasting channels could not be registered: ' . $e->getMessage());
    }
}
