<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;
use App\Models\Enterprise;
use App\Events\UserNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Servicio para crear y gestionar notificaciones del sistema
 * 
 * Uso:
 *   NotificationService::toUser($user)->vacation('Vacaciones aprobadas', 'Tu solicitud fue aprobada');
 *   NotificationService::toEnterprise($enterprise)->alert('Mantenimiento', 'El sistema estará en mantenimiento');
 *   NotificationService::toAll()->system('Actualización', 'Nueva versión disponible');
 */
class NotificationService
{
    protected ?User $targetUser = null;
    protected ?Enterprise $targetEnterprise = null;
    protected ?string $targetRole = null;
    protected string $audienceType = 'personal';
    
    protected string $priority = SystemNotification::PRIORITY_NORMAL;
    protected ?string $actionUrl = null;
    protected ?string $actionLabel = null;
    protected ?array $data = null;
    protected ?string $expiresAt = null;
    protected ?string $icon = null;
    protected ?string $iconColor = null;

    // ==================== CONSTRUCTORES ESTÁTICOS ====================

    /**
     * Notificación para un usuario específico
     */
    public static function toUser(User $user): self
    {
        $instance = new self();
        $instance->targetUser = $user;
        $instance->audienceType = SystemNotification::AUDIENCE_PERSONAL;
        return $instance;
    }

    /**
     * Notificación para todos los usuarios de una empresa
     */
    public static function toEnterprise(Enterprise $enterprise): self
    {
        $instance = new self();
        $instance->targetEnterprise = $enterprise;
        $instance->audienceType = SystemNotification::AUDIENCE_ENTERPRISE;
        return $instance;
    }

    /**
     * Notificación para usuarios con un rol específico
     */
    public static function toRole(string $role): self
    {
        $instance = new self();
        $instance->targetRole = $role;
        $instance->audienceType = SystemNotification::AUDIENCE_ROLE;
        return $instance;
    }

    /**
     * Notificación global para todos los usuarios
     */
    public static function toAll(): self
    {
        $instance = new self();
        $instance->audienceType = SystemNotification::AUDIENCE_GLOBAL;
        return $instance;
    }

    // ==================== CONFIGURADORES ====================

    /**
     * Establecer prioridad
     */
    public function priority(string $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Establecer como urgente
     */
    public function urgent(): self
    {
        $this->priority = SystemNotification::PRIORITY_URGENT;
        return $this;
    }

    /**
     * Establecer como alta prioridad
     */
    public function high(): self
    {
        $this->priority = SystemNotification::PRIORITY_HIGH;
        return $this;
    }

    /**
     * Agregar acción (botón/link)
     */
    public function withAction(string $url, string $label = 'Ver más'): self
    {
        $this->actionUrl = $url;
        $this->actionLabel = $label;
        return $this;
    }

    /**
     * Agregar datos extra
     */
    public function withData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Establecer expiración
     */
    public function expiresIn(int $hours): self
    {
        $this->expiresAt = now()->addHours($hours)->toISOString();
        return $this;
    }

    /**
     * Establecer expiración en días
     */
    public function expiresInDays(int $days): self
    {
        $this->expiresAt = now()->addDays($days)->toISOString();
        return $this;
    }

    /**
     * Establecer icono personalizado
     */
    public function icon(string $icon, ?string $color = null): self
    {
        $this->icon = $icon;
        $this->iconColor = $color;
        return $this;
    }

    // ==================== MÉTODOS DE ENVÍO POR CATEGORÍA ====================

    /**
     * Notificación de sistema
     */
    public function system(string $title, string $message): SystemNotification
    {
        return $this->send($title, $message, SystemNotification::CATEGORY_SYSTEM);
    }

    /**
     * Notificación de vacaciones
     */
    public function vacation(string $title, string $message): SystemNotification
    {
        return $this->send($title, $message, SystemNotification::CATEGORY_VACATION);
    }

    /**
     * Notificación de asistencia
     */
    public function attendance(string $title, string $message): SystemNotification
    {
        return $this->send($title, $message, SystemNotification::CATEGORY_ATTENDANCE);
    }

    /**
     * Notificación de RH
     */
    public function rh(string $title, string $message): SystemNotification
    {
        return $this->send($title, $message, SystemNotification::CATEGORY_RH);
    }

    /**
     * Alerta
     */
    public function alert(string $title, string $message): SystemNotification
    {
        return $this->send($title, $message, SystemNotification::CATEGORY_ALERT);
    }

    /**
     * Información general
     */
    public function info(string $title, string $message): SystemNotification
    {
        return $this->send($title, $message, SystemNotification::CATEGORY_INFO);
    }

    // ==================== MÉTODO PRINCIPAL ====================

    /**
     * Crear y enviar la notificación
     */
    protected function send(string $title, string $message, string $category): SystemNotification
    {
        $notification = SystemNotification::create([
            'id' => Str::uuid(),
            'audience_type' => $this->audienceType,
            'user_id' => $this->targetUser?->id,
            'enterprise_id' => $this->targetEnterprise?->id,
            'role' => $this->targetRole,
            'title' => $title,
            'message' => $message,
            'category' => $category,
            'icon' => $this->icon,
            'icon_color' => $this->iconColor,
            'action_url' => $this->actionUrl,
            'action_label' => $this->actionLabel,
            'data' => $this->data,
            'priority' => $this->priority,
            'expires_at' => $this->expiresAt,
            'created_by' => Auth::id(),
        ]);

        // Broadcast en tiempo real
        $this->broadcastNotification($notification);

        return $notification;
    }

    /**
     * Broadcast de la notificación en tiempo real
     */
    protected function broadcastNotification(SystemNotification $notification): void
    {
        switch ($notification->audience_type) {
            case SystemNotification::AUDIENCE_PERSONAL:
                // Enviar al canal del usuario específico
                if ($notification->user_id) {
                    broadcast(new UserNotification([
                        'id' => $notification->id,
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'category' => $notification->category,
                        'icon' => $notification->icon,
                        'icon_color' => $notification->icon_color,
                        'action_url' => $notification->action_url,
                        'action_label' => $notification->action_label,
                        'priority' => $notification->priority,
                        'created_at' => $notification->created_at->toISOString(),
                    ], $notification->user_id));
                }
                break;

            case SystemNotification::AUDIENCE_ENTERPRISE:
                // Para empresa, enviar a cada usuario de la empresa
                if ($notification->enterprise) {
                    $userIds = $notification->enterprise->users()->pluck('users.id');
                    foreach ($userIds as $userId) {
                        broadcast(new UserNotification([
                            'id' => $notification->id,
                            'title' => $notification->title,
                            'message' => $notification->message,
                            'category' => $notification->category,
                            'icon' => $notification->icon,
                            'icon_color' => $notification->icon_color,
                            'action_url' => $notification->action_url,
                            'action_label' => $notification->action_label,
                            'priority' => $notification->priority,
                            'created_at' => $notification->created_at->toISOString(),
                        ], $userId));
                    }
                }
                break;

            case SystemNotification::AUDIENCE_ROLE:
                // Para rol, enviar a usuarios con ese rol
                $userIds = User::where('role', $notification->role)->pluck('id');
                foreach ($userIds as $userId) {
                    broadcast(new UserNotification([
                        'id' => $notification->id,
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'category' => $notification->category,
                        'icon' => $notification->icon,
                        'icon_color' => $notification->icon_color,
                        'action_url' => $notification->action_url,
                        'action_label' => $notification->action_label,
                        'priority' => $notification->priority,
                        'created_at' => $notification->created_at->toISOString(),
                    ], $userId));
                }
                break;

            case SystemNotification::AUDIENCE_GLOBAL:
                // Para global, broadcasting a todos los usuarios conectados
                // Esto se maneja diferente - los clientes escuchan un canal público
                // Por ahora, enviar a los primeros usuarios (limitar para no saturar)
                $userIds = User::pluck('id');
                foreach ($userIds->take(100) as $userId) { // Limitar para no saturar
                    broadcast(new UserNotification([
                        'id' => $notification->id,
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'category' => $notification->category,
                        'icon' => $notification->icon,
                        'icon_color' => $notification->icon_color,
                        'action_url' => $notification->action_url,
                        'action_label' => $notification->action_label,
                        'priority' => $notification->priority,
                        'created_at' => $notification->created_at->toISOString(),
                    ], $userId));
                }
                break;
        }
    }

    // ==================== MÉTODOS ESTÁTICOS DE UTILIDAD ====================

    /**
     * Obtener notificaciones para un usuario
     */
    public static function getForUser(User $user, int $limit = 50, bool $unreadOnly = false): \Illuminate\Database\Eloquent\Collection
    {
        $query = SystemNotification::forUser($user)
            ->active()
            ->notDismissedBy($user)
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->unreadBy($user);
        }

        return $query->limit($limit)->get()->map(function (SystemNotification $notification) use ($user) {
            $notification->is_read = $notification->isReadBy($user);
            return $notification;
        });
    }

    /**
     * Contar notificaciones no leídas para un usuario
     */
    public static function countUnreadForUser(User $user): int
    {
        return SystemNotification::forUser($user)
            ->active()
            ->unreadBy($user)
            ->notDismissedBy($user)
            ->count();
    }

    /**
     * Marcar todas como leídas para un usuario
     */
    public static function markAllReadForUser(User $user): void
    {
        $notifications = SystemNotification::forUser($user)
            ->active()
            ->unreadBy($user)
            ->get();

        /** @var SystemNotification $notification */
        foreach ($notifications as $notification) {
            $notification->markAsReadBy($user);
        }
    }
}
