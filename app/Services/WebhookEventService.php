<?php

namespace App\Services;

use App\Models\PaymentWebhook;
use App\Models\WebhookDelivery;
use App\Models\PaymentIntent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Centralized webhook event coordination service
 * Manages the flow from incoming webhooks to outbound webhook deliveries
 */
class WebhookEventService extends BaseService
{
    protected OutboundWebhookService $outboundWebhookService;
    
    public function __construct(OutboundWebhookService $outboundWebhookService)
    {
        $this->outboundWebhookService = $outboundWebhookService;
    }
    
    /**
     * Get webhook event type mapping from config
     */
    public static function getEventTypeMapping(): array
    {
        return config('apps.webhook_event_mappings', []);
    }

    /**
     * Get available merchant-facing event types from config
     */
    public static function getMerchantEventTypes(): array
    {
        return config('apps.webhook_events', []);
    }

    /**
     * Get gateway-specific event mappings
     */
    public static function getGatewayEventMappings(string | null $gateway = null): array
    {
        $mappings = config('app.gateway_event_mappings', []);
        return $gateway ? ($mappings[$gateway] ?? []) : $mappings;
    }
    
    /**
     * Generate correlation ID for tracking webhooks end-to-end
     */
    public function generateCorrelationId(): string
    {
        return 'whc_' . Str::random(32);
    }
    
    /**
     * Process incoming webhook and coordinate outbound webhook delivery
     */
    public function processIncomingWebhook(PaymentWebhook $incomingWebhook, array $processResult): void
    {
        // Generate correlation ID for tracking
        $correlationId = $this->generateCorrelationId();

        // Update incoming webhook with correlation ID
        $incomingWebhook->update(['correlation_id' => $correlationId]);

        // Standardize the process result structure
        $standardizedResult = $this->standardizeProcessResult($processResult);

        // Check if this should trigger outbound webhooks
        if ($standardizedResult['status'] === 'processed' && isset($standardizedResult['payment_intent_id'])) {
            $this->triggerOutboundWebhooks($incomingWebhook, $standardizedResult, $correlationId);
        }

        Log::info('Webhook event processed with correlation tracking', [
            'correlation_id' => $correlationId,
            'incoming_webhook_id' => $incomingWebhook->webhook_id,
            'process_result' => $standardizedResult
        ]);
    }

    /**
     * Standardize different process result formats
     */
    protected function standardizeProcessResult(array $processResult): array
    {
        // If already in standard format
        if (isset($processResult['status'])) {
            return $processResult;
        }

        // Handle legacy transaction-based format
        if (isset($processResult['success']) && isset($processResult['transaction'])) {
            $transaction = $processResult['transaction'];
            $paymentIntent = null;

            // Try to find associated payment intent
            if ($transaction->payable_type === 'App\\Models\\PaymentIntent') {
                $paymentIntent = PaymentIntent::find($transaction->payable_id);
            } else if ($transaction->gateway_transaction_id) {
                $paymentIntent = PaymentIntent::where('gateway_transaction_id', $transaction->gateway_transaction_id)->first();
            }

            return [
                'status' => $processResult['success'] ? 'processed' : 'failed',
                'payment_intent_id' => $paymentIntent ? $paymentIntent->intent_id : null,
                'transaction_id' => $transaction->id,
                'action' => $this->mapTransactionStatusToAction($transaction->status)
            ];
        }

        // Default fallback
        return [
            'status' => 'ignored',
            'reason' => 'unknown_format'
        ];
    }

    /**
     * Map transaction status to webhook action
     */
    protected function mapTransactionStatusToAction(string $status): string
    {
        return match ($status) {
            'completed' => 'succeeded',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'pending' => 'pending',
            default => 'unknown'
        };
    }
    
    /**
     * Trigger outbound webhooks based on incoming webhook processing
     */
    protected function triggerOutboundWebhooks(PaymentWebhook $incomingWebhook, array $processResult, string $correlationId): void
    {
        $paymentIntent = PaymentIntent::where('intent_id', $processResult['payment_intent_id'])->first();
        
        if (!$paymentIntent || !$paymentIntent->merchantApp) {
            Log::warning('Cannot trigger outbound webhooks - PaymentIntent or App not found', [
                'correlation_id' => $correlationId,
                'payment_intent_id' => $processResult['payment_intent_id']
            ]);
            return;
        }
        
        $appId = $paymentIntent->merchantApp->app_id;
        
        // Map gateway event to merchant event type
        $gatewayEventType = $incomingWebhook->event_type;
        $merchantEventType = $this->mapToMerchantEventType($gatewayEventType, $processResult['action'] ?? '');
        
        if (!$merchantEventType) {
            Log::info('No merchant event mapping for gateway event', [
                'correlation_id' => $correlationId,
                'gateway_event' => $gatewayEventType,
                'action' => $processResult['action'] ?? 'unknown'
            ]);
            return;
        }
        
        // Prepare payload for outbound webhook
        $payload = [
            'event_type' => $merchantEventType,
            'data' => $this->outboundWebhookService->formatPaymentIntentPayload($paymentIntent),
            'timestamp' => now()->toISOString(),
            'correlation_id' => $correlationId,
            'source_webhook_id' => $incomingWebhook->webhook_id,
        ];
        
        // Send webhook and track correlation
        $this->outboundWebhookService->sendWebhookForEvent($appId, $merchantEventType, $payload, $correlationId);
    }
    
    /**
     * Map gateway event type to merchant-facing event type
     */
    protected function mapToMerchantEventType(string $gatewayEventType, string $action = ''): ?string
    {
        $mapping = self::getEventTypeMapping();

        // Direct mapping
        if (isset($mapping[$gatewayEventType])) {
            return $mapping[$gatewayEventType];
        }

        // Action-based mapping for generic events
        if ($gatewayEventType === 'payment_intent' && $action) {
            $eventKey = "payment_intent.{$action}";
            return $mapping[$eventKey] ?? null;
        }

        return null;
    }
    
    /**
     * Get webhook delivery statistics with correlation tracking
     */
    public function getWebhookFlowStats(string $timeframe = '24h', ?string $appId = null): array
    {
        $startDate = match ($timeframe) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };
        
        // Incoming webhooks query with app filtering
        $incomingQuery = PaymentWebhook::where('created_at', '>=', $startDate);
        if ($appId) {
            $incomingQuery->where('merchant_app_id', $appId);
        }
        
        $incomingStats = $incomingQuery->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "processed" THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending
            ')
            ->first();
            
        // Outbound webhooks query with app filtering
        $outboundQuery = WebhookDelivery::where('created_at', '>=', $startDate);
        if ($appId) {
            $outboundQuery->whereHas('appWebhook', function ($q) use ($appId) {
                $q->where('app_id', $appId);
            });
        }
        
        $outboundStats = $outboundQuery->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = "pending" OR status = "retrying" THEN 1 ELSE 0 END) as pending
            ')
            ->first();
            
        return [
            'timeframe' => $timeframe,
            'incoming_webhooks' => [
                'total' => $incomingStats->total ?? 0,
                'processed' => $incomingStats->processed ?? 0,
                'failed' => $incomingStats->failed ?? 0,
                'pending' => $incomingStats->pending ?? 0,
                'success_rate' => $incomingStats->total ? round(($incomingStats->processed / $incomingStats->total) * 100, 2) : 0
            ],
            'outbound_webhooks' => [
                'total' => $outboundStats->total ?? 0,
                'delivered' => $outboundStats->delivered ?? 0,
                'failed' => $outboundStats->failed ?? 0,
                'pending' => $outboundStats->pending ?? 0,
                'success_rate' => $outboundStats->total ? round(($outboundStats->delivered / $outboundStats->total) * 100, 2) : 0
            ]
        ];
    }
    
    /**
     * Replay failed webhook with new correlation ID
     */
    public function replayWebhook(string $webhookId): array
    {
        $webhook = PaymentWebhook::findByWebhookId($webhookId);
        
        if (!$webhook) {
            return ['success' => false, 'error' => 'Webhook not found'];
        }
        
        // Generate new correlation ID for replay
        $newCorrelationId = $this->generateCorrelationId();
        
        // Create new webhook record for replay
        $replayWebhook = PaymentWebhook::create([
            'webhook_id' => Str::uuid(),
            'payment_gateway_id' => $webhook->payment_gateway_id,
            'event_type' => $webhook->event_type,
            'payload' => $webhook->payload,
            'status' => 'pending',
            'correlation_id' => $newCorrelationId,
            'replay_of_webhook_id' => $webhook->webhook_id,
        ]);
        
        Log::info('Webhook replay initiated', [
            'original_webhook_id' => $webhook->webhook_id,
            'replay_webhook_id' => $replayWebhook->webhook_id,
            'correlation_id' => $newCorrelationId
        ]);
        
        return [
            'success' => true,
            'replay_webhook_id' => $replayWebhook->webhook_id,
            'correlation_id' => $newCorrelationId
        ];
    }
}