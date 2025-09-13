<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\OutboundWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class SendOutboundWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60; // 1 minute
    public $tries = 1; // Single attempt, retries handled by the service
    public $backoff = [30];

    protected WebhookDelivery $delivery;

    public function __construct(WebhookDelivery $delivery)
    {
        $this->delivery = $delivery;
        $this->onQueue('webhooks');
    }

    public function handle(OutboundWebhookService $webhookService): void
    {
        Log::info('Sending outbound webhook', [
            'delivery_id' => $this->delivery->id,
            'webhook_id' => $this->delivery->app_webhook_id,
            'event_type' => $this->delivery->event_type,
            'url' => $this->delivery->appWebhook->url,
        ]);

        try {
            $result = $webhookService->sendWebhookRequest($this->delivery);

            if ($result['success']) {
                $this->delivery->markAsDelivered(
                    $result['status_code'],
                    $result['response_body'] ?? null
                );

                Log::info('Webhook delivered successfully', [
                    'delivery_id' => $this->delivery->id,
                    'status_code' => $result['status_code'],
                ]);

                // Update webhook success timestamp
                $this->delivery->appWebhook->markSuccess();

            } else {
                $this->delivery->markAsFailed(
                    $result['error'],
                    $result['status_code'] ?? null,
                    $result['response_body'] ?? null
                );

                Log::warning('Webhook delivery failed', [
                    'delivery_id' => $this->delivery->id,
                    'error' => $result['error'],
                    'status_code' => $result['status_code'] ?? 'N/A',
                    'attempts' => $this->delivery->delivery_attempts,
                ]);

                // Update webhook failure timestamp
                $this->delivery->appWebhook->markFailure($result['error']);
            }

        } catch (Exception $e) {
            $this->delivery->markAsFailed($e->getMessage());

            Log::error('Webhook delivery job failed', [
                'delivery_id' => $this->delivery->id,
                'error' => $e->getMessage(),
            ]);

            // Update webhook failure timestamp
            $this->delivery->appWebhook->markFailure($e->getMessage());

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error('Webhook delivery job failed permanently', [
            'delivery_id' => $this->delivery->id,
            'error' => $exception->getMessage(),
        ]);

        $this->delivery->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}