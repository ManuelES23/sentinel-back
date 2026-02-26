<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controller para gestionar notificaciones del usuario
 */
class NotificationController extends Controller
{
    /**
     * Obtener notificaciones del usuario autenticado
     * 
     * GET /api/notifications
     * Query params:
     *   - unread_only: bool (solo no leídas)
     *   - limit: int (por defecto 50)
     *   - category: string (filtrar por categoría)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min($request->integer('limit', 50), 100);
        $unreadOnly = $request->boolean('unread_only', false);

        $query = SystemNotification::forUser($user)
            ->active()
            ->notDismissedBy($user)
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->unreadBy($user);
        }

        if ($request->filled('category')) {
            $query->category($request->input('category'));
        }

        $notifications = $query->limit($limit)->get()->map(function ($notification) use ($user) {
            return $this->formatNotification($notification, $user);
        });

        $unreadCount = NotificationService::countUnreadForUser($user);

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Obtener conteo de notificaciones no leídas
     * 
     * GET /api/notifications/count
     */
    public function count(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = NotificationService::countUnreadForUser($user);

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    /**
     * Marcar una notificación como leída
     * 
     * POST /api/notifications/{id}/read
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = SystemNotification::findOrFail($id);

        // Verificar que la notificación sea para este usuario
        if (!$this->isNotificationForUser($notification, $user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes acceso a esta notificación',
            ], 403);
        }

        $notification->markAsReadBy($user);

        return response()->json([
            'success' => true,
            'message' => 'Notificación marcada como leída',
            'data' => $this->formatNotification($notification, $user),
        ]);
    }

    /**
     * Marcar todas las notificaciones como leídas
     * 
     * POST /api/notifications/read-all
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        NotificationService::markAllReadForUser($user);

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones marcadas como leídas',
        ]);
    }

    /**
     * Descartar una notificación
     * 
     * POST /api/notifications/{id}/dismiss
     */
    public function dismiss(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = SystemNotification::findOrFail($id);

        // Verificar que la notificación sea para este usuario
        if (!$this->isNotificationForUser($notification, $user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes acceso a esta notificación',
            ], 403);
        }

        $notification->dismissBy($user);

        return response()->json([
            'success' => true,
            'message' => 'Notificación descartada',
        ]);
    }

    /**
     * Descartar todas las notificaciones leídas
     * 
     * POST /api/notifications/dismiss-read
     */
    public function dismissAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = SystemNotification::forUser($user)
            ->active()
            ->notDismissedBy($user)
            ->get()
            ->filter(fn($n) => $n->isReadBy($user));

        foreach ($notifications as $notification) {
            $notification->dismissBy($user);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notificaciones leídas descartadas',
            'data' => [
                'dismissed_count' => $notifications->count(),
            ],
        ]);
    }

    /**
     * Verificar si una notificación es para el usuario dado
     */
    protected function isNotificationForUser(SystemNotification $notification, $user): bool
    {
        switch ($notification->audience_type) {
            case SystemNotification::AUDIENCE_PERSONAL:
                return $notification->user_id === $user->id;

            case SystemNotification::AUDIENCE_ENTERPRISE:
                // Verificar si el usuario pertenece a la empresa
                return $user->enterprises()
                    ->where('enterprise_id', $notification->enterprise_id)
                    ->exists();

            case SystemNotification::AUDIENCE_ROLE:
                return $user->role === $notification->role;

            case SystemNotification::AUDIENCE_GLOBAL:
                return true;

            default:
                return false;
        }
    }

    /**
     * Formatear notificación para respuesta
     */
    protected function formatNotification(SystemNotification $notification, $user): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'category' => $notification->category,
            'icon' => $notification->icon,
            'icon_color' => $notification->icon_color,
            'action_url' => $notification->action_url,
            'action_label' => $notification->action_label,
            'priority' => $notification->priority,
            'priority_label' => $notification->priority_label,
            'is_read' => $notification->isReadBy($user),
            'read_at' => $notification->readByUsers()
                ->where('user_id', $user->id)
                ->first()
                ?->pivot
                ?->read_at,
            'created_at' => $notification->created_at?->toISOString(),
            'expires_at' => $notification->expires_at?->toISOString(),
            'data' => $notification->data,
        ];
    }
}
