<?php

namespace App\Events;

use App\Models\DisbursementBatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchDisbursementStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public DisbursementBatch $batch;
    public array $metadata;

    public function __construct(DisbursementBatch $batch, array $metadata = [])
    {
        $this->batch = $batch;
        $this->metadata = $metadata;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('private-admin.disbursements'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'batch.processing.started';
    }

    public function broadcastWith(): array
    {
        return [
            'batch' => [
                'id' => $this->batch->id,
                'name' => $this->batch->name,
                'status' => $this->batch->status,
                'disbursements_count' => $this->batch->disbursements->count(),
                'total_amount' => $this->batch->total_amount,
                'created_at' => $this->batch->created_at->toISOString(),
            ],
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}
