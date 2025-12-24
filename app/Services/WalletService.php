<?php

namespace App\Services;

use App\Models\MerchantWallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService extends BaseService
{
    // ==================== WALLET MANAGEMENT ====================

    /**
     * Create a new wallet
     */
    public function createWallet(string $merchantId, string $currency, string $type = 'operating', array $options = []): MerchantWallet
    {
        $this->logActivity('wallet.create', [
            'merchant_id' => $merchantId,
            'currency' => $currency,
            'type' => $type,
        ]);

        return MerchantWallet::create(array_merge([
            'merchant_id' => $merchantId,
            'currency' => strtoupper($currency),
            'type' => $type,
            'status' => 'active',
            'name' => $options['name'] ?? ucfirst($type) . ' Wallet (' . strtoupper($currency) . ')',
            'available_balance' => 0,
            'locked_balance' => 0,
            'total_topped_up' => 0,
            'total_spent' => 0,
            'daily_withdrawal_limit' => $options['daily_withdrawal_limit'] ?? null,
            'monthly_withdrawal_limit' => $options['monthly_withdrawal_limit'] ?? null,
            'minimum_balance' => $options['minimum_balance'] ?? 0,
        ], $options));
    }

    /**
     * Get wallet by ID
     */
    public function getWallet(string $walletId): ?MerchantWallet
    {
        return MerchantWallet::findByWalletId($walletId);
    }

    /**
     * Get wallet by internal UUID
     */
    public function getWalletById(string $id): ?MerchantWallet
    {
        return MerchantWallet::find($id);
    }

    /**
     * Get wallet by merchant and currency
     */
    public function getWalletByMerchantAndCurrency(string $merchantId, string $currency, string $type = 'operating'): ?MerchantWallet
    {
        return MerchantWallet::findByMerchantAndCurrency($merchantId, $currency, $type);
    }

    /**
     * Get all wallets for a merchant
     */
    public function getMerchantWallets(string $merchantId): Collection
    {
        return MerchantWallet::getForMerchant($merchantId);
    }

    /**
     * Get or create wallet
     */
    public function getOrCreateWallet(string $merchantId, string $currency, string $type = 'operating', array $options = []): MerchantWallet
    {
        $wallet = $this->getWalletByMerchantAndCurrency($merchantId, $currency, $type);

        if (!$wallet) {
            $wallet = $this->createWallet($merchantId, $currency, $type, $options);
        }

        return $wallet;
    }

    /**
     * Update wallet settings
     */
    public function updateWalletSettings(string $walletId, array $settings): MerchantWallet
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        $allowedSettings = [
            'name',
            'daily_withdrawal_limit',
            'monthly_withdrawal_limit',
            'minimum_balance',
            'auto_sweep_enabled',
            'auto_sweep_config',
            'metadata',
        ];

        $updateData = array_intersect_key($settings, array_flip($allowedSettings));

        $wallet->update($updateData);

        $this->logActivity('wallet.update', [
            'wallet_id' => $walletId,
            'settings' => array_keys($updateData),
        ]);

        return $wallet->fresh();
    }

    /**
     * Freeze wallet
     */
    public function freezeWallet(string $walletId, string $reason): bool
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        $result = $wallet->freeze($reason);

        $this->logActivity('wallet.freeze', [
            'wallet_id' => $walletId,
            'reason' => $reason,
        ]);

        return $result;
    }

    /**
     * Unfreeze wallet
     */
    public function unfreezeWallet(string $walletId): bool
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        $result = $wallet->unfreeze();

        $this->logActivity('wallet.unfreeze', [
            'wallet_id' => $walletId,
        ]);

        return $result;
    }

    // ==================== BALANCE OPERATIONS ====================

    /**
     * Get wallet balance
     */
    public function getBalance(string $walletId): array
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        return [
            'wallet_id' => $wallet->wallet_id,
            'currency' => $wallet->currency,
            'available_balance' => (float) $wallet->available_balance,
            'locked_balance' => (float) $wallet->locked_balance,
            'total_balance' => $wallet->getTotalBalance(),
            'minimum_balance' => (float) $wallet->minimum_balance,
            'withdrawable_balance' => max(0, $wallet->available_balance - $wallet->minimum_balance),
            'total_topped_up' => (float) $wallet->total_topped_up,
            'total_spent' => (float) $wallet->total_spent,
            'status' => $wallet->status,
            'last_activity_at' => $wallet->last_activity_at?->toISOString(),
        ];
    }

    /**
     * Credit wallet
     */
    public function creditWallet(string $walletId, float $amount, string $type, $sourceModel, string $description): WalletTransaction
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        return DB::transaction(function () use ($wallet, $amount, $type, $sourceModel, $description) {
            $balanceBefore = $wallet->available_balance;
            $balanceAfter = $balanceBefore + $amount;

            // Create transaction record
            $transaction = WalletTransaction::createTransaction($wallet, [
                'type' => $type,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'status' => 'completed',
                'source_type' => $sourceModel ? get_class($sourceModel) : null,
                'source_id' => $sourceModel?->id,
                'description' => $description,
                'completed_at' => now(),
            ]);

            // Credit the wallet
            if ($type === 'top_up') {
                $wallet->recordTopUp($amount);
            } else {
                $wallet->credit($amount);
            }

            // Update balance_after
            $transaction->update(['balance_after' => $wallet->fresh()->available_balance]);

            $this->logActivity('wallet.credit', [
                'wallet_id' => $wallet->wallet_id,
                'amount' => $amount,
                'type' => $type,
                'transaction_id' => $transaction->transaction_id,
            ]);

            return $transaction;
        });
    }

    /**
     * Debit wallet
     */
    public function debitWallet(string $walletId, float $amount, string $type, $sourceModel, string $description): WalletTransaction
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        // Validate can debit
        $canDebit = $this->canDebit($walletId, $amount);
        if (!$canDebit['allowed']) {
            throw new \Exception($canDebit['reason']);
        }

        return DB::transaction(function () use ($wallet, $amount, $type, $sourceModel, $description) {
            $balanceBefore = $wallet->available_balance;

            // Create transaction record
            $transaction = WalletTransaction::createTransaction($wallet, [
                'type' => $type,
                'direction' => 'debit',
                'amount' => $amount,
                'status' => 'completed',
                'source_type' => $sourceModel ? get_class($sourceModel) : null,
                'source_id' => $sourceModel?->id,
                'description' => $description,
                'completed_at' => now(),
            ]);

            // Debit the wallet
            $wallet->debit($amount);

            // Update balance_after
            $transaction->update(['balance_after' => $wallet->fresh()->available_balance]);

            $this->logActivity('wallet.debit', [
                'wallet_id' => $wallet->wallet_id,
                'amount' => $amount,
                'type' => $type,
                'transaction_id' => $transaction->transaction_id,
            ]);

            return $transaction;
        });
    }

    /**
     * Hold funds
     */
    public function holdFunds(string $walletId, float $amount, string $reason, string $referenceId = null): WalletTransaction
    {
        $wallet = $this->getWalletById($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        if (!$wallet->canDebit($amount)) {
            throw new \Exception('Insufficient balance to hold');
        }

        return DB::transaction(function () use ($wallet, $amount, $reason, $referenceId) {
            $balanceBefore = $wallet->available_balance;
            $balanceAfter = $wallet->available_balance;

            // Create transaction record
            //NB: NEED TO VERIFY AND CHECK balance* and the source
            $transaction = WalletTransaction::createTransaction($wallet, [
                'type' => 'hold',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source_type' => \App\Models\Disbursement::class,
                'source_id' => $wallet->id,
                'status' => 'completed',
                'reference' => $referenceId,
                'description' => $reason,
                'completed_at' => now(),
            ]);

            // Hold the funds
            $wallet->hold($amount);

            // Update balance_after
            $transaction->update(['balance_after' => $wallet->fresh()->available_balance]);

            $this->logActivity('wallet.hold', [
                'wallet_id' => $wallet->wallet_id,
                'amount' => $amount,
                'reference' => $referenceId,
            ]);

            return $transaction;
        });
    }

    /**
     * Release funds
     */
    public function releaseFunds(string $walletId, float $amount, string $reason, string $referenceId = null): WalletTransaction
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        if ($wallet->locked_balance < $amount) {
            throw new \Exception('Insufficient locked balance to release');
        }

        return DB::transaction(function () use ($wallet, $amount, $reason, $referenceId) {
            $balanceBefore = $wallet->available_balance;

            // Create transaction record
            $transaction = WalletTransaction::createTransaction($wallet, [
                'type' => 'release',
                'direction' => 'credit',
                'amount' => $amount,
                'status' => 'completed',
                'reference' => $referenceId,
                'description' => $reason,
                'completed_at' => now(),
            ]);

            // Release the funds
            $wallet->release($amount);

            // Update balance_after
            $transaction->update(['balance_after' => $wallet->fresh()->available_balance]);

            $this->logActivity('wallet.release', [
                'wallet_id' => $wallet->wallet_id,
                'amount' => $amount,
                'reference' => $referenceId,
            ]);

            return $transaction;
        });
    }

    // ==================== VALIDATION ====================

    /**
     * Check if can debit amount
     */
    public function canDebit(string $walletId, float $amount): array
    {
        $wallet = $this->getWalletById($walletId);

        if (!$wallet) {
            return ['allowed' => false, 'reason' => 'Wallet not found'];
        }

        if (!$wallet->isActive()) {
            return ['allowed' => false, 'reason' => 'Wallet is not active'];
        }

        if (!$wallet->canDebit($amount)) {
            return [
                'allowed' => false,
                'reason' => 'Insufficient available balance',
            ];
        }

        // Check withdrawal limits
        $limitCheck = $wallet->checkWithdrawalLimit($amount);
        if (!$limitCheck['allowed']) {
            return $limitCheck;
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Validate withdrawal limits
     */
    public function validateWithdrawalLimits(string $walletId, float $amount): array
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            return ['valid' => false, 'reason' => 'Wallet not found'];
        }

        return $wallet->checkWithdrawalLimit($amount);
    }

    // ==================== TRANSACTION HISTORY ====================

    /**
     * Get transactions for wallet
     */
    public function getTransactions(string $walletId, array $filters = []): LengthAwarePaginator
    {
        $wallet = $this->getWallet($walletId);

        if (!$wallet) {
            throw new \Exception('Wallet not found');
        }

        $query = WalletTransaction::where('wallet_id', $wallet->id);

        // Apply filters
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
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
     * Get transaction by ID
     */
    public function getTransactionById(string $transactionId): ?WalletTransaction
    {
        return WalletTransaction::findByTransactionId($transactionId);
    }

    // ==================== BALANCE SUMMARY ====================

    /**
     * Get wallet summary for merchant
     */
    public function getWalletSummary(string $merchantId): array
    {
        $wallets = $this->getMerchantWallets($merchantId);

        $summary = [
            'wallets' => [],
            'totals_by_currency' => [],
            'wallet_count' => $wallets->count(),
        ];

        foreach ($wallets as $wallet) {
            $summary['wallets'][] = [
                'wallet_id' => $wallet->wallet_id,
                'name' => $wallet->name,
                'currency' => $wallet->currency,
                'type' => $wallet->type,
                'status' => $wallet->status,
                'available_balance' => (float) $wallet->available_balance,
                'locked_balance' => (float) $wallet->locked_balance,
                'total_balance' => $wallet->getTotalBalance(),
            ];

            if (!isset($summary['totals_by_currency'][$wallet->currency])) {
                $summary['totals_by_currency'][$wallet->currency] = [
                    'available' => 0,
                    'locked' => 0,
                    'total' => 0,
                ];
            }

            $summary['totals_by_currency'][$wallet->currency]['available'] += (float) $wallet->available_balance;
            $summary['totals_by_currency'][$wallet->currency]['locked'] += (float) $wallet->locked_balance;
            $summary['totals_by_currency'][$wallet->currency]['total'] += $wallet->getTotalBalance();
        }

        return $summary;
    }
}
