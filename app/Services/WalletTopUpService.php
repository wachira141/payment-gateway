<?php

namespace App\Services;

use App\Models\MerchantWallet;
use App\Models\WalletTopUp;
use App\Models\WalletTransaction;
use App\Helpers\CurrencyHelper;
use App\Events\WalletTopUpCompleted;
use App\Events\WalletTopUpFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

class WalletTopUpService extends BaseService
{
    protected WalletService $walletService;
    protected PaymentProcessorService $paymentProcessor;

    public function __construct(
        WalletService $walletService,
        PaymentProcessorService $paymentProcessor
    ) {
        $this->walletService = $walletService;
        $this->paymentProcessor = $paymentProcessor;
    }

    // ==================== TOP-UP OPERATIONS ====================

    /**
     * Initiate a top-up
     */
    public function initiateTopUp(string $walletId, float $amount, string $method, array $options = []): WalletTopUp
    {
        $wallet = $this->walletService->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        if (!$wallet->isActive()) {
            throw new \Exception('Wallet is not active');
        }

        $this->logActivity('wallet.topup.initiate', [
            'wallet_id' => $walletId,
            'amount' => $amount,
            'method' => $method,
        ]);

        switch ($method) {
            case 'bank_transfer':
                return $this->initiateBankTransferTopUp($wallet, $amount, $options);
            case 'mobile_money':
                return $this->initiateMobileMoneyTopUp($wallet, $amount, $options);
            case 'card':
                return $this->initiateCardTopUp($wallet, $amount, $options);
            case 'balance_sweep':
                return $this->initiateBalanceSweep($wallet, $amount, $options);
            default:
                throw new \Exception("Unsupported top-up method: {$method}");
        }
    }

    /**
     * Initiate bank transfer top-up
     * Bank transfers don't go through PaymentProcessorService as they're manual
     */
    protected function initiateBankTransferTopUp(MerchantWallet $wallet, float $amount, array $options = []): WalletTopUp
    {
        // Generate unique bank reference
        $bankReference = 'BT' . strtoupper(substr(md5(uniqid()), 0, 10));

        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'merchant_id' => $wallet->merchant_id,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'bank_reference' => $bankReference,
            'expires_at' => now()->addHours(24), // 24 hour expiry
            'metadata' => $options['metadata'] ?? null,
        ]);

        // Generate payment instructions
        $topUp->update([
            'payment_instructions' => $topUp->generatePaymentInstructions(),
        ]);

        return $topUp;
    }

    /**
     * Initiate mobile money top-up
     */
    /**
     * Initiate mobile money top-up through PaymentProcessorService
     */
    protected function initiateMobileMoneyTopUp(MerchantWallet $wallet, float $amount, array $options = []): WalletTopUp
    {
        $phoneNumber = $options['phone_number'] ?? null;

        if (!$phoneNumber) {
            throw new \Exception('Phone number is required for mobile money top-up');
        }

        // Determine gateway based on provider
        $provider = $options['provider'] ?? 'mpesa';
        $gatewayCode = $this->mapProviderToGatewayCode($provider);

        // Create WalletTopUp record first
        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'merchant_id' => $wallet->merchant_id,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'method' => 'mobile_money',
            'status' => 'pending',
            'gateway_type' => $provider,
            'expires_at' => now()->addMinutes(15),
            'metadata' => array_merge($options['metadata'] ?? [], [
                'phone_number' => $phoneNumber,
            ]),
        ]);

        // Process payment through PaymentProcessorService
        $paymentResult = $this->paymentProcessor->processPayment([
            'gateway_code' => $gatewayCode,
            'merchant_id' => $wallet->merchant_id,
            'payable_type' => 'wallet_top_up',
            'payable_id' => $topUp->id,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'phone_number' => $phoneNumber,
            'description' => "Wallet top-up: {$topUp->top_up_id}",
            'metadata' => [
                'wallet_id' => $wallet->wallet_id,
                'top_up_id' => $topUp->top_up_id,
            ],
        ]);

        if ($paymentResult['success']) {
            $topUp->update([
                'status' => 'processing',
                'gateway_reference' => $paymentResult['checkout_request_id']
                    ?? $paymentResult['reference_id']
                    ?? $paymentResult['transaction_id']
                    ?? null,
                'payment_transaction_id' => $paymentResult['transaction']->id ?? null,
            ]);

            $this->logActivity('wallet.topup.payment_initiated', [
                'top_up_id' => $topUp->top_up_id,
                'gateway' => $gatewayCode,
                'transaction_id' => $paymentResult['transaction']->id ?? null,
            ]);
        } else {
            $topUp->markFailed($paymentResult['error'] ?? 'Payment initiation failed');

            $this->logActivity('wallet.topup.payment_failed', [
                'top_up_id' => $topUp->top_up_id,
                'error' => $paymentResult['error'] ?? 'Unknown error',
            ]);
        }

        return $topUp->fresh();
    }

    /**
     * Initiate card top-up through PaymentProcessorService
     */
    protected function initiateCardTopUp(MerchantWallet $wallet, float $amount, array $options = []): WalletTopUp
    {
        $gateway = $options['gateway'] ?? 'stripe';

        // Create WalletTopUp record first
        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'merchant_id' => $wallet->merchant_id,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'method' => 'card',
            'status' => 'pending',
            'gateway_type' => $gateway,
            'expires_at' => now()->addMinutes(30),
            'metadata' => $options['metadata'] ?? null,
        ]);

        // Process payment through PaymentProcessorService
        $paymentResult = $this->paymentProcessor->processPayment([
            'gateway_code' => $gateway,
            'merchant_id' => $wallet->merchant_id,
            'payable_type' => 'wallet_top_up',
            'payable_id' => $topUp->id,
            'amount' => CurrencyHelper::toMinorUnits($amount, $wallet->currency),
            'currency' => $wallet->currency,
            'description' => "Wallet top-up: {$topUp->top_up_id}",
            'metadata' => [
                'wallet_id' => $wallet->wallet_id,
                'top_up_id' => $topUp->top_up_id,
            ],
        ]);

        if ($paymentResult['success']) {
            $updateData = [
                'status' => 'processing',
                'payment_transaction_id' => $paymentResult['transaction_id'] ?? null,
            ];

            // Store Stripe-specific data
            if (isset($paymentResult['payment_intent_id'])) {
                $updateData['gateway_reference'] = $paymentResult['payment_intent_id'];
                $updateData['gateway_response'] = [
                    'payment_intent_id' => $paymentResult['payment_intent_id'],
                    'client_secret' => $paymentResult['client_secret'] ?? null,
                ];
            }

            $topUp->update($updateData);

            $this->logActivity('wallet.topup.payment_initiated', [
                'top_up_id' => $topUp->top_up_id,
                'gateway' => $gateway,
                'transaction_id' => $paymentResult['transaction_id'] ?? null,
            ]);
        } else {
            $topUp->markFailed($paymentResult['error'] ?? 'Payment initiation failed');

            $this->logActivity('wallet.topup.payment_failed', [
                'top_up_id' => $topUp->top_up_id,
                'error' => $paymentResult['error'] ?? 'Unknown error',
            ]);
        }

        return $topUp->fresh();
    }

    /**
     * Initiate balance sweep (from MerchantBalance to Wallet)
     */
    protected function initiateBalanceSweep(MerchantWallet $wallet, float $amount, array $options = []): WalletTopUp
    {
        // This will be handled by WalletTransferService
        // Create a top-up record for tracking
        $topUp = WalletTopUp::create([
            'wallet_id' => $wallet->id,
            'merchant_id' => $wallet->merchant_id,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'method' => 'balance_sweep',
            'status' => 'pending',
            'metadata' => $options['metadata'] ?? null,
        ]);

        return $topUp;
    }


    /**
     * Map mobile money provider to gateway code
     */
    protected function mapProviderToGatewayCode(string $provider): string
    {
        $mapping = [
            'mpesa' => 'mpesa',
            'mtn' => 'mtn_momo',
            'mtn_momo' => 'mtn_momo',
            'airtel' => 'airtel_money',
            'airtel_money' => 'airtel_money',
            'telebirr' => 'telebirr',
        ];

        return $mapping[$provider] ?? $provider;
    }
    /**
     * Process top-up callback
     */
    public function processTopUpCallback(string $topUpId, array $callbackData): WalletTopUp
    {
        $topUp = WalletTopUp::findByTopUpId($topUpId);

        if (!$topUp) {
            throw new \Exception('Top-up not found');
        }

        if ($topUp->status !== 'pending' && $topUp->status !== 'processing') {
            throw new \Exception('Top-up is not in a processable state');
        }

        $this->logActivity('wallet.topup.callback', [
            'top_up_id' => $topUpId,
            'callback_data' => $callbackData,
        ]);

        return DB::transaction(function () use ($topUp, $callbackData) {
            $success = $callbackData['success'] ?? false;
            $gatewayReference = $callbackData['gateway_reference'] ?? null;
            $gatewayResponse = $callbackData['gateway_response'] ?? [];

            if ($success) {
                // Mark top-up as completed
                $topUp->update([
                    'status' => 'completed',
                    'gateway_reference' => $gatewayReference ?? $topUp->gateway_reference,
                    'gateway_response' => array_merge(
                        $topUp->gateway_response ?? [],
                        $gatewayResponse
                    ),
                    'completed_at' => now(),
                ]);

                // Credit the wallet
                $this->walletService->creditWallet(
                    $topUp->wallet->wallet_id,
                    $topUp->amount,
                    'top_up',
                    $topUp,
                    "Top-up via {$topUp->method}"
                );

                $this->logActivity('wallet.topup.completed', [
                    'top_up_id' => $topUp->top_up_id,
                    'amount' => $topUp->amount,
                ]);
                // Fire event for real-time updates
                event(new WalletTopUpCompleted($topUp));
            } else {
                // Mark top-up as failed
                $failureReason = $callbackData['failure_reason'] ?? 'Payment failed';
                $topUp->markFailed($failureReason);

                $this->logActivity('wallet.topup.failed', [
                    'top_up_id' => $topUp->top_up_id,
                    'reason' => $failureReason,
                ]);
                // Fire event for real-time updates
                event(new WalletTopUpFailed($topUp, $failureReason));
            }

            return $topUp->fresh();
        });
    }

     /**
     * Process top-up from PaymentTransaction (called by WebhookController)
     */
    public function processTopUpFromTransaction(\App\Models\PaymentTransaction $transaction): WalletTopUp
    {
        $topUp = WalletTopUp::find($transaction->payable_id);

        if (!$topUp) {
            throw new \Exception('WalletTopUp not found for transaction: ' . $transaction->transaction_id);
        }

        $success = $transaction->status === 'completed';

        return $this->processTopUpCallback($topUp->top_up_id, [
            'success' => $success,
            'gateway_reference' => $transaction->gateway_transaction_id,
            'gateway_response' => $transaction->gateway_response ?? [],
            'failure_reason' => $success ? null : ($transaction->failure_reason ?? 'Payment failed'),
        ]);
    }

    /**
     * Cancel a pending top-up
     */
    public function cancelTopUp(string $topUpId): bool
    {
        $topUp = WalletTopUp::findByTopUpId($topUpId);

        if (!$topUp) {
            throw new \Exception('Top-up not found');
        }

        if (!$topUp->canBeCancelled()) {
            throw new \Exception('Top-up cannot be cancelled in its current state');
        }

        $result = $topUp->markCancelled();

        $this->logActivity('wallet.topup.cancelled', [
            'top_up_id' => $topUpId,
        ]);

        return $result;
    }

    /**
     * Expire stale top-ups
     */
    public function expireStaleTopUps(): int
    {
        $expiredTopUps = WalletTopUp::getExpiredPending();
        $count = 0;

        foreach ($expiredTopUps as $topUp) {
            $topUp->markExpired();
            $count++;

            $this->logActivity('wallet.topup.expired', [
                'top_up_id' => $topUp->top_up_id,
            ]);
        }

        return $count;
    }

    // ==================== QUERY METHODS ====================

    /**
     * Get top-ups for wallet
     */
    public function getTopUpsForWallet(string $walletId, array $filters = []): LengthAwarePaginator
    {
        $wallet = $this->walletService->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

       
        $query = WalletTopUp::where('wallet_id', $wallet->id)
            ->with(['paymentTransaction']);

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $perPage = $filters['per_page'] ?? 25;

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get top-up by ID
     */
    public function getTopUpById(string $topUpId): ?WalletTopUp
    {
        return WalletTopUp::findByTopUpId($topUpId);
    }

    /**
     * Get top-up by gateway reference
     */
    public function getTopUpByGatewayReference(string $reference): ?WalletTopUp
    {
        return WalletTopUp::findByGatewayReference($reference);
    }

     /**
     * Get top-up by payment transaction ID
     */
    public function getTopUpByTransactionId(string $transactionId): ?WalletTopUp
    {
        return WalletTopUp::where('payment_transaction_id', $transactionId)->first();
    }
    
    /**
     * Complete a top-up manually (for bank transfers confirmed offline)
     */
    public function completeTopUpManually(string $topUpId, string $bankReference = null): WalletTopUp
    {
        $topUp = WalletTopUp::findByTopUpId($topUpId);

        if (!$topUp) {
            throw new \Exception('Top-up not found');
        }

        if ($topUp->status === 'completed') {
            throw new \Exception('Top-up is already completed');
        }

        return $this->processTopUpCallback($topUpId, [
            'success' => true,
            'gateway_reference' => $bankReference,
            'gateway_response' => ['manual_confirmation' => true],
        ]);
    }
}
