<?php

namespace App\Services;

use App\Models\MerchantBalance;
use App\Models\LedgerEntry;
use App\Services\BaseService;

class BalanceService extends BaseService
{
    /**
     * Get merchant balance for specific currency
     */
    public function getMerchantBalance($merchantId, $currency)
    {
        return MerchantBalance::findByMerchantAndCurrency($merchantId, $currency);
    }

    /**
     * Get all balances for merchant
     */
    public function getAllMerchantBalances($merchantId)
    {
        return MerchantBalance::getByMerchant($merchantId);
    }

    /**
     * Create balance if not exists
     */
    public function createBalanceIfNotExists($merchantId, $currency)
    {
        return MerchantBalance::createIfNotExists($merchantId, $currency);
    }

    /**
     * Add to merchant balance
     */
    public function addToBalance($merchantId, $currency, $amount, $type = 'available', $description = null, $referenceId = null)
    {
        try {
            $balance = $this->createBalanceIfNotExists($merchantId, $currency);
            
            // Add to balance
            $balance->addAmount($amount, $type);
            
            // Create ledger entry
            $this->createLedgerEntry([
                'merchant_id' => $merchantId,
                'currency' => $currency,
                'amount' => $amount,
                'type' => 'credit',
                'balance_type' => $type,
                'description' => $description ?? "Added to {$type} balance",
                'reference_id' => $referenceId,
                'balance_after' => $balance->fresh()->{$type . '_balance'},
            ]);
            
            return $balance->fresh();
        } catch (\Exception $e) {
            $this->handleException($e, 'Add to balance');
        }
    }

    /**
     * Subtract from merchant balance
     */
    public function subtractFromBalance($merchantId, $currency, $amount, $type = 'available', $description = null, $referenceId = null)
    {
        try {
            $balance = $this->getMerchantBalance($merchantId, $currency);
            
            if (!$balance) {
                throw new \Exception('Merchant balance not found');
            }
            
            // Subtract from balance
            $balance->subtractAmount($amount, $type);
            
            // Create ledger entry
            $this->createLedgerEntry([
                'merchant_id' => $merchantId,
                'currency' => $currency,
                'amount' => -$amount,
                'type' => 'debit',
                'balance_type' => $type,
                'description' => $description ?? "Subtracted from {$type} balance",
                'reference_id' => $referenceId,
                'balance_after' => $balance->fresh()->{$type . '_balance'},
            ]);
            
            return $balance->fresh();
        } catch (\Exception $e) {
            $this->handleException($e, 'Subtract from balance');
        }
    }

    /**
     * Transfer between balance types
     */
    public function transferBalance($merchantId, $currency, $amount, $fromType, $toType, $description = null, $referenceId = null)
    {
        try {
            $balance = $this->getMerchantBalance($merchantId, $currency);
            
            if (!$balance) {
                throw new \Exception('Merchant balance not found');
            }
            
            // Transfer balance
            $balance->transferBalance($amount, $fromType, $toType);
            
            // Create ledger entries for both sides
            $this->createLedgerEntry([
                'merchant_id' => $merchantId,
                'currency' => $currency,
                'amount' => -$amount,
                'type' => 'transfer_out',
                'balance_type' => $fromType,
                'description' => $description ?? "Transfer from {$fromType} to {$toType}",
                'reference_id' => $referenceId,
                'balance_after' => $balance->fresh()->{$fromType . '_balance'},
            ]);
            
            $this->createLedgerEntry([
                'merchant_id' => $merchantId,
                'currency' => $currency,
                'amount' => $amount,
                'type' => 'transfer_in',
                'balance_type' => $toType,
                'description' => $description ?? "Transfer from {$fromType} to {$toType}",
                'reference_id' => $referenceId,
                'balance_after' => $balance->fresh()->{$toType . '_balance'},
            ]);
            
            return $balance->fresh();
        } catch (\Exception $e) {
            $this->handleException($e, 'Transfer balance');
        }
    }

    /**
     * Check if merchant has sufficient balance
     */
    public function hasSufficientBalance($merchantId, $currency, $amount, $type = 'available')
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);
        
        if (!$balance) {
            return false;
        }
        
        return $balance->hasSufficientBalance($amount);
    }

    /**
     * Get balance history
     */
    public function getBalanceHistory($merchantId, $currency = null, $dateFrom = null, $dateTo = null)
    {
        $query = LedgerEntry::where('merchant_id', $merchantId);
        
        if ($currency) {
            $query->where('currency', $currency);
        }
        
        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }
        
        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Get balance summary
     */
    public function getBalanceSummary($merchantId)
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
                'available' => $balance->available_balance,
                'pending' => $balance->pending_balance,
                'reserved' => $balance->reserved_balance,
                'total' => $balance->total_balance,
            ];
            
            // Convert to base currency for totals (simplified - in real app you'd use exchange rates)
            $summary['total_available'] += $balance->available_balance;
            $summary['total_pending'] += $balance->pending_balance;
            $summary['total_reserved'] += $balance->reserved_balance;
        }
        
        return $summary;
    }

    /**
     * Process payment to balance
     */
    public function processPaymentToBalance($merchantId, $currency, $amount, $chargeId = null, $commissionAmount = 0)
    {
        try {
            $netAmount = $amount - $commissionAmount;
            
            // Add to pending balance first
            $balance = $this->addToBalance(
                $merchantId,
                $currency,
                $netAmount,
                'pending',
                'Payment received - pending settlement',
                $chargeId
            );
            
            // If there's a commission, record it separately
            if ($commissionAmount > 0) {
                $this->createLedgerEntry([
                    'merchant_id' => $merchantId,
                    'currency' => $currency,
                    'amount' => -$commissionAmount,
                    'type' => 'commission',
                    'balance_type' => 'pending',
                    'description' => 'Commission deducted',
                    'reference_id' => $chargeId,
                    'balance_after' => $balance->pending_balance,
                ]);
            }
            
            return $balance;
        } catch (\Exception $e) {
            $this->handleException($e, 'Process payment to balance');
        }
    }

    /**
     * Settlement - move from pending to available
     */
    public function settlePendingBalance($merchantId, $currency, $amount = null)
    {
        try {
            $balance = $this->getMerchantBalance($merchantId, $currency);
            
            if (!$balance) {
                throw new \Exception('Merchant balance not found');
            }
            
            $settleAmount = $amount ?? $balance->pending_balance;
            
            if ($settleAmount > $balance->pending_balance) {
                throw new \Exception('Insufficient pending balance');
            }
            
            return $this->transferBalance(
                $merchantId,
                $currency,
                $settleAmount,
                'pending',
                'available',
                'Settlement - funds now available',
                $this->generateReferenceId('settlement_')
            );
        } catch (\Exception $e) {
            $this->handleException($e, 'Settle pending balance');
        }
    }

    /**
     * Create ledger entry
     */
    private function createLedgerEntry(array $data)
    {
        return LedgerEntry::createRecord([
            'entry_id' => $this->generateReferenceId('le_'),
            'merchant_id' => $data['merchant_id'],
            'currency' => $data['currency'],
            'amount' => $data['amount'],
            'type' => $data['type'],
            'balance_type' => $data['balance_type'],
            'description' => $data['description'],
            'reference_id' => $data['reference_id'],
            'balance_after' => $data['balance_after'],
            'metadata' => $data['metadata'] ?? [],
        ]);
    }

   /**
     * Reserve balance for pending transaction
     */
    public function reserveBalance(string $merchantId, string $currency, int $amount, string $reason, string $referenceId): array
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);
        
        if (!$balance || $balance->available_balance < $amount) {
            throw new \Exception('Insufficient balance available for reservation');
        }

        // Add to reserved balance, subtract from available
        $balance->reserved_balance = ($balance->reserved_balance ?? 0) + $amount;
        $balance->available_balance -= $amount;
        $balance->save();

        // Create ledger entry for reservation
        $this->createLedgerEntry([
            'merchant_id' => $merchantId,
            'currency' => $currency,
            'amount' => -$amount,
            'type' => 'reserve',
            'balance_type' => 'reserved',
            'description' => $reason,
            'reference_id' => $referenceId,
            'balance_after' => $balance->reserved_balance,
            'metadata' => ['action' => 'reserve']
        ]);

        return $balance->fresh()->toArray();
    }

    /**
     * Release reserved balance (cancel reservation)
     */
    public function releaseReservedBalance(string $merchantId, string $currency, int $amount, string $reason, string $referenceId): array
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);
        
        if (!$balance || ($balance->reserved_balance ?? 0) < $amount) {
            throw new \Exception('Insufficient reserved balance to release');
        }

        // Remove from reserved balance, add back to available
        $balance->reserved_balance -= $amount;
        $balance->available_balance += $amount;
        $balance->save();

        // Create ledger entry for release
        $this->createLedgerEntry([
            'merchant_id' => $merchantId,
            'currency' => $currency,
            'amount' => $amount,
            'type' => 'release',
            'balance_type' => 'available',
            'description' => $reason,
            'reference_id' => $referenceId,
            'balance_after' => $balance->available_balance,
            'metadata' => ['action' => 'release_reservation']
        ]);

        return $balance->fresh()->toArray();
    }

    /**
     * Process reserved balance (convert reservation to actual transaction)
     */
    public function processReservedBalance(string $merchantId, string $currency, int $amount, string $reason, string $referenceId): array
    {
        $balance = $this->getMerchantBalance($merchantId, $currency);
        
        if (!$balance || ($balance->reserved_balance ?? 0) < $amount) {
            throw new \Exception('Insufficient reserved balance to process');
        }

        // Remove from reserved balance only (amount already deducted from available when reserved)
        $balance->reserved_balance -= $amount;
        $balance->save();

        // Create ledger entry for processing
        $this->createLedgerEntry([
            'merchant_id' => $merchantId,
            'currency' => $currency,
            'amount' => -$amount,
            'type' => 'debit',
            'balance_type' => 'reserved',
            'description' => $reason,
            'reference_id' => $referenceId,
            'balance_after' => $balance->reserved_balance,
            'metadata' => ['action' => 'process_reservation']
        ]);

        return $balance->fresh()->toArray();
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
            'available' => $balance->available_balance,
            'pending' => $balance->pending_balance,
            'reserved' => $balance->reserved_balance ?? 0,
            'total' => $balance->available_balance + $balance->pending_balance + ($balance->reserved_balance ?? 0)
        ];
    }
}