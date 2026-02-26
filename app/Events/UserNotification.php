<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notificación en tiempo real para un usuario específico
 * 
 * Uso:
 *   // Forma simple (legacy)
 *   broadcast(new UserNotification($userId, 'info', 'Título', 'Mensaje'));
 * 
 *   // Forma con array de datos (nueva)
 *   broadcast(new UserNotification(['id' => 'x', 'title' => 'y', ...], $userId));
 */
class UserNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;
    public array $notificationData;

    /**
     * Create a new event instance.
     * 
     * Acepta dos formas:
     * 1. (array $data, int $userId) - Nueva forma con datos completos
     * 2. (int $userId, string $type, string $title, string $message, ?array $data) - Legacy
     */
    public function __construct(mixed $first, mixed $second = null, mixed $third = null, mixed $fourth = null, mixed $fifth = null)
    {
        // Nueva forma: (array $data, int $userId)
        if (is_array($first) && is_int($second)) {
            $this->notificationData = $first;
            $this->userId = $second;
        } 
        // Legacy: (int $userId, string $type, string $title, string $message, ?array $data)
        else {
            $this->userId = $first;
            $this->notificationData = [
                'type' => $second,
                'title' => $third,
                'message' => $fourth,
                'data' => $fifth,
            ];
        }
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("App.Models.User.{$this->userId}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'notification';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return array_merge($this->notificationData, [
            'timestamp' => now()->toISOString(),
        ]);
    }
}
