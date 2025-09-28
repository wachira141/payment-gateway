<?php

namespace App\Services;

use App\Models\AppWebhook;
use App\Models\WebhookDelivery;
use App\Jobs\SendOutboundWebhookJob;
use Illuminate\Support\Facades\Log;


class OutboundWebhookService extends BaseService
{
    /**
     * Send webhook for specific event with optional correlation tracking
     */
    public function sendWebhookForEvent(string $appId, string $eventType, array $payload, string | null $correlationId = null): void
    {
        $webhooks = AppWebhook::getActiveForEvent($appId, $eventType);

        Log::info('Found webhooks for event', [
            'app_id' => $appId,
            'event_type' => $eventType,
            'webhooks' => $webhooks,
            'webhook_count' => count($webhooks),
        ]);
        
        foreach ($webhooks as $webhook) {
            $this->dispatchWebhook($webhook, $eventType, $payload, $correlationId);
        }
    }

    /**
     * Dispatch webhook delivery job with correlation tracking
     */
    public function dispatchWebhook(AppWebhook $webhook, string $eventType, array $payload, string | null $correlationId = null): WebhookDelivery
    {
        // Create delivery record
        $delivery = WebhookDelivery::create([
            'app_webhook_id' => $webhook->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'pending',
            'correlation_id' => $correlationId,
            'source_webhook_id' => $payload['source_webhook_id'] ?? null,
        ]);

        // Dispatch job to send webhook
        SendOutboundWebhookJob::dispatch($delivery);

        Log::info('Webhook delivery dispatched', [
            'webhook_id' => $webhook->id,
            'delivery_id' => $delivery->id,
            'event_type' => $eventType,
            'url' => $webhook->url,
            'correlation_id' => $correlationId,
        ]);

        return $delivery;
    }

    /**
     * Generate webhook signature
     */
    public function generateSignature(array $payload, string $secret): string
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha256', $payloadJson, $secret);
    }

    /**
     * Send webhook HTTP request
     */
    public function sendWebhookRequest(WebhookDelivery $delivery): array
    {
        $webhook = $delivery->appWebhook;
        $payload = $delivery->payload;

        try {
            // Generate signature
            $signature = $this->generateSignature($payload, $webhook->secret ?? '');
            
            // Prepare headers
            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'PGaaS-Webhooks/1.0',
                'X-Webhook-Signature' => 'sha256=' . $signature,
                'X-Webhook-Event' => $delivery->event_type,
                'X-Webhook-Delivery' => $delivery->id,
            ];

            // Add custom headers from webhook configuration
            if (!empty($webhook->headers)) {
                $headers = array_merge($headers, $webhook->headers);
            }

            // Send HTTP request
            $client = new \GuzzleHttp\Client([
                'timeout' => $webhook->timeout_seconds ?? 30,
                'connect_timeout' => 10,
            ]);

            $response = $client->post($webhook->url, [
                'headers' => $headers,
                'json' => $payload,
            ]);

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'response_body' => $response->getBody()->getContents(),
            ];

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null;

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
                'response_body' => $responseBody,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => null,
                'response_body' => null,
            ];
        }
    }

    /**
     * Retry failed webhook deliveries
     */
    public function retryFailedDeliveries(): void
    {
        $deliveries = WebhookDelivery::getReadyForRetry();

        foreach ($deliveries as $delivery) {
            SendOutboundWebhookJob::dispatch($delivery);
        }

        if ($deliveries->count() > 0) {
            Log::info('Retrying webhook deliveries', [
                'count' => $deliveries->count(),
            ]);
        }
    }

    /**
     * Format payment intent data for webhook payload
     */
    public function formatPaymentIntentPayload($paymentIntent): array
    {

        Log::info('formatPaymentIntentPayload', [
            'data' => $paymentIntent,
        ]);

        return [
            'id' => $paymentIntent->intent_id,
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'client_reference_id' => $paymentIntent->client_reference_id,
            'description' => $paymentIntent->description,
            'metadata' => $paymentIntent->metadata,
            'customer' => $paymentIntent->customer ? [
                'id' => $paymentIntent->customer->customer_id,
                'email' => $paymentIntent->customer->email,
                'name' => $paymentIntent->customer->name,
            ] : null,
            'created_at' => $paymentIntent->created_at->toISOString(),
            'updated_at' => $paymentIntent->updated_at->toISOString(),
        ];
    }
}