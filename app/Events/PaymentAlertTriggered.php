<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\SystemAlert;

class PaymentAlertTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public SystemAlert $alert;
    public array $paymentData;

    public function __construct(SystemAlert $alert, array $paymentData = [])
    {
        $this->alert = $alert;
        $this->paymentData = $paymentData;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.alerts'),
            new PrivateChannel('admin.payments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.alert';
    }

    public function broadcastWith(): array
    {
        return [
            'alert' => [
                'id' => $this->alert->id,
                'type' => $this->alert->type,
                'severity' => $this->alert->severity,
                'title' => $this->alert->title,
                'message' => $this->alert->message,
                'created_at' => $this->alert->created_at->toISOString(),
            ],
            'payment_data' => $this->paymentData,
            'timestamp' => now()->toISOString(),
        ];
    }
}