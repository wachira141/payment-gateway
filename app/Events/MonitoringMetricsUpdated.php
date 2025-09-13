<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MonitoringMetricsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $metrics;
    public string $type;

    public function __construct(array $metrics, string $type = 'general')
    {
        $this->metrics = $metrics;
        $this->type = $type;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.monitoring'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'metrics.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'metrics' => $this->metrics,
            'type' => $this->type,
            'timestamp' => now()->toISOString(),
        ];
    }
}