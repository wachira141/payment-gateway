<?php

namespace App\Listeners;

use App\Events\PaymentIntentCreated;
use App\Events\PaymentIntentConfirmed;
use App\Events\PaymentIntentSucceeded;
use App\Events\PaymentIntentFailed;
use App\Events\PaymentIntentCancelled;
use App\Events\PaymentIntentCaptured;
use App\Models\BaseModel;
use App\Services\OutboundWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOutboundWebhooks implements ShouldQueue
{
    use InteractsWithQueue;

    protected OutboundWebhookService $webhookService;

    public function __construct(OutboundWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Handle payment intent created event
     */
    public function handlePaymentIntentCreated(PaymentIntentCreated $event): void
    {
        Log::info('Handling PaymentIntentCreated event', [
            'payment_intent_id' => $event,
            'merchant_id' => $event->paymentIntent->merchant_id,
        ]);
        $this->sendWebhook($event->paymentIntent, 'payment_intent.created');
    }

    /**
     * Handle payment intent confirmed event
     */
    public function handlePaymentIntentConfirmed(PaymentIntentConfirmed $event): void
    {
        $this->sendWebhook($event->paymentIntent, 'payment_intent.confirmed');
    }

    /**
     * Handle payment intent succeeded event
     */
    public function handlePaymentIntentSucceeded(PaymentIntentSucceeded $event): void
    {
        $this->sendWebhook($event->paymentIntent, 'payment_intent.succeeded');
    }

    /**
     * Handle payment intent failed event
     */
    public function handlePaymentIntentFailed(PaymentIntentFailed $event): void
    {
        $this->sendWebhook($event->paymentIntent, 'payment_intent.failed');
    }

    /**
     * Handle payment intent cancelled event
     */
    public function handlePaymentIntentCancelled(PaymentIntentCancelled $event): void
    {
        $this->sendWebhook($event->paymentIntent, 'payment_intent.cancelled');
    }

    /**
     * Handle payment intent captured event
     */
    public function handlePaymentIntentCaptured(PaymentIntentCaptured $event): void
    {
        $this->sendWebhook($event->paymentIntent, 'payment_intent.captured');
    }

    /**
     * Send webhook for payment intent event
     */
    protected function sendWebhook($paymentIntent, string $eventType): void
    {

        try {
            $appId = $paymentIntent->merchant_app_id;

            $payload = [
                'event_type' => $eventType,
                'data' => $this->webhookService->formatPaymentIntentPayload($paymentIntent),
                'timestamp' => now()->toISOString(),
            ];

            $endPoint = 'whc_';

            $correlationId = BaseModel::generateCorrelationId($endPoint);

            $this->webhookService->sendWebhookForEvent($appId, $eventType, $payload, $correlationId);

            Log::info('Outbound webhook event processed', [
                'event_type' => $eventType,
                'payment_intent_id' => $paymentIntent->intent_id,
                'app_id' => $appId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send outbound webhook', [
                'event_type' => $eventType,
                'payment_intent_id' => $paymentIntent->intent_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
