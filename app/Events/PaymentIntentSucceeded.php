<?php

namespace App\Events;

use App\Models\PaymentIntent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentIntentSucceeded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PaymentIntent $paymentIntent;

    public function __construct(PaymentIntent $paymentIntent)
    {
        $this->paymentIntent = $paymentIntent;
    }

    public function broadcastOn()
    {
        return [
            new PrivateChannel('merchant.' . $this->paymentIntent->merchant_id),
        ];
    }
}