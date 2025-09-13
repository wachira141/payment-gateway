<?php

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\PaymentTransaction;
use App\Events\PaymentIntentSucceeded;
use App\Events\PaymentIntentFailed;
use Illuminate\Support\Facades\Log;

/**
 * Service to manage the relationship and synchronization between
 * PaymentIntent and PaymentTransaction models
 */
class PaymentIntentTransactionService
{
    /**
     * Link a payment transaction to a payment intent
     */
    public function linkTransactionToIntent(PaymentIntent $paymentIntent, PaymentTransaction $transaction): void
    {
        $paymentIntent->update([
            'gateway_transaction_id' => $transaction->transaction_id,
            'gateway_payment_intent_id' => $transaction->gateway_payment_intent_id,
        ]);
    }

    /**
     * Sync payment intent status based on transaction status
     */
    public function syncIntentStatusFromTransaction(PaymentTransaction $transaction): void
    {
        $paymentIntent = $this->findIntentByTransaction($transaction);
        
        if (!$paymentIntent) {
            Log::warning('Payment intent not found for transaction', [
                'transaction_id' => $transaction->transaction_id
            ]);
            return;
        }

        $this->updateIntentStatus($paymentIntent, $transaction);
    }

    /**
     * Find payment intent by transaction
     */
    public function findIntentByTransaction(PaymentTransaction $transaction): ?PaymentIntent
    {
        return PaymentIntent::where('gateway_transaction_id', $transaction->transaction_id)
            ->orWhere('gateway_payment_intent_id', $transaction->gateway_payment_intent_id)
            ->first();
    }

    /**
     * Update payment intent status based on transaction status
     */
    private function updateIntentStatus(PaymentIntent $paymentIntent, PaymentTransaction $transaction): void
    {
        $statusMapping = [
            'pending' => 'processing',
            'processing' => 'processing',
            'completed' => 'succeeded',
            'failed' => 'requires_action',
            'cancelled' => 'cancelled',
            'refunded' => 'succeeded', // Keep as succeeded but metadata can track refund
            'partially_refunded' => 'succeeded',
        ];

        $newStatus = $statusMapping[$transaction->status] ?? $paymentIntent->status;

        if ($paymentIntent->status !== $newStatus) {
            $additionalData = [];

            switch ($newStatus) {
                case 'succeeded':
                    $additionalData = [
                        'confirmed_at' => $transaction->completed_at ?? now(),
                        'succeeded_at' => $transaction->completed_at ?? now(),
                    ];
                    break;
                case 'cancelled':
                    $additionalData = [
                        'cancelled_at' => now(),
                        'cancellation_reason' => $transaction->failure_reason,
                    ];
                    break;
            }

            $paymentIntent->updateStatus($newStatus, $additionalData);

            // Fire appropriate events
            if ($newStatus === 'succeeded') {
                PaymentIntentSucceeded::dispatch($paymentIntent->fresh());
            } elseif ($newStatus === 'requires_action') {
                PaymentIntentFailed::dispatch($paymentIntent->fresh());
            }

            Log::info('Payment intent status synchronized', [
                'intent_id' => $paymentIntent->intent_id,
                'transaction_id' => $transaction->transaction_id,
                'old_status' => $paymentIntent->getOriginal('status'),
                'new_status' => $newStatus,
            ]);
        }
    }

    /**
     * Get transaction for payment intent
     */
    public function getTransactionForIntent(PaymentIntent $paymentIntent): ?PaymentTransaction
    {
        if (!$paymentIntent->gateway_transaction_id) {
            return null;
        }

        return PaymentTransaction::where('transaction_id', $paymentIntent->gateway_transaction_id)->first();
    }

    /**
     * Handle webhook update for payment intent
     */
    public function handleWebhookUpdate(string $gatewayTransactionId, string $status, array $gatewayData = []): void
    {
        $transaction = PaymentTransaction::where('gateway_transaction_id', $gatewayTransactionId)
            ->orWhere('transaction_id', $gatewayTransactionId)
            ->orWhere('gateway_payment_intent_id', $gatewayTransactionId)
            ->first();

        if ($transaction) {
            // Update transaction first
            $transaction->updateWithGatewayResponse($gatewayData, $status);
            
            // Then sync payment intent
            $this->syncIntentStatusFromTransaction($transaction);
        }
    }
}