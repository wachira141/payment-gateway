<?php

namespace App\Events;

use App\Models\WalletTopUp;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletTopUpCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public WalletTopUp $topUp;

    /**
     * Create a new event instance.
     */
    public function __construct(WalletTopUp $topUp)
    {
        $this->topUp = $topUp->load(['wallet', 'paymentTransaction']);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("merchant.{$this->topUp->merchant_id}.wallet"),
            new PrivateChannel("wallet.{$this->topUp->wallet_id}"),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'wallet.topup.completed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'top_up_id' => $this->topUp->top_up_id,
            'wallet_id' => $this->topUp->wallet_id,
            'merchant_id' => $this->topUp->merchant_id,
            'amount' => $this->topUp->amount,
            'net_amount' => $this->topUp->net_amount,
            'fee' => $this->topUp->fee,
            'currency' => $this->topUp->currency,
            'method' => $this->topUp->method,
            'status' => $this->topUp->status,
            'completed_at' => $this->topUp->completed_at?->toISOString(),
            'new_balance' => $this->topUp->wallet?->balance,
        ];
    }
}
