<?php

namespace App\Services;

use App\Models\MerchantBalance;
use App\Models\LedgerEntry;
use App\Services\BaseService;
use Illuminate\Support\Collection;

class BalanceService extends BaseService
{
    /**
     * Get merchant balance for specific currency
     */
    public function getMerchantBalance(string $merchantId, string $currency): ?MerchantBalance
    {
        return MerchantBalance::findByMerchantAndCurrency($merchantId, $currency);
    }

    /**
     * Get all balances for merchant
     */
    public function getAllMerchantBalances(string $merchantId): Collection
    {
        return MerchantBalance::where('merchant_id', $merchantId)->get();
    }

    /**
     * Create or get existing balance
     */
    public function getOrCreateBalance(string $merchantId, string $currency): MerchantBalance
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);

        if (!$balance) {
            $balance = MerchantBalance::create([
                'merchant_id' => $merchantId,
                'currency' => $currency,
                'available_amount' => 0,
                'pending_amount' => 0,
                'reserved_amount' => 0,
                'total_volume' => 0,
            ]);
        }

        return $balance;
    }

    /**
     * Check if merchant has sufficient balance
     */
    public function hasSufficientBalance(string $merchantId, string $currency, float $amount, string $type = 'available'): bool
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);

        if (!$balance) {
            return false;
        }

        switch ($type) {
            case 'available':
                return $balance->hasSufficientAvailable($amount);
            case 'pending':
                return $balance->pending_amount >= $amount;
            case 'reserved':
                return $balance->reserved_amount >= $amount;
            default:
                return false;
        }
    }

    /**
     * Get balance history from ledger
     */
    public function getBalanceHistory(string $merchantId, ?string $currency = null, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $query = LedgerEntry::where('merchant_id', $merchantId)
            ->whereIn('account_name', ['merchant_balance_available', 'merchant_balance_pending', 'merchant_balance_reserved']);

        if ($currency) {
            $query->where('currency', $currency);
        }

        if ($dateFrom) {
            $query->where('posted_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('posted_at', '<=', $dateTo);
        }

        return $query->orderBy('posted_at', 'desc')->get();
    }

    /**
     * Get balance summary
     */
    public function getBalanceSummary(string $merchantId): array
    {
        $balances = $this->getAllMerchantBalances($merchantId);

        $summary = [
            'total_currencies' => $balances->count(),
            'balances_by_currency' => [],
            'total_available' => 0,
            'total_pending' => 0,
            'total_reserved' => 0,
        ];

        foreach ($balances as $balance) {
            $summary['balances_by_currency'][$balance->currency] = [
                'available' => $balance->available_amount,
                'pending' => $balance->pending_amount,
                'reserved' => $balance->reserved_amount,
                'total' => $balance->getTotalBalance(),
            ];

            // Convert to base currency for totals (simplified - in real app you'd use exchange rates)
            $summary['total_available'] += $balance->available_amount;
            $summary['total_pending'] += $balance->pending_amount;
            $summary['total_reserved'] += $balance->reserved_amount;
        }

        return $summary;
    }

    /**
     * Settlement - move from pending to available
     * This should be called by a scheduled job after payment clearing period
     */
    public function settlePendingBalance(string $merchantId, string $currency, ?float $amount = null): MerchantBalance
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);

        if (!$balance) {
            throw new \Exception('Merchant balance not found');
        }

        $settleAmount = $amount ?? $balance->pending_amount;

        if ($settleAmount > $balance->pending_amount) {
            throw new \Exception('Insufficient pending balance');
        }

        if ($settleAmount <= 0) {
            return $balance;
        }

        // This will be handled by LedgerService to ensure proper accounting
        app(LedgerService::class)->recordBalanceSettlement($balance, $settleAmount);

        return $balance->fresh();
    }

    /**
     * Reserve balance for pending transaction
     */
    public function reserveBalance(string $merchantId, string $currency, float $amount, string $reason, ?string $referenceId = null): MerchantBalance
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);

        if (!$balance || !$balance->hasSufficientAvailable($amount)) {
            throw new \Exception('Insufficient available balance for reservation');
        }

        $balance->reserve($amount);

        return $balance->fresh();
    }

    /**
     * Release reserved balance (cancel reservation)
     */
    public function releaseReservedBalance(string $merchantId, string $currency, float $amount, string $reason, ?string $referenceId = null): MerchantBalance
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);

        if (!$balance || $balance->reserved_amount < $amount) {
            throw new \Exception('Insufficient reserved balance to release');
        }

        $balance->releaseReserved($amount);

        return $balance->fresh();
    }

    /**
     * Debit from available balance (for payouts, etc.)
     */
    public function debitAvailableBalance(string $merchantId, string $currency, float $amount, string $reason, ?string $referenceId = null): MerchantBalance
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);

        if (!$balance || !$balance->hasSufficientAvailable($amount)) {
            throw new \Exception('Insufficient available balance');
        }

        $balance->debitAvailable($amount);

        return $balance->fresh();
    }

    /**
     * Get balance for currency
     */
    public function getBalanceForCurrency(string $merchantId, string $currency): array
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);

        if (!$balance) {
            return [
                'available' => 0,
                'pending' => 0,
                'reserved' => 0,
                'total' => 0
            ];
        }

        return [
            'available' => $balance->available_amount,
            'pending' => $balance->pending_amount,
            'reserved' => $balance->reserved_amount,
            'total' => $balance->getTotalBalance()
        ];
    }

    /**
     * Get merchant balances for API response
     */
    public function getFormattedBalances(string $merchantId): array
    {
        $balances = $this->getAllMerchantBalances($merchantId);

        return $balances->map(function ($balance) {
            return [
                'currency' => $balance->currency,
                'available' => $balance->available_amount,
                'pending' => $balance->pending_amount,
                'reserved' => $balance->reserved_amount,
                'total_volume' => $balance->total_volume,
                'last_transaction_at' => $balance->last_transaction_at ? $balance->last_transaction_at->timestamp : null,
            ];
        })->toArray();
    }

    /**
     * Process reserved balance (convert reservation to actual debit)
     * Used when a reserved transaction completes (e.g., payout processed)
     */
    public function processReservedBalance(string $merchantId, string $currency, float $amount, string $reason, ?string $referenceId = null): MerchantBalance
    {
        try {
            //code...
            $balance = $this->getMerchantBalance($merchantId, $currency);
            
        if (!$balance || $balance->reserved_amount < $amount) {
            throw new \Exception('Insufficient reserved balance to process');
        }

        // Deduct from reserved amount (completing the transaction)
        $balance->reserved_amount -= $amount;
        $balance->last_transaction_at = now();
        $balance->save();

        // Record the transaction in ledger
        // Record the transaction in ledger using double-entry bookkeeping
        LedgerEntry::createTransaction(
            $merchantId,
            $balance, // Related model
            [
                [
                    'account_type' => 'assets',
                    'account_name' => 'merchant_balance_available',
                    'entry_type' => 'debit',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => "{$reason} - Reserved balance processed",
                    'metadata' => ['reference_id' => $referenceId, 'operation' => 'process_reserved'],
                ],
                [
                    'account_type' => 'assets',
                    'account_name' => 'merchant_balance_reserved',
                    'entry_type' => 'credit',
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => "{$reason} - Reserved balance processed",
                    'metadata' => ['reference_id' => $referenceId, 'operation' => 'process_reserved'],
                    ]
                    ]
                );
                
                return $balance->fresh();
            } catch (\Exception $e) {
                throw new \Exception('Error processing reserved balance: ' . $e->getMessage());
            }
    }
}
