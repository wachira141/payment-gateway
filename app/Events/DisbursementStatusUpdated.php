<?php

namespace App\Events;

use App\Models\Disbursement;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DisbursementStatusUpdated implements ShouldBroadcast
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
            new PrivateChannel('admin.disbursements'),
            new PrivateChannel('admin.payments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'disbursement.status.updated';
    }

    public function broadcastWith(): array
    {
        // Temporarily add this before broadcasting
        Log::info('Dispatching DisbursementStatusUpdated event', [
            'disbursement_id' => $this->disbursement->disbursement_id,
            'channels' => $this->broadcastOn()
        ]);
        return [
            'disbursement' => [
                'id' => $this->disbursement->disbursement_id ?? $this->disbursement->id,
                'user_id' => $this->disbursement->user_id,
                'status' => $this->disbursement->status,
                'amount' => $this->disbursement->net_amount,
                'currency' => $this->disbursement->currency,
                'gateway_response' => $this->disbursement->gateway_response,
                'created_at' => $this->disbursement->created_at->toISOString(),
                'updated_at' => $this->disbursement->updated_at->toISOString(),
                'processed_at' => $this->disbursement->processed_at?->toISOString(),
                'completed_at' => $this->disbursement->completed_at?->toISOString(),
                'failed_at' => $this->disbursement->failed_at?->toISOString(),
                'failure_reason' => $this->disbursement->failure_reason,
                'user' => $this->disbursement->user ? [
                    'id' => $this->disbursement->user->id,
                    'name' => $this->disbursement->user->name ?? 'Unknown',
                    'email' => $this->disbursement->user->email ?? '',
                ] : null, // Handle null user
                'batch_id' => $this->disbursement->disbursement_batch_id,
            ],
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}
