<?php
namespace App\Services;

use App\Models\PaymentWebhook;
use App\Models\Disbursement;
use App\Models\PaymentIntent;
use App\Services\MpesaPaymentService;
use App\Events\PaymentIntentSucceeded;
use App\Events\PaymentIntentFailed;
use App\Events\PaymentIntentCancelled;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class WebhookProcessingService
{
    protected $mpesaService;

    public function __construct(MpesaPaymentService $mpesaService)
    {
        $this->mpesaService = $mpesaService;
    }
    /**
     * Process a webhook event
     */
    public function processWebhook(PaymentWebhook $webhook): array
    {
        Log::info('Processing webhook', [
            'webhook_id' => $webhook->webhook_id,
            'event_type' => $webhook->event_type
        ]);

        try {
            DB::beginTransaction();

            // Verify webhook signature
            if (!$this->verifyWebhookSignature($webhook)) {
                throw new Exception('Invalid webhook signature');
            }

            // Process based on event type
            $result = $this->processEventType($webhook);

            DB::commit();

            return [
                'success' => true,
                'data' => $result
            ];

        } catch (Exception $e) {
            DB::rollback();
            
            Log::error('Webhook processing failed', [
                'webhook_id' => $webhook->webhook_id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process webhook based on event type and gateway
     */
    protected function processEventType(PaymentWebhook $webhook): array
    {
        $payload = $webhook->payload;
        $gatewayType = $webhook->paymentGateway->type;
        
        // Handle M-Pesa specific webhooks
        if ($gatewayType === 'mpesa') {
            return $this->processMpesaWebhook($webhook, $payload);
        }
        
        // Handle other gateway webhooks
        switch ($webhook->event_type) {
            case 'payment.completed':
                return $this->handlePaymentCompleted($webhook, $payload);
                
            case 'payment.failed':
                return $this->handlePaymentFailed($webhook, $payload);
                
            case 'payment.pending':
                return $this->handlePaymentPending($webhook, $payload);
                
            case 'payment.cancelled':
                return $this->handlePaymentCancelled($webhook, $payload);
                
            case 'disbursement.completed':
                return $this->handleDisbursementCompleted($webhook, $payload);
                
            case 'disbursement.failed':
                return $this->handleDisbursementFailed($webhook, $payload);

            // Payment Intent specific events
            case 'payment_intent.succeeded':
            case 'payment_intent.payment_succeeded':
                return $this->handlePaymentIntentSucceeded($webhook, $payload);
                
            case 'payment_intent.failed':
            case 'payment_intent.payment_failed':
                return $this->handlePaymentIntentFailed($webhook, $payload);
                
            case 'payment_intent.cancelled':
                return $this->handlePaymentIntentCancelled($webhook, $payload);

            default:
                Log::warning('Unhandled webhook event type', [
                    'event_type' => $webhook->event_type,
                    'webhook_id' => $webhook->webhook_id
                ]);
                
                return ['status' => 'ignored'];
        }
    }


    /**
     * Process M-Pesa specific webhooks
     */
    protected function processMpesaWebhook(PaymentWebhook $webhook, array $payload): array
    {
        // Check if it's B2C callback
        if (isset($payload['Result'])) {
            return $this->processMpesaB2CCallback($webhook, $payload);
        }
        
        // Check if it's STK Push callback
        if (isset($payload['Body']['stkCallback'])) {
            return $this->processMpesaSTKCallback($webhook, $payload);
        }
        
        Log::warning('Unknown M-Pesa webhook format', [
            'webhook_id' => $webhook->webhook_id,
            'payload_keys' => array_keys($payload)
        ]);
        
        return ['status' => 'ignored', 'reason' => 'unknown_format'];
    }

    /**
     * Process M-Pesa B2C callback
     */
    protected function processMpesaB2CCallback(PaymentWebhook $webhook, array $payload): array
    {
        // Use MpesaPaymentService to process the callback
        $result = $this->mpesaService->processB2CCallback($payload);
        
        if (!$result['success']) {
            throw new Exception('Failed to process M-Pesa B2C callback: ' . $result['error']);
        }
        
        $callbackData = $result['data'];
        $conversationId = $callbackData['conversation_id'];
        $resultCode = $callbackData['result_code'];
        
        // Find disbursement by conversation ID
        $disbursement = Disbursement::where('gateway_transaction_id', $conversationId)
            ->orWhere('gateway_disbursement_id', $conversationId)
            ->first();
        
        if (!$disbursement) {
            Log::warning('Disbursement not found for B2C callback', [
                'conversation_id' => $conversationId,
                'webhook_id' => $webhook->webhook_id,
                'payload' => json_encode($payload)
            ]);
            
            return ['status' => 'ignored', 'reason' => 'disbursement_not_found'];
        }
        
        if ($resultCode == 0) {
            // Success
            $disbursement->update([
                'status' => 'completed',
                'completed_at' => now(),
                'gateway_response' => $callbackData,
                'gateway_disbursement_id' => $callbackData['transaction_receipt'] ?? $conversationId
            ]);
            
            $this->notifyProvider($disbursement, 'completed');
            
            return [
                'status' => 'processed',
                'disbursement_id' => $disbursement->id,
                'action' => 'completed'
            ];
        } else {
            // Failed
            $errorMessage = $callbackData['result_desc'] ?? 'B2C payment failed';
            
            $disbursement->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $errorMessage,
                'gateway_response' => $callbackData
            ]);
            
            $this->notifyProvider($disbursement, 'failed');
            
            return [
                'status' => 'processed',
                'disbursement_id' => $disbursement->id,
                'action' => 'failed'
            ];
        }
    }

    /**
     * Process M-Pesa STK Push callback
     */
    protected function processMpesaSTKCallback(PaymentWebhook $webhook, array $payload): array
    {
        // Use MpesaPaymentService to process the callback
        $result = $this->mpesaService->processCallback($payload);
        
        if (!$result['success']) {
            throw new Exception('Failed to process M-Pesa STK callback: ' . $result['error']);
        }
        
        $callbackData = $result['data'];
        
        // This would typically update a payment record, not disbursement
        // For now, just log and return processed status
        Log::info('STK Push callback processed', [
            'webhook_id' => $webhook->webhook_id,
            'checkout_request_id' => $callbackData['checkout_request_id'],
            'result_code' => $callbackData['result_code']
        ]);
        
        return [
            'status' => 'processed',
            'action' => 'stk_callback_processed'
        ];
    }

    /**
     * Handle payment completed webhook
     */
    protected function handlePaymentCompleted(PaymentWebhook $webhook, array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? $payload['reference'] ?? null;
        
        if (!$transactionId) {
            throw new Exception('Missing transaction ID in webhook payload');
        }

        $disbursement = Disbursement::where('transaction_id', $transactionId)
            ->orWhere('gateway_transaction_id', $transactionId)
            ->first();

        if (!$disbursement) {
            Log::warning('Disbursement not found for webhook', [
                'transaction_id' => $transactionId,
                'webhook_id' => $webhook->webhook_id
            ]);
            
            return ['status' => 'ignored', 'reason' => 'disbursement_not_found'];
        }

        // Update disbursement status
        $disbursement->update([
            'status' => 'completed',
            'completed_at' => now(),
            'gateway_response' => $payload,
            'gateway_transaction_id' => $payload['gateway_transaction_id'] ?? $transactionId
        ]);

        // Notify provider
        $this->notifyProvider($disbursement, 'completed');

        Log::info('Payment completed via webhook', [
            'disbursement_id' => $disbursement->id,
            'transaction_id' => $transactionId
        ]);

        return [
            'status' => 'processed',
            'disbursement_id' => $disbursement->id,
            'action' => 'completed'
        ];
    }

    /**
     * Handle payment failed webhook
     */
    protected function handlePaymentFailed(PaymentWebhook $webhook, array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? $payload['reference'] ?? null;
        
        if (!$transactionId) {
            throw new Exception('Missing transaction ID in webhook payload');
        }

        $disbursement = Disbursement::where('transaction_id', $transactionId)
            ->orWhere('gateway_transaction_id', $transactionId)
            ->first();

        if (!$disbursement) {
            return ['status' => 'ignored', 'reason' => 'disbursement_not_found'];
        }

        $errorMessage = $payload['error_message'] ?? $payload['failure_reason'] ?? 'Payment failed';

        // Check if this failure is retryable
        $isRetryable = $this->isRetryableFailure($payload);

        if ($isRetryable && $disbursement->retry_count < 3) {
            // Schedule retry
            $disbursement->update([
                'status' => 'pending',
                'failure_reason' => $errorMessage,
                'gateway_response' => $payload
            ]);

            // Dispatch retry job with delay
            \App\Jobs\PaymentRetryJob::dispatch($disbursement)
                ->delay(now()->addMinutes(5 * ($disbursement->retry_count + 1)));

            Log::info('Payment failed, scheduling retry', [
                'disbursement_id' => $disbursement->id,
                'retry_count' => $disbursement->retry_count
            ]);

            return [
                'status' => 'processed',
                'disbursement_id' => $disbursement->id,
                'action' => 'retry_scheduled'
            ];
        } else {
            // Mark as permanently failed
            $disbursement->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $errorMessage,
                'gateway_response' => $payload
            ]);

            // Notify provider of failure
            $this->notifyProvider($disbursement, 'failed');

            Log::info('Payment permanently failed', [
                'disbursement_id' => $disbursement->id,
                'error' => $errorMessage
            ]);

            return [
                'status' => 'processed',
                'disbursement_id' => $disbursement->id,
                'action' => 'failed'
            ];
        }
    }

    /**
     * Handle payment pending webhook
     */
    protected function handlePaymentPending(PaymentWebhook $webhook, array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? $payload['reference'] ?? null;
        
        if (!$transactionId) {
            throw new Exception('Missing transaction ID in webhook payload');
        }

        $disbursement = Disbursement::where('transaction_id', $transactionId)
            ->orWhere('gateway_transaction_id', $transactionId)
            ->first();

        if ($disbursement) {
            $disbursement->update([
                'status' => 'processing',
                'gateway_response' => $payload
            ]);
        }

        return [
            'status' => 'processed',
            'action' => 'status_updated'
        ];
    }

    /**
     * Handle payment cancelled webhook
     */
    protected function handlePaymentCancelled(PaymentWebhook $webhook, array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? $payload['reference'] ?? null;
        
        if (!$transactionId) {
            throw new Exception('Missing transaction ID in webhook payload');
        }

        $disbursement = Disbursement::where('transaction_id', $transactionId)
            ->orWhere('gateway_transaction_id', $transactionId)
            ->first();

        if ($disbursement) {
            $disbursement->update([
                'status' => 'cancelled',
                'failed_at' => now(),
                'failure_reason' => 'Payment cancelled',
                'gateway_response' => $payload
            ]);

            $this->notifyProvider($disbursement, 'cancelled');
        }

        return [
            'status' => 'processed',
            'action' => 'cancelled'
        ];
    }

    /**
     * Handle disbursement completed webhook
     */
    protected function handleDisbursementCompleted(PaymentWebhook $webhook, array $payload): array
    {
        return $this->handlePaymentCompleted($webhook, $payload);
    }

    /**
     * Handle disbursement failed webhook
     */
    protected function handleDisbursementFailed(PaymentWebhook $webhook, array $payload): array
    {
        return $this->handlePaymentFailed($webhook, $payload);
    }

    /**
     * Verify webhook signature
     */
    protected function verifyWebhookSignature(PaymentWebhook $webhook): bool
    {
        // Get the gateway configuration
        $gateway = $webhook->paymentGateway;
        
        if (!$gateway) {
            Log::warning('Gateway not found for webhook', [
                'webhook_id' => $webhook->webhook_id
            ]);
            return false;
        }

        // Gateway-specific signature verification
        switch ($gateway->type) {
            case 'mpesa':
                return $this->verifyMpesaSignature($webhook, $gateway);
                
            case 'bank_transfer':
                return $this->verifyBankTransferSignature($webhook, $gateway);
                
            default:
                Log::warning('Unknown gateway type for signature verification', [
                    'gateway_type' => $gateway->type
                ]);
                return false;
        }
    }

    /**
     * Verify M-Pesa webhook signature
     */
    protected function verifyMpesaSignature(PaymentWebhook $webhook, $gateway): bool
    {
        // Implement M-Pesa specific signature verification
        // This would use the consumer secret and other M-Pesa credentials
        return true; // Placeholder - implement actual verification
    }

    /**
     * Verify bank transfer webhook signature
     */
    protected function verifyBankTransferSignature(PaymentWebhook $webhook, $gateway): bool
    {
        // Implement bank-specific signature verification
        return true; // Placeholder - implement actual verification
    }

    /**
     * Check if a failure is retryable
     */
    protected function isRetryableFailure(array $payload): bool
    {
        $nonRetryableErrors = [
            'INSUFFICIENT_FUNDS',
            'INVALID_ACCOUNT',
            'ACCOUNT_BLOCKED',
            'INVALID_AMOUNT',
            'DUPLICATE_TRANSACTION'
        ];

        $errorCode = $payload['error_code'] ?? $payload['failure_code'] ?? '';
        
        return !in_array($errorCode, $nonRetryableErrors);
    }

    /**
     * Notify provider of payment status change
     */
    protected function notifyProvider(Disbursement $disbursement, string $status): void
    {
        try {
            // This would send email/SMS notification to the provider
            // For now, just log the notification
            Log::info('Provider notification sent', [
                'disbursement_id' => $disbursement->id,
                'provider_id' => $disbursement->user_id,
                'status' => $status
            ]);
            
            // TODO: Implement actual notification service
            // NotificationService::notifyProvider($disbursement, $status);
            
        } catch (Exception $e) {
            Log::error('Failed to notify provider', [
                'disbursement_id' => $disbursement->id,
                'error' => $e->getMessage()
            ]);
        }
    }



     /**
     * Get webhook by ID with related data
     */
    public function getWebhookById(string $webhookId)
    {
        $webhook = PaymentWebhook::getWebhookWithGateway($webhookId);
        
        if (!$webhook) {
            throw new \Exception('Webhook not found', 404);
        }
        
        return $webhook;
    }

    /**
     * Get webhook statistics for specified timeframe
     */
    public function getWebhookStats(string $timeframe): array
    {
        $startDate = $this->calculateStartDate($timeframe);
        
        $stats = PaymentWebhook::getStatsByTimeframe($startDate);
        
        // Calculate success rate
        if ($stats['total'] > 0) {
            $stats['success_rate'] = round(($stats['processed'] / $stats['total']) * 100, 2);
        } else {
            $stats['success_rate'] = 0;
        }
        
        return $stats;
    }

    /**
     * Bulk retry failed webhooks
     */
    public function bulkRetryWebhooks(array $webhookIds): array
    {
        $webhooks = PaymentWebhook::getRetryableWebhooks($webhookIds);
        
        $results = [
            'total' => count($webhookIds),
            'retried' => 0,
            'failed' => 0
        ];
        
        foreach ($webhooks as $webhook) {
            try {
                $result = $this->processWebhook($webhook);
                
                if ($result['success']) {
                    $webhook->markProcessed();
                    $results['retried']++;
                } else {
                    $webhook->markFailed($result['error']);
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                $webhook->markFailed($e->getMessage());
                $results['failed']++;
            }
        }
        
        return $results;
    }

    /**
     * Get all available event types
     */
    public function getAvailableEventTypes()
    {
        return PaymentWebhook::getDistinctEventTypes();
    }

    /**
     * Replay/resend a webhook
     */
    public function replayWebhook(string $webhookId)
    {
        $webhook = PaymentWebhook::findByWebhookId($webhookId);
        
        if (!$webhook) {
            throw new \Exception('Webhook not found', 404);
        }
        
        $replayData = [
            'payment_gateway_id' => $webhook->payment_gateway_id,
            'webhook_id' => Str::uuid(),
            'event_type' => $webhook->event_type . '.replay',
            'gateway_event_id' => $webhook->gateway_event_id,
            'payload' => $webhook->payload,
            'status' => 'pending',
        ];
        
        return PaymentWebhook::createWebhook($replayData);
    }

    /**
     * Calculate start date based on timeframe
     */
    private function calculateStartDate(string $timeframe): \Carbon\Carbon
    {
        return match($timeframe) {
            '1h' => now()->subHour(),
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay(),
        };
    }

      /**
     * Handle payment intent succeeded webhook
     */
    protected function handlePaymentIntentSucceeded(PaymentWebhook $webhook, array $payload): array
    {
        $paymentIntent = $this->findPaymentIntentFromWebhook($webhook, $payload);
        
        if (!$paymentIntent) {
            Log::warning('Payment intent not found for succeeded webhook', [
                'webhook_id' => $webhook->webhook_id,
                'payload' => $payload
            ]);
            
            return ['status' => 'ignored', 'reason' => 'payment_intent_not_found'];
        }

        // Update payment intent status
        $paymentIntent->updateStatus('succeeded', [
            'confirmed_at' => now(),
            'gateway_transaction_id' => $payload['transaction_id'] ?? $payload['gateway_transaction_id'] ?? null,
        ]);

        // Fire event for webhooks
        PaymentIntentSucceeded::dispatch($paymentIntent->fresh());

        Log::info('Payment intent succeeded via webhook', [
            'payment_intent_id' => $paymentIntent->intent_id,
            'webhook_id' => $webhook->webhook_id,
            'gateway_transaction_id' => $payload['transaction_id'] ?? null,
        ]);

        return [
            'status' => 'processed',
            'payment_intent_id' => $paymentIntent->intent_id,
            'action' => 'succeeded'
        ];
    }

    /**
     * Handle payment intent failed webhook
     */
    protected function handlePaymentIntentFailed(PaymentWebhook $webhook, array $payload): array
    {
        $paymentIntent = $this->findPaymentIntentFromWebhook($webhook, $payload);
        
        if (!$paymentIntent) {
            return ['status' => 'ignored', 'reason' => 'payment_intent_not_found'];
        }

        $errorMessage = $payload['error_message'] ?? $payload['failure_reason'] ?? 'Payment failed';

        // Update payment intent status
        $paymentIntent->updateStatus('payment_failed', [
            'failure_reason' => $errorMessage,
            'gateway_transaction_id' => $payload['transaction_id'] ?? $payload['gateway_transaction_id'] ?? null,
        ]);

        // Fire event for webhooks
        PaymentIntentFailed::dispatch($paymentIntent->fresh());

        Log::info('Payment intent failed via webhook', [
            'payment_intent_id' => $paymentIntent->intent_id,
            'webhook_id' => $webhook->webhook_id,
            'error' => $errorMessage,
        ]);

        return [
            'status' => 'processed',
            'payment_intent_id' => $paymentIntent->intent_id,
            'action' => 'failed'
        ];
    }

    /**
     * Handle payment intent cancelled webhook
     */
    protected function handlePaymentIntentCancelled(PaymentWebhook $webhook, array $payload): array
    {
        $paymentIntent = $this->findPaymentIntentFromWebhook($webhook, $payload);
        
        if (!$paymentIntent) {
            return ['status' => 'ignored', 'reason' => 'payment_intent_not_found'];
        }

        $reason = $payload['cancellation_reason'] ?? 'Payment cancelled by gateway';

        // Update payment intent status
        $paymentIntent->updateStatus('cancelled', [
            'cancellation_reason' => $reason,
            'gateway_transaction_id' => $payload['transaction_id'] ?? $payload['gateway_transaction_id'] ?? null,
        ]);

        // Fire event for webhooks
        PaymentIntentCancelled::dispatch($paymentIntent->fresh());

        Log::info('Payment intent cancelled via webhook', [
            'payment_intent_id' => $paymentIntent->intent_id,
            'webhook_id' => $webhook->webhook_id,
            'reason' => $reason,
        ]);

        return [
            'status' => 'processed',
            'payment_intent_id' => $paymentIntent->intent_id,
            'action' => 'cancelled'
        ];
    }

    /**
     * Find payment intent from webhook data
     */
    protected function findPaymentIntentFromWebhook(PaymentWebhook $webhook, array $payload): ?PaymentIntent
    {
        // Try multiple ways to identify the payment intent
        
        // 1. By gateway transaction ID
        if (!empty($payload['transaction_id'])) {
            $paymentIntent = PaymentIntent::where('gateway_transaction_id', $payload['transaction_id'])->first();
            if ($paymentIntent) return $paymentIntent;
        }

        // 2. By payment intent ID (if provided in payload)
        if (!empty($payload['payment_intent_id'])) {
            $paymentIntent = PaymentIntent::where('intent_id', $payload['payment_intent_id'])->first();
            if ($paymentIntent) return $paymentIntent;
        }

        // 3. By client reference ID
        if (!empty($payload['client_reference_id'])) {
            $paymentIntent = PaymentIntent::where('client_reference_id', $payload['client_reference_id'])->first();
            if ($paymentIntent) return $paymentIntent;
        }

        // 4. By gateway-specific identifiers
        if (!empty($payload['gateway_payment_id'])) {
            $paymentIntent = PaymentIntent::where('gateway_transaction_id', $payload['gateway_payment_id'])->first();
            if ($paymentIntent) return $paymentIntent;
        }

        return null;
    }
}