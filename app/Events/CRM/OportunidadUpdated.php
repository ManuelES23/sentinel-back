<?php

namespace App\Events\CRM;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OportunidadUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $action, public array $data) {}

    public function broadcastOn(): array
    {
        return [new Channel("module.{$this->data['empresa_id']}.crm.oportunidades")];
    }

    public function broadcastAs(): string
    {
        return 'oportunidad.updated';
    }

    public function broadcastWith(): array
    {
        return ['action' => $this->action, 'data' => $this->data];
    }
}
