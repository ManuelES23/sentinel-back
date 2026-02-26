<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\NotificationService;
use App\Models\User;

class NotificationSeeder extends Seeder
{
    /**
     * Seed de notificaciones de ejemplo
     */
    public function run(): void
    {
        $user = User::first();
        
        if (!$user) {
            $this->command->info('No hay usuarios en el sistema');
            return;
        }

        // Notificación personal con acción
        NotificationService::toUser($user)
            ->withAction('/profile', 'Ver perfil')
            ->info('Bienvenido a SENTINEL', 'Tu cuenta ha sido configurada correctamente. Puedes personalizar tu perfil desde aquí.');

        // Notificación de sistema global
        NotificationService::toAll()
            ->system('Sistema actualizado', 'SENTINEL 3.0 ha sido actualizado con nuevas funcionalidades incluyendo el sistema de notificaciones.');

        // Notificación urgente
        NotificationService::toUser($user)
            ->urgent()
            ->withAction('/profile', 'Revisar')
            ->alert('Acción requerida', 'Por favor actualiza tu información de contacto en tu perfil.');

        // Notificación de vacaciones (ejemplo)
        NotificationService::toUser($user)
            ->withAction('/profile', 'Ver vacaciones')
            ->vacation('Recordatorio de vacaciones', 'Tienes días de vacaciones disponibles. Recuerda solicitarlos a tiempo.');

        $this->command->info('Notificaciones de ejemplo creadas exitosamente');
    }
}
