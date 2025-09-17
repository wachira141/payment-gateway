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
     * Webhook event type mapping from gateway events to platform events
     */
    public static function getEventTypeMapping(): array
    {
        return [
            // Payment Intent Events
            'payment_intent.succeeded' => 'payment.completed',
            'payment_intent.payment_succeeded' => 'payment.completed',
            'payment_intent.failed' => 'payment.failed',
            'payment_intent.payment_failed' => 'payment.failed',
            'payment_intent.cancelled' => 'payment.cancelled',
            'payment_intent.confirmed' => 'payment.confirmed',
            'payment_intent.captured' => 'payment.captured',
            'payment_intent.created' => 'payment.created',
            
            // Legacy mappings
            'payment.completed' => 'payment.completed',
            'payment.failed' => 'payment.failed',
            'payment.pending' => 'payment.pending',
            'payment.cancelled' => 'payment.cancelled',
            
            // Disbursement Events
            'disbursement.completed' => 'payout.completed',
            'disbursement.failed' => 'payout.failed',
            
            // Refund Events
            'refund.completed' => 'refund.completed',
            'refund.failed' => 'refund.failed',
        ];
    }
    
    /**
     * Get available merchant-facing event types
     */
    public static function getMerchantEventTypes(): array
    {
        return [
            'payment.created' => 'Payment Created',
            'payment.confirmed' => 'Payment Confirmed', 
            'payment.completed' => 'Payment Completed',
            'payment.failed' => 'Payment Failed',
            'payment.cancelled' => 'Payment Cancelled',
            'payment.captured' => 'Payment Captured',
            'payout.completed' => 'Payout Completed',
            'payout.failed' => 'Payout Failed',
            'refund.completed' => 'Refund Completed',
            'refund.failed' => 'Refund Failed',
        ];
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
        
        // Check if this should trigger outbound webhooks
        if ($processResult['status'] === 'processed' && isset($processResult['payment_intent_id'])) {
            $this->triggerOutboundWebhooks($incomingWebhook, $processResult, $correlationId);
        }
        
        Log::info('Webhook event processed with correlation tracking', [
            'correlation_id' => $correlationId,
            'incoming_webhook_id' => $incomingWebhook->webhook_id,
            'process_result' => $processResult
        ]);
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
        if ($gatewayEventType === 'payment_intent') {
            return match ($action) {
                'succeeded' => 'payment.completed',
                'failed' => 'payment.failed',
                'cancelled' => 'payment.cancelled',
                'confirmed' => 'payment.confirmed',
                'captured' => 'payment.captured',
                default => null
            };
        }
        
        return null;
    }
    
    /**
     * Get webhook delivery statistics with correlation tracking
     */
    public function getWebhookFlowStats(string $timeframe = '24h'): array
    {
        $startDate = match ($timeframe) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay()
        };
        
        $incomingStats = PaymentWebhook::where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "processed" THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending
            ')
            ->first();
            
        $outboundStats = WebhookDelivery::where('created_at', '>=', $startDate)
            ->selectRaw('
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