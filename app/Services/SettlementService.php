<?php

namespace App\Services;

use App\Models\Settlement;
use App\Models\Charge;
use App\Models\Refund;
use App\Helpers\CurrencyHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SettlementService extends BaseService
{
    /**
     * Get settlements for a merchant with filters
     */
    public function getSettlementsForMerchant(string $merchantId, array $filters = [])
    {
        $query = Settlement::where('merchant_id', $merchantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $filters['limit'] ?? 15;
        return $query->paginate($perPage);
    }

    /**
     * Get a settlement by ID for a merchant
     */
    public function getSettlementById(string $settlementId, string $merchantId): ?array
    {
        $settlement = Settlement::findByIdAndMerchant($settlementId, $merchantId);
        return $settlement ? $settlement : null;
    }

    /**
     * Get settlement transactions
     */
    public function getSettlementTransactions(string $settlementId, string $merchantId): array
    {
        $settlement = Settlement::findByIdAndMerchant($settlementId, $merchantId);
        
        if (!$settlement) {
            throw new \Exception('Settlement not found');
        }

        return $settlement['transactions'] ?? [];
    }

    /**
     * Create automatic settlement for merchant
     */
    public function createAutoSettlement(string $merchantId, string $currency): array
    {
        // Get all unsettled charges and refunds
        $charges = Charge::getUnsettledForMerchant($merchantId, $currency);
        $refunds = Refund::getUnsettledForMerchant($merchantId, $currency);

        if ($charges->isEmpty() && $refunds->isEmpty()) {
            throw new \Exception('No unsettled transactions found');
        }

        // Calculate settlement amounts
        $grossAmount = $charges->sum('amount_captured');
        $refundAmount = $refunds->sum('amount');
        $feeAmount = $charges->sum('application_fee_amount');
        $netAmount = $grossAmount - $refundAmount - $feeAmount;

        if ($netAmount <= 0) {
            throw new \Exception('Net settlement amount must be positive');
        }

        // Create settlement record
        $settlementData = [
            'id' => 'sett_' . Str::random(24),
            'merchant_id' => $merchantId,
            'currency' => $currency,
            'status' => 'pending',
            'gross_amount' => $grossAmount,
            'refund_amount' => $refundAmount,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'transaction_count' => $charges->count() + $refunds->count(),
            'settlement_date' => now()->addBusinessDay(), // Next business day
            'transactions' => $this->formatSettlementTransactions($charges, $refunds),
            'metadata' => [
                'settlement_type' => 'automatic',
                'created_by' => 'system'
            ]
        ];

        $settlement = Settlement::create($settlementData);

        // Mark transactions as settled
        $this->markTransactionsAsSettled($charges, $refunds, $settlement['id']);

        return $settlement;
    }

    /**
     * Process a settlement
     */
    public function processSettlement(string $settlementId): array
    {
        $settlement = Settlement::findById($settlementId);
        
        if (!$settlement) {
            throw new \Exception('Settlement not found');
        }

        if ($settlement['status'] !== 'pending') {
            throw new \Exception('Settlement is not in processable state');
        }

        // Simulate settlement processing
        $success = $this->processSettlementWithBankingSystem($settlement);
        
        if ($success) {
            return Settlement::updateById($settlementId, [
                'status' => 'completed',
                'processed_at' => now(),
                'bank_reference' => 'bank_ref_' . Str::random(12)
            ]);
        } else {
            return Settlement::updateById($settlementId, [
                'status' => 'failed',
                'failure_reason' => 'Banking system error'
            ]);
        }
    }

    /**
     * Format transactions for settlement record
     */
    private function formatSettlementTransactions(Collection $charges, Collection $refunds): array
    {
        $transactions = [];

        foreach ($charges as $charge) {
            $transactions[] = [
                'type' => 'charge',
                'id' => $charge['id'],
                'amount' => $charge['amount_captured'],
                'fee' => $charge['application_fee_amount'],
                'created_at' => $charge['created_at']
            ];
        }

        foreach ($refunds as $refund) {
            $transactions[] = [
                'type' => 'refund',
                'id' => $refund['id'],
                'amount' => -$refund['amount'], // Negative for refunds
                'fee' => 0,
                'created_at' => $refund['created_at']
            ];
        }

        // Sort by creation date
        usort($transactions, function ($a, $b) {
            return strtotime($a['created_at']) <=> strtotime($b['created_at']);
        });

        return $transactions;
    }

    /**
     * Mark transactions as settled
     */
    private function markTransactionsAsSettled(Collection $charges, Collection $refunds, string $settlementId): void
    {
        // Mark charges as settled
        foreach ($charges as $charge) {
            Charge::updateById($charge['id'], [
                'settlement_id' => $settlementId,
                'settled_at' => now()
            ]);
        }

        // Mark refunds as settled
        foreach ($refunds as $refund) {
            Refund::updateById($refund['id'], [
                'settlement_id' => $settlementId,
                'settled_at' => now()
            ]);
        }
    }

    /**
     * Simulate settlement processing with banking system
     */
    private function processSettlementWithBankingSystem(array $settlement): bool
    {
        // Simulate banking system integration
        // In real implementation, this would integrate with banking APIs
        
        // Simulate 98% success rate
        return rand(1, 100) > 2;
    }

    /**
     * Get settlement statistics for merchant
     */
    public function getSettlementStatistics(string $merchantId, array $filters = []): array
    {
        $query = Settlement::where('merchant_id', $merchantId);

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

      
        $settlements = $query->get();
        $completedSettlements = $settlements->where('status', 'completed');
        
        // Get currency for formatting (use first settlement's currency or default)
        $currency = $completedSettlements->first()['currency'] ?? 'USD';
        $totalAmount = $completedSettlements->sum('net_amount');
        $totalFees = $completedSettlements->sum('fee_amount');
        $avgAmount = $completedSettlements->avg('net_amount') ?? 0;

        return [
            'total_settlements' => $settlements->count(),
            'completed_settlements' => $completedSettlements->count(),
            'failed_settlements' => $settlements->where('status', 'failed')->count(),
            'pending_settlements' => $settlements->where('status', 'pending')->count(),
            'total_settlement_amount' => $totalAmount,
            'total_settlement_formatted' => CurrencyHelper::format($totalAmount, $currency),
            'total_fees_collected' => $totalFees,
            'total_fees_formatted' => CurrencyHelper::format($totalFees, $currency),
            'average_settlement_amount' => $avgAmount,
            'average_settlement_formatted' => CurrencyHelper::format((int) $avgAmount, $currency),
            'total_transactions_settled' => $completedSettlements->sum('transaction_count'),
        ];
    }

    /**
     * Generate settlement report for merchant
     */
    public function generateSettlementReport(string $merchantId, array $filters = []): array
    {
        $settlements = $this->getSettlementsForMerchant($merchantId, $filters);
        $statistics = $this->getSettlementStatistics($merchantId, $filters);

        // Group settlements by currency
        $settlementsByCurrency = $settlements->groupBy('currency');

        $currencyBreakdown = [];
        foreach ($settlementsByCurrency as $currency => $currencySettlements) {
            $currencyBreakdown[$currency] = [
                'count' => $currencySettlements->count(),
                'total_amount' => $currencySettlements->where('status', 'completed')->sum('net_amount'),
                'total_fees' => $currencySettlements->where('status', 'completed')->sum('fee_amount'),
            ];
        }

        return [
            'summary' => $statistics,
            'currency_breakdown' => $currencyBreakdown,
            'settlements' => $settlements->take(100)->toArray(), // Limit for performance
            'period' => [
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null
            ]
        ];
    }

    /**
     * Schedule automatic settlements for all eligible merchants
     */
    public function scheduleAutoSettlements(): array
    {
        // This would typically be called by a scheduled job
        // Get all merchants with auto-settlement enabled
        $merchantsWithAutoSettlement = $this->getMerchantsWithAutoSettlement();
        
        $results = [];
        
        foreach ($merchantsWithAutoSettlement as $merchant) {
            try {
                $currencies = $this->getEligibleCurrenciesForSettlement($merchant['id']);
                
                foreach ($currencies as $currency) {
                    $settlement = $this->createAutoSettlement($merchant['id'], $currency);
                    $results[] = [
                        'merchant_id' => $merchant['id'],
                        'currency' => $currency,
                        'settlement_id' => $settlement['id'],
                        'status' => 'success'
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'merchant_id' => $merchant['id'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Get merchants with auto-settlement enabled
     */
    private function getMerchantsWithAutoSettlement(): array
    {
        // This would query the merchant settings
        // For now, return empty array as placeholder
        return [];
    }

    /**
     * Get currencies eligible for settlement for a merchant
     */
    private function getEligibleCurrenciesForSettlement(string $merchantId): array
    {
        // Get currencies with unsettled transactions above minimum threshold
        $currencies = [];
        
        $supportedCurrencies = ['USD', 'EUR', 'GBP', 'KES', 'UGX', 'TZS', 'NGN', 'GHS', 'ZAR'];
        
        foreach ($supportedCurrencies as $currency) {
            $charges = Charge::getUnsettledForMerchant($merchantId, $currency);
            $totalAmount = $charges->sum('amount_captured');
            
            // Only settle if above minimum threshold
            $minimumThreshold = $this->getMinimumSettlementThreshold($currency);
            
            if ($totalAmount >= $minimumThreshold) {
                $currencies[] = $currency;
            }
        }
        
        return $currencies;
    }

    /**
     * Get minimum settlement threshold for currency
     */
    private function getMinimumSettlementThreshold(string $currency): int
    {
        return match ($currency) {
            'USD' => 1000,    // $10.00
            'EUR' => 1000,    // €10.00
            'GBP' => 1000,    // £10.00
            'KES' => 100000,  // KSh 1,000
            'UGX' => 3700000, // UGX 37,000
            'TZS' => 2300000, // TZS 23,000
            'NGN' => 400000,  // NGN 4,000
            'GHS' => 6000,    // GHS 60
            'ZAR' => 15000,   // ZAR 150
            default => 1000
        };
    }
}