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
            new PrivateChannel('merchant.' . $this->disbursement->merchant_id . '.disbursements'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'disbursement.status.updated';
    }

    public function broadcastWith(): array
    {
        Log::info('Dispatching DisbursementStatusUpdated event', [
            'disbursement_id' => $this->disbursement->disbursement_id,
            'merchant_id' => $this->disbursement->merchant_id,
            'channels' => $this->broadcastOn()
        ]);

        return [
            'disbursement' => [
                'id' => $this->disbursement->disbursement_id,
                'merchant_id' => $this->disbursement->merchant_id,
                'wallet_id' => $this->disbursement->wallet_id,
                'beneficiary_id' => $this->disbursement->beneficiary_id,
                'status' => $this->disbursement->status,
                'amount' => $this->disbursement->amount,
                'fee_amount' => $this->disbursement->fee_amount,
                'net_amount' => $this->disbursement->net_amount,
                'currency' => $this->disbursement->currency,
                'payout_method' => $this->disbursement->payout_method,
                'reference' => $this->disbursement->reference,
                'failure_reason' => $this->disbursement->failure_reason,
                'gateway_response' => $this->disbursement->gateway_response,
                'created_at' => $this->disbursement->created_at->toISOString(),
                'updated_at' => $this->disbursement->updated_at->toISOString(),
                'processed_at' => $this->disbursement->processed_at?->toISOString(),
                'completed_at' => $this->disbursement->completed_at?->toISOString(),
                'failed_at' => $this->disbursement->failed_at?->toISOString(),
                'beneficiary' => $this->disbursement->beneficiary ? [
                    'id' => $this->disbursement->beneficiary->beneficiary_id,
                    'name' => $this->disbursement->beneficiary->name ?? 'Unknown',
                ] : null,
                'wallet' => $this->disbursement->wallet ? [
                    'id' => $this->disbursement->wallet->wallet_id,
                    'name' => $this->disbursement->wallet->name,
                    'currency' => $this->disbursement->wallet->currency,
                ] : null,
                'batch_id' => $this->disbursement->batch_id,
            ],
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}