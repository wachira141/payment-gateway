<?php

namespace App\Listeners;

use App\Models\SystemActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogPaymentActivity implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle payment intent events.
     */
    public function handle($event): void
    {
        if (property_exists($event, 'paymentIntent')) {
            $paymentIntent = $event->paymentIntent;
            $merchantId = $paymentIntent->merchant_id;
            
            $eventType = class_basename(get_class($event));
            $action = $this->getActionFromEvent($eventType);
            $status = $this->getStatusFromEvent($eventType);
            
            SystemActivity::logPaymentActivity(
                $merchantId,
                $action,
                $status,
                [
                    'payment_intent_id' => $paymentIntent->id,
                    'amount' => $paymentIntent->amount,
                    'currency' => $paymentIntent->currency,
                    'status' => $paymentIntent->status,
                    'event_type' => $eventType
                ]
            );
        }
    }

    /**
     * Get action description from event type
     */
    private function getActionFromEvent(string $eventType): string
    {
        switch ($eventType) {
            case 'PaymentIntentCreated':
                return 'created';
            case 'PaymentIntentConfirmed':
                return 'confirmed';
            case 'PaymentIntentSucceeded':
                return 'succeeded';
            case 'PaymentIntentFailed':
                return 'failed';
            case 'PaymentIntentCancelled':
                return 'cancelled';
            case 'PaymentIntentCaptured':
                return 'captured';
            default:
                return 'updated';
        }
    }

    /**
     * Get status from event type
     */
    private function getStatusFromEvent(string $eventType): string
    {
        switch ($eventType) {
            case 'PaymentIntentSucceeded':
            case 'PaymentIntentCaptured':
            case 'PaymentIntentCreated':
            case 'PaymentIntentConfirmed':
                return 'success';
            case 'PaymentIntentFailed':
                return 'error';
            case 'PaymentIntentCancelled':
                return 'warning';
            default:
                return 'info';
        }
    }
}