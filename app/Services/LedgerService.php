<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\LedgerEntry;
use App\Models\Refund;
use App\Models\MerchantBalance;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class LedgerService
{
    private GatewayPricingService $gatewayPricingService;

    public function __construct(GatewayPricingService $gatewayPricingService)
    {
        $this->gatewayPricingService = $gatewayPricingService;
    }
    /**
     * Record successful payment in ledger using gateway-based pricing
     */
    public function recordPayment(Charge $charge, array $feeCalculation)
    {
        $merchant = $charge->merchant;
        $grossAmount = $charge->amount;

        // Get gateway-specific fee breakdown
        $gatewayCode = $charge->gateway_code ?? 'unknown';
        $paymentMethodType = $charge->payment_method_type ?? 'unknown';

        $processingFee = $feeCalculation['processing_fee'];
        $applicationFee = $feeCalculation['application_fee'];
        $totalFees = $feeCalculation['total_fees'];
        $netAmount = $grossAmount - $totalFees;

        DB::transaction(function () use ($merchant, $charge, $grossAmount, $processingFee, $applicationFee, $totalFees, $netAmount, $gatewayCode, $paymentMethodType) {
            $metadata = [
                'gateway_code' => $gatewayCode,
                'payment_method_type' => $paymentMethodType,
                'fee_breakdown' => [
                    'processing_fee' => $processingFee,
                    'application_fee' => $applicationFee,
                    'total_fees' => $totalFees
                ]
            ];

            // Create ledger entries
            LedgerEntry::createTransaction(
                $merchant->id,
                $charge,
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_pending',
                        'entry_type' => 'debit',
                        'amount' => $netAmount,
                        'currency' => $charge->currency,
                        'description' => "Payment received - {$charge->charge_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'gateway_processing_fees',
                        'entry_type' => 'debit',
                        'amount' => $processingFee,
                        'currency' => $charge->currency,
                        'description' => "Gateway processing fee - {$charge->charge_id} ({$gatewayCode})",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'platform_application_fees',
                        'entry_type' => 'debit',
                        'amount' => $applicationFee,
                        'currency' => $charge->currency,
                        'description' => "Platform application fee - {$charge->charge_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'revenue',
                        'account_name' => 'payment_processing_revenue',
                        'entry_type' => 'credit',
                        'amount' => $grossAmount,
                        'currency' => $charge->currency,
                        'description' => "Payment processed - {$charge->charge_id} ({$gatewayCode}/{$paymentMethodType})",
                        'metadata' => $metadata,
                    ],
                ]
            );

            // Update merchant balance - add net amount to pending
            $balance = MerchantBalance::findByMerchantAndCurrency($merchant->id, $charge->currency);
            //if not exists, create it
            if (!$balance) {
                $balance = MerchantBalance::createIfNotExists($merchant->id, $charge->currency);
            }

            $balance->creditPending($netAmount);
        });

        // Update charge with calculated fees for future reference
        $charge->update([
            'fee_amount' => $totalFees,
            'gateway_processing_fee' => $processingFee,
            'platform_application_fee' => $applicationFee,
        ]);
    }

    /**
     * Record refund in ledger with gateway-aware fee calculations
     */
    public function recordRefund(Refund $refund)
    {
        $charge = $refund->charge;
        $merchant = $charge->merchant;
        $refundAmount = $refund->amount;

        // Calculate proportional fee refund based on original gateway fees
        $refundRatio = $refund->amount / $charge->amount;
        $processingFeeRefund = ($charge->gateway_processing_fee ?? 0) * $refundRatio;
        $applicationFeeRefund = ($charge->platform_application_fee ?? 0) * $refundRatio;
        $totalFeeRefund = $processingFeeRefund + $applicationFeeRefund;

        $gatewayCode = $charge->gateway_code ?? 'unknown';
        $paymentMethodType = $charge->payment_method_type ?? 'unknown';

        DB::transaction(function () use ($merchant, $refund, $charge, $refundAmount, $processingFeeRefund, $applicationFeeRefund, $totalFeeRefund, $gatewayCode, $paymentMethodType) {
            $metadata = [
                'gateway_code' => $gatewayCode,
                'payment_method_type' => $paymentMethodType,
                'original_charge_id' => $charge->id,
                'refund_ratio' => $refund->amount / $charge->amount,
                'fee_refund_breakdown' => [
                    'processing_fee_refund' => $processingFeeRefund,
                    'application_fee_refund' => $applicationFeeRefund,
                    'total_fee_refund' => $totalFeeRefund
                ]
            ];

            LedgerEntry::createTransaction(
                $merchant->id,
                $refund,
                [
                    [
                        'account_type' => 'revenue',
                        'account_name' => 'payment_processing_revenue',
                        'entry_type' => 'debit',
                        'amount' => $refundAmount,
                        'currency' => $refund->currency,
                        'description' => "Refund processed - {$refund->refund_id} ({$gatewayCode})",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'credit',
                        'amount' => $refundAmount - $totalFeeRefund,
                        'currency' => $refund->currency,
                        'description' => "Refund deducted from balance - {$refund->refund_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'gateway_processing_fees',
                        'entry_type' => 'credit',
                        'amount' => $processingFeeRefund,
                        'currency' => $refund->currency,
                        'description' => "Processing fee refund - {$refund->refund_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'platform_application_fees',
                        'entry_type' => 'credit',
                        'amount' => $applicationFeeRefund,
                        'currency' => $refund->currency,
                        'description' => "Application fee refund - {$refund->refund_id}",
                        'metadata' => $metadata,
                    ],
                ]
            );

            // Update merchant balance - deduct refund from available balance
            $balance = MerchantBalance::findByMerchantAndCurrency($merchant->id, $refund->currency);
            if ($balance) {
                $balance->debitAvailable($refundAmount - $totalFeeRefund);
            }
        });
    }

    /**
     * Record balance settlement (moving from pending to available)
     */
    public function recordBalanceSettlement(MerchantBalance $balance, float $amount)
    {
        DB::transaction(function () use ($balance, $amount) {
            $metadata = [
                'settlement_type' => 'pending_to_available',
                'merchant_id' => $balance->merchant_id,
                'currency' => $balance->currency,
                'amount_settled' => $amount
            ];

            // Create ledger entries for the settlement
            LedgerEntry::createTransaction(
                $balance->merchant_id,
                $balance, // Use balance as reference
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_pending',
                        'entry_type' => 'credit',
                        'amount' => $amount,
                        'currency' => $balance->currency,
                        'description' => "Balance settlement - moved to available",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'debit',
                        'amount' => $amount,
                        'currency' => $balance->currency,
                        'description' => "Balance settlement - from pending",
                        'metadata' => $metadata,
                    ],
                ]
            );

            // Update the merchant balance
            $balance->movePendingToAvailable($amount);
        });
    }

    /**
     * Get account balance
     */
    public function getAccountBalance($merchantId, $accountType, $accountName, $currency = null)
    {
        return LedgerEntry::getAccountBalance($merchantId, $accountType, $accountName, $currency);
    }

    /**
     * Generate financial report
     */
    public function generateFinancialReport($merchantId, $startDate, $endDate, $currency = null)
    {
        $query = LedgerEntry::where('merchant_id', $merchantId)
            ->whereBetween('posted_at', [$startDate, $endDate]);

        if ($currency) {
            $query->where('currency', $currency);
        }

        $entries = $query->get();
        // Convert to Carbon instances if they're strings
        $startDateTime = is_string($startDate) ? Carbon::parse($startDate) : $startDate;
        $endDateTime = is_string($endDate) ? Carbon::parse($endDate) : $endDate;

        $report = [
            'period' => [
                'start' => $startDate->toDateString(), // e.g. "2025-08-18"
                'end'   => $endDate->toDateString(),   // e.g. "2025-09-18"
            ],
            'currency' => $currency,
            'accounts' => [],
            'totals' => [
                'total_debits' => 0,
                'total_credits' => 0,
            ],
        ];

        foreach ($entries->groupBy(['account_type', 'account_name']) as $accountType => $accounts) {
            foreach ($accounts as $accountName => $accountEntries) {
                $debits = $accountEntries->where('entry_type', 'debit')->sum('amount');
                $credits = $accountEntries->where('entry_type', 'credit')->sum('amount');
                $balance = $this->calculateAccountBalance($accountType, $debits, $credits);

                $report['accounts'][$accountType][$accountName] = [
                    'debits' => $debits,
                    'credits' => $credits,
                    'balance' => $balance,
                    'entry_count' => $accountEntries->count(),
                ];

                $report['totals']['total_debits'] += $debits;
                $report['totals']['total_credits'] += $credits;
            }
        }

        return $report;
    }

    /**
     * Calculate account balance based on account type
     */
    protected function calculateAccountBalance($accountType, $debits, $credits)
    {
        // Assets and expenses: debits increase balance
        if (in_array($accountType, ['assets', 'fees'])) {
            return $debits - $credits;
        }

        // Liabilities, equity, and revenue: credits increase balance
        return $credits - $debits;
    }

    /**
     * Generate financial reports for all currencies
     */
    public function generateMultiCurrencyFinancialReport($merchantId, $startDate, $endDate)
    {
        // Get all currencies used by this merchant
        $currencies = LedgerEntry::where('merchant_id', $merchantId)
            ->whereBetween('posted_at', [$startDate, $endDate])
            ->distinct('currency')
            ->pluck('currency')
            ->toArray();

        $reports = [];
        foreach ($currencies as $currency) {
            $reports[] = $this->generateFinancialReport($merchantId, $startDate, $endDate, $currency);
        }

        return $reports;
    }

    /**
     * Get account balances for a specific currency
     */
    public function getAccountBalancesByCurrency($merchantId, $currency)
    {
        $accountTypes = [
            'merchant_balance_available' => 'assets',
            'merchant_balance_pending' => 'assets',
            'gateway_processing_fees' => 'assets',
            'platform_application_fees' => 'assets',
            'payment_processing_revenue' => 'revenue'
        ];

        $balances = [];
        foreach ($accountTypes as $accountName => $accountType) {
            $balance = $this->getAccountBalance($merchantId, $accountType, $accountName, $currency);
            $balances[] = [
                'account_type' => $accountName,
                'currency' => $currency,
                'balance' => $balance,
                'last_updated' => now()->toISOString(),
            ];
        }

        return $balances;
    }

   /**
     * Get account balances for all currencies with currency summary
     */
    public function getAllAccountBalances($merchantId)
    {
        // Get all currencies for this merchant
        $currencies = LedgerEntry::where('merchant_id', $merchantId)
            ->distinct('currency')
            ->pluck('currency')
            ->toArray();

        $allBalances = [];
        $currencySummary = [];
        
        foreach ($currencies as $currency) {
            $currencyBalances = $this->getAccountBalancesByCurrency($merchantId, $currency);
            $allBalances = array_merge($allBalances, $currencyBalances);
            
            // Categorize balances
            $merchantBalances = [];
            $feeBalances = [];
            $revenueBalances = [];
            
            foreach ($currencyBalances as $balance) {
                switch ($balance['account_type']) {
                    case 'merchant_balance_available':
                    case 'merchant_balance_pending':
                        $merchantBalances[] = $balance;
                        break;
                    case 'gateway_processing_fees':
                    case 'platform_application_fees':
                        $feeBalances[] = $balance;
                        break;
                    case 'payment_processing_revenue':
                        $revenueBalances[] = $balance;
                        break;
                }
            }
            
            // Calculate balances by category
            $merchantNetBalance = array_sum(array_column($merchantBalances, 'balance'));
            $totalFees = array_sum(array_column($feeBalances, 'balance'));
            $totalRevenue = array_sum(array_column($revenueBalances, 'balance'));
            $totalAccountingBalance = array_sum(array_column($currencyBalances, 'balance'));
            
            $currencySummary[$currency] = [
                'currency' => $currency,
                'merchant_net_balance' => $merchantNetBalance,
                'total_fees' => $totalFees,
                'total_revenue' => $totalRevenue,
                'total_accounting_balance' => $totalAccountingBalance,
                'account_count' => count($currencyBalances),
                'accounts' => $currencyBalances,
                'merchant_accounts' => $merchantBalances,
                'fee_accounts' => $feeBalances,
                'revenue_accounts' => $revenueBalances
            ];
        }

        return [
            'balances' => $allBalances,
            'currency_summary' => $currencySummary,
            'total_currencies' => count($currencies),
            'available_currencies' => $currencies
        ];
    }


    /**
     * Get assets accounts summary for financial reports
     */
    public function getAssetAccountsSummary($merchantId, $currency = null)
    {
        $query = LedgerEntry::where('merchant_id', $merchantId)
            ->where('account_type', 'assets');

        if ($currency && $currency !== 'all') {
            $query->where('currency', $currency);
        }

        $entries = $query->get()->groupBy('account_name');
        $assets = [];

        foreach ($entries as $accountName => $accountEntries) {
            $debits = $accountEntries->where('entry_type', 'debit')->sum('amount');
            $credits = $accountEntries->where('entry_type', 'credit')->sum('amount');
            $balance = $debits - $credits; // Assets: debits increase balance

            $assets[$accountName] = [
                'debits' => $debits,
                'credits' => $credits,
                'balance' => $balance,
                'entry_count' => $accountEntries->count(),
                'currencies' => $accountEntries->pluck('currency')->unique()->values()->toArray()
            ];
        }

        return $assets;
    }

    /**
     * Get revenue accounts summary for financial reports
     */
    public function getRevenueAccountsSummary($merchantId, $currency = null)
    {
        $query = LedgerEntry::where('merchant_id', $merchantId)
            ->where('account_type', 'revenue');

        if ($currency && $currency !== 'all') {
            $query->where('currency', $currency);
        }

        $entries = $query->get()->groupBy('account_name');
        $revenue = [];

        foreach ($entries as $accountName => $accountEntries) {
            $debits = $accountEntries->where('entry_type', 'debit')->sum('amount');
            $credits = $accountEntries->where('entry_type', 'credit')->sum('amount');
            $balance = $credits - $debits; // Revenue: credits increase balance

            $revenue[$accountName] = [
                'debits' => $debits,
                'credits' => $credits,
                'balance' => $balance,
                'entry_count' => $accountEntries->count(),
                'currencies' => $accountEntries->pluck('currency')->unique()->values()->toArray()
            ];
        }

        return $revenue;
    }

     /**
     * Get merchant balances summary (only merchant-facing balances)
     */
    public function getMerchantBalancesSummary($merchantId)
    {
        // Get all currencies for this merchant
        $currencies = LedgerEntry::where('merchant_id', $merchantId)
            ->distinct('currency')
            ->pluck('currency')
            ->toArray();

        $merchantBalances = [];
        $currencySummary = [];
        
        foreach ($currencies as $currency) {
            // Only include merchant-facing balances
            $merchantAccountTypes = [
                'merchant_balance_available' => 'assets',
                'merchant_balance_pending' => 'assets',
            ];

            $balances = [];
            foreach ($merchantAccountTypes as $accountName => $accountType) {
                $balance = $this->getAccountBalance($merchantId, $accountType, $accountName, $currency);
                $balances[] = [
                    'account_type' => $accountName,
                    'currency' => $currency,
                    'balance' => $balance,
                    'last_updated' => now()->toISOString(),
                ];
            }

            $merchantBalances = array_merge($merchantBalances, $balances);
            
            // Calculate merchant net balance (available + pending)
            $availableBalance = $balances[0]['balance'] ?? 0;
            $pendingBalance = $balances[1]['balance'] ?? 0;
            $merchantNetBalance = $availableBalance + $pendingBalance;
            
            $currencySummary[$currency] = [
                'currency' => $currency,
                'merchant_net_balance' => $merchantNetBalance,
                'available_balance' => $availableBalance,
                'pending_balance' => $pendingBalance,
                'account_count' => count($balances),
                'accounts' => $balances
            ];
        }

        return [
            'balances' => $merchantBalances,
            'currency_summary' => $currencySummary,
            'total_currencies' => count($currencies),
            'available_currencies' => $currencies
        ];
    }
}
