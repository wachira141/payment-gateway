<?php

namespace App\Jobs;

use App\Models\PaymentWebhook;
use App\Services\WebhookProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class WebhookProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes
    public $tries = 3;
    public $backoff = [10, 30, 60];

    protected $webhook;

    public function __construct(PaymentWebhook $webhook)
    {
        $this->webhook = $webhook;
        $this->onQueue('webhooks');
    }

    public function handle(WebhookProcessingService $webhookService)
    {
        Log::info('Processing webhook', [
            'webhook_id' => $this->webhook->webhook_id,
            'event_type' => $this->webhook->event_type,
            'attempt' => $this->attempts()
        ]);

        try {
            $result = $webhookService->processWebhook($this->webhook);

            if ($result['success']) {
                $this->webhook->update([
                    'status' => 'processed',
                    'processed_at' => now()
                ]);

                Log::info('Webhook processed successfully', [
                    'webhook_id' => $this->webhook->webhook_id
                ]);
            } else {
                $this->handleFailure($result['error'] ?? 'Processing failed');
            }

        } catch (Exception $e) {
            Log::error('Webhook processing failed', [
                'webhook_id' => $this->webhook->webhook_id,
                'error' => $e->getMessage()
            ]);

            $this->handleFailure($e->getMessage());
            throw $e;
        }
    }

    public function failed(Exception $exception)
    {
        Log::error('Webhook processing job failed permanently', [
            'webhook_id' => $this->webhook->webhook_id,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage()
        ]);

        $this->webhook->update([
            'status' => 'failed',
            'processing_error' => $exception->getMessage()
        ]);
    }

    protected function handleFailure(string $error)
    {
        $this->webhook->increment('retry_count');
        
        if ($this->attempts() >= $this->tries) {
            $this->webhook->update([
                'status' => 'failed',
                'processing_error' => $error
            ]);
        } else {
            $this->webhook->update([
                'processing_error' => $error
            ]);
        }
    }
}