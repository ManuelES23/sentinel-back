<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/**
 * Evento base para broadcasting de modelos
 * Extiende esta clase para crear eventos específicos
 * Usa ShouldBroadcastNow para emitir inmediatamente sin necesidad de cola
 */
abstract class ModelBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $action;
    public array $data;
    public ?int $userId;
    public ?string $enterprise;
    public ?string $application;
    public ?string $module;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $action,
        array $data,
        ?string $enterprise = null,
        ?string $application = null,
        ?string $module = null
    ) {
        $this->action = $action;
        $this->data = $data;
        $this->userId = Auth::id();
        $this->enterprise = $enterprise ?? request()->header('X-Enterprise-Slug');
        $this->application = $application ?? request()->header('X-Application-Slug');
        $this->module = $module ?? request()->header('X-Module-Slug');

        // Log para debugging
        \Illuminate\Support\Facades\Log::info('🎯 ModelBroadcastEvent construido', [
            'action' => $this->action,
            'enterprise' => $this->enterprise,
            'application' => $this->application,
            'module' => $this->module,
            'user_id' => $this->userId,
        ]);
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'data' => $this->data,
            'user_id' => $this->userId,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    abstract public function broadcastAs(): string;

    /**
     * Get the channels the event should broadcast on.
     */
    abstract public function broadcastOn(): array;
}
