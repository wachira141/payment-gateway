<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemHealthChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $component;
    public string $status;
    public array $healthData;

    public function __construct(string $component, string $status, array $healthData = [])
    {
        $this->component = $component;
        $this->status = $status;
        $this->healthData = $healthData;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.monitoring'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'health.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'component' => $this->component,
            'status' => $this->status,
            'health_data' => $this->healthData,
            'timestamp' => now()->toISOString(),
        ];
    }
}