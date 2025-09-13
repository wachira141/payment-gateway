<?php

namespace App\Events;

use App\Models\Disbursement;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStuckDetected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Disbursement $disbursement;
    public array $metadata;

    public function __construct(Disbursement $disbursement, array $metadata = [])
    {
        $this->disbursement = $disbursement;
        $this->metadata = $metadata;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-admin.payments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.stuck.detected';
    }

    public function broadcastWith(): array
    {
        return [
            'disbursement' => [
                'id' => $this->disbursement->disbursement_id,
                'user_id' => $this->disbursement->user_id,
                'status' => $this->disbursement->status,
                'amount' => $this->disbursement->net_amount,
                'processed_at' => $this->disbursement->processed_at?->toISOString(),
                'minutes_stuck' => $this->metadata['minutes_stuck'] ?? 0,
                'user' => [
                    'name' => $this->disbursement->user->name,
                ],
            ],
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}
