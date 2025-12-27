<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\LedgerEntry;
use App\Models\Refund;
use App\Models\MerchantBalance;
use App\Helpers\CurrencyHelper;

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
     * 
     * NOTE: All monetary amounts on the Charge model are already stored in MINOR UNITS.
     * Do NOT call CurrencyHelper::toMinorUnits() on charge amounts.
     */
    public function recordPayment(Charge $charge)
    {
        $merchant = $charge->merchant;
        $currency = $charge->currency;

        // All amounts are already in minor units on the Charge model
        $grossAmount = (int) $charge->amount;
        $processingFee = (int) ($charge->gateway_processing_fee ?? 0);
        $applicationFee = (int) ($charge->platform_application_fee ?? 0);
        $totalFees = $processingFee + $applicationFee;
        $netAmount = $grossAmount - $totalFees;

        // Get gateway info for metadata
        $gatewayCode = $charge->gateway_code ?? 'unknown';
        $paymentMethodType = $charge->payment_method_type ?? 'unknown';

        DB::transaction(function () use ($merchant, $charge, $grossAmount, $processingFee, $applicationFee, $netAmount, $gatewayCode, $paymentMethodType, $currency) {
            // Convert to major units only for metadata display
            $metadata = [
                'gateway_code' => $gatewayCode,
                'payment_method_type' => $paymentMethodType,
                'fee_breakdown' => [
                    'processing_fee' => CurrencyHelper::fromMinorUnits($processingFee, $currency),
                    'application_fee' => CurrencyHelper::fromMinorUnits($applicationFee, $currency),
                    'total_fees' => CurrencyHelper::fromMinorUnits($processingFee + $applicationFee, $currency)
                ]
            ];

            // Create ledger entries - amounts already in minor units
            LedgerEntry::createTransaction(
                $merchant->id,
                $charge,
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_pending',
                        'entry_type' => 'debit',
                        'amount' => $netAmount,
                        'currency' => $currency,
                        'description' => "Payment received - {$charge->charge_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'gateway_processing_fees',
                        'entry_type' => 'debit',
                        'amount' => $processingFee,
                        'currency' => $currency,
                        'description' => "Gateway processing fee - {$charge->charge_id} ({$gatewayCode})",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'platform_application_fees',
                        'entry_type' => 'debit',
                        'amount' => $applicationFee,
                        'currency' => $currency,
                        'description' => "Platform application fee - {$charge->charge_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'revenue',
                        'account_name' => 'payment_processing_revenue',
                        'entry_type' => 'credit',
                        'amount' => $grossAmount,
                        'currency' => $currency,
                        'description' => "Payment processed - {$charge->charge_id} ({$gatewayCode}/{$paymentMethodType})",
                        'metadata' => $metadata,
                    ],
                ]
            );

            // Update merchant balance - add net amount (already in minor units) to pending
            $balance = MerchantBalance::findByMerchantAndCurrency($merchant->id, $currency);
            if (!$balance) {
                $balance = MerchantBalance::createIfNotExists($merchant->id, $currency);
            }

            $balance->creditPending($netAmount);
        });
    }


    /**
     * Record refund in ledger with gateway-aware fee calculations
     * 
     * NOTE: All monetary amounts on the Refund and Charge models are already stored in MINOR UNITS.
     * Do NOT call CurrencyHelper::toMinorUnits() on these amounts.
     */
    public function recordRefund(Refund $refund)
    {
        $charge = $refund->charge;
        $merchant = $charge->merchant;
        $currency = $refund->currency;

        // All amounts are already in minor units
        $refundAmount = (int) $refund->amount;
        $chargeAmount = (int) $charge->amount;

        // Calculate proportional fee refund based on original gateway fees (already in minor units)
        $refundRatio = $chargeAmount > 0 ? $refundAmount / $chargeAmount : 0;
        $processingFeeRefund = (int) round(($charge->gateway_processing_fee ?? 0) * $refundRatio);
        $applicationFeeRefund = (int) round(($charge->platform_application_fee ?? 0) * $refundRatio);
        $totalFeeRefund = $processingFeeRefund + $applicationFeeRefund;
        $netRefund = $refundAmount - $totalFeeRefund;

        $gatewayCode = $charge->gateway_code ?? 'unknown';
        $paymentMethodType = $charge->payment_method_type ?? 'unknown';

        DB::transaction(function () use ($merchant, $refund, $charge, $refundAmount, $processingFeeRefund, $applicationFeeRefund, $netRefund, $gatewayCode, $paymentMethodType, $refundRatio, $totalFeeRefund, $currency) {
            // Convert to major units only for metadata display
            $metadata = [
                'gateway_code' => $gatewayCode,
                'payment_method_type' => $paymentMethodType,
                'original_charge_id' => $charge->id,
                'refund_ratio' => $refundRatio,
                'fee_refund_breakdown' => [
                    'processing_fee_refund' => CurrencyHelper::fromMinorUnits($processingFeeRefund, $currency),
                    'application_fee_refund' => CurrencyHelper::fromMinorUnits($applicationFeeRefund, $currency),
                    'total_fee_refund' => CurrencyHelper::fromMinorUnits($totalFeeRefund, $currency)
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
                        'currency' => $currency,
                        'description' => "Refund processed - {$refund->refund_id} ({$gatewayCode})",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'credit',
                        'amount' => $netRefund,
                        'currency' => $currency,
                        'description' => "Refund deducted from balance - {$refund->refund_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'gateway_processing_fees',
                        'entry_type' => 'credit',
                        'amount' => $processingFeeRefund,
                        'currency' => $currency,
                        'description' => "Processing fee refund - {$refund->refund_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'platform_application_fees',
                        'entry_type' => 'credit',
                        'amount' => $applicationFeeRefund,
                        'currency' => $currency,
                        'description' => "Application fee refund - {$refund->refund_id}",
                        'metadata' => $metadata,
                    ],
                ]
            );

            // Update merchant balance - deduct refund (already in minor units) from available balance
            $balance = MerchantBalance::findByMerchantAndCurrency($merchant->id, $currency);
            if ($balance) {
                $balance->debitAvailable($netRefund);
            }
        });
    }

    /**
     * Record balance settlement (moving from pending to available)
     * Note: $amount should already be in minor units when called from BalanceService
     */
    public function recordBalanceSettlement(MerchantBalance $balance, int $amountMinor)
    {
        $currency = $balance->currency;
        $amountMajor = CurrencyHelper::fromMinorUnits($amountMinor, $currency);

        DB::transaction(function () use ($balance, $amountMinor, $amountMajor, $currency) {
            $metadata = [
                'settlement_type' => 'pending_to_available',
                'merchant_id' => $balance->merchant_id,
                'currency' => $currency,
                'amount_settled' => $amountMajor
            ];

            // Create ledger entries for the settlement (amounts in minor units)
            LedgerEntry::createTransaction(
                $balance->merchant_id,
                $balance, // Use balance as reference
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_pending',
                        'entry_type' => 'credit',
                        'amount' => $amountMinor,
                        'currency' => $currency,
                        'description' => "Balance settlement - moved to available",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'debit',
                        'amount' => $amountMinor,
                        'currency' => $currency,
                        'description' => "Balance settlement - from pending",
                        'metadata' => $metadata,
                    ],
                ]
            );

            // Update the merchant balance (already in minor units)
            $balance->movePendingToAvailable($amountMinor);
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
    // public function getAssetAccountsSummary($merchantId, $currency = null)
    // {
    //     $query = LedgerEntry::where('merchant_id', $merchantId)
    //         ->where('account_type', 'assets');

    //     if ($currency && $currency !== 'all') {
    //         $query->where('currency', $currency);
    //     }

    //     $entries = $query->get()->groupBy('account_name');
    //     $assets = [];

    //     foreach ($entries as $accountName => $accountEntries) {
    //         $debits = $accountEntries->where('entry_type', 'debit')->sum('amount');
    //         $credits = $accountEntries->where('entry_type', 'credit')->sum('amount');
    //         $balance = $debits - $credits; // Assets: debits increase balance

    //         $assets[$accountName] = [
    //             'debits' => $debits,
    //             'credits' => $credits,
    //             'balance' => $balance,
    //             'entry_count' => $accountEntries->count(),
    //             'currencies' => $accountEntries->pluck('currency')->unique()->values()->toArray()
    //         ];
    //     }

    //     return $assets;
    // }
    public function getAssetAccountsSummary($merchantId, $currency = null)
    {
        $assetAccounts = [
            'merchant_balance_available',
            'merchant_balance_pending',
            'gateway_processing_fees',
            'platform_application_fees'
        ];

        $summary = [];
        foreach ($assetAccounts as $accountName) {
            $balance = $this->getAccountBalance($merchantId, 'assets', $accountName, $currency);
            $summary[$accountName] = $balance;
        }

        return $summary;
    }

    /**
     * Get revenue accounts summary for financial reports
     */
    // public function getRevenueAccountsSummary($merchantId, $currency = null)
    // {
    //     $query = LedgerEntry::where('merchant_id', $merchantId)
    //         ->where('account_type', 'revenue');

    //     if ($currency && $currency !== 'all') {
    //         $query->where('currency', $currency);
    //     }

    //     $entries = $query->get()->groupBy('account_name');
    //     $revenue = [];

    //     foreach ($entries as $accountName => $accountEntries) {
    //         $debits = $accountEntries->where('entry_type', 'debit')->sum('amount');
    //         $credits = $accountEntries->where('entry_type', 'credit')->sum('amount');
    //         $balance = $credits - $debits; // Revenue: credits increase balance

    //         $revenue[$accountName] = [
    //             'debits' => $debits,
    //             'credits' => $credits,
    //             'balance' => $balance,
    //             'entry_count' => $accountEntries->count(),
    //             'currencies' => $accountEntries->pluck('currency')->unique()->values()->toArray()
    //         ];
    //     }

    //     return $revenue;
    // }
    public function getRevenueAccountsSummary($merchantId, $currency = null)
    {
        $revenueAccounts = [
            'payment_processing_revenue'
        ];

        $summary = [];
        foreach ($revenueAccounts as $accountName) {
            $balance = $this->getAccountBalance($merchantId, 'revenue', $accountName, $currency);
            $summary[$accountName] = $balance;
        }

        return $summary;
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

    

    // ==================== WALLET LEDGER ENTRIES ====================

    /**
     * Record wallet top-up in ledger
     */
    public function recordWalletTopUp($topUp, $transaction)
    {
        $wallet = $topUp->wallet;
        $merchant = $wallet->merchant;

        $metadata = [
            'top_up_id' => $topUp->top_up_id,
            'wallet_id' => $wallet->wallet_id,
            'method' => $topUp->method,
            'gateway_type' => $topUp->gateway_type,
            'gateway_reference' => $topUp->gateway_reference,
        ];

        DB::transaction(function () use ($merchant, $topUp, $wallet, $transaction, $metadata) {
            LedgerEntry::createTransaction(
                $merchant->id,
                $topUp,
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance',
                        'entry_type' => 'debit',
                        'amount' => $topUp->amount,
                        'currency' => $topUp->currency,
                        'description' => "Wallet top-up via {$topUp->method} - {$topUp->top_up_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'liabilities',
                        'account_name' => 'wallet_funds_payable',
                        'entry_type' => 'credit',
                        'amount' => $topUp->amount,
                        'currency' => $topUp->currency,
                        'description' => "Wallet funds received - {$topUp->top_up_id}",
                        'metadata' => $metadata,
                    ],
                ]
            );
        });
    }

    /**
     * Record wallet disbursement in ledger
     */
    public function recordWalletDisbursement($transaction, $disbursement = null)
    {
        $wallet = $transaction->wallet;
        $merchant = $wallet->merchant;

        $metadata = [
            'transaction_id' => $transaction->transaction_id,
            'wallet_id' => $wallet->wallet_id,
            'disbursement_id' => $disbursement?->disbursement_id ?? null,
        ];

        DB::transaction(function () use ($merchant, $transaction, $wallet, $metadata) {
            LedgerEntry::createTransaction(
                $merchant->id,
                $transaction,
                [
                    [
                        'account_type' => 'liabilities',
                        'account_name' => 'wallet_funds_payable',
                        'entry_type' => 'debit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Wallet disbursement - {$transaction->transaction_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance',
                        'entry_type' => 'credit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Wallet balance debited - {$transaction->transaction_id}",
                        'metadata' => $metadata,
                    ],
                ]
            );
        });
    }

    /**
     * Record wallet-to-wallet transfer in ledger
     */
    public function recordWalletTransfer($debitTransaction, $creditTransaction)
    {
        $fromWallet = $debitTransaction->wallet;
        $toWallet = $creditTransaction->wallet;
        $merchant = $fromWallet->merchant;

        $metadata = [
            'from_wallet_id' => $fromWallet->wallet_id,
            'to_wallet_id' => $toWallet->wallet_id,
            'debit_transaction_id' => $debitTransaction->transaction_id,
            'credit_transaction_id' => $creditTransaction->transaction_id,
            'reference' => $debitTransaction->reference,
        ];

        DB::transaction(function () use ($merchant, $debitTransaction, $creditTransaction, $metadata) {
            // Record the transfer as internal movement (no external liability change)
            LedgerEntry::createTransaction(
                $merchant->id,
                $debitTransaction,
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance',
                        'entry_type' => 'credit',
                        'amount' => $debitTransaction->amount,
                        'currency' => $debitTransaction->currency,
                        'description' => "Transfer out to wallet - {$debitTransaction->reference}",
                        'metadata' => array_merge($metadata, ['direction' => 'out']),
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance',
                        'entry_type' => 'debit',
                        'amount' => $creditTransaction->amount,
                        'currency' => $creditTransaction->currency,
                        'description' => "Transfer in from wallet - {$creditTransaction->reference}",
                        'metadata' => array_merge($metadata, ['direction' => 'in']),
                    ],
                ]
            );
        });
    }

    /**
     * Record balance sweep to wallet in ledger
     */
    public function recordBalanceSweepToWallet($transaction, $merchantBalance)
    {
        $wallet = $transaction->wallet;
        $merchant = $wallet->merchant;

        $metadata = [
            'transaction_id' => $transaction->transaction_id,
            'wallet_id' => $wallet->wallet_id,
            'source' => 'merchant_balance',
            'reference' => $transaction->reference,
        ];

        DB::transaction(function () use ($merchant, $transaction, $merchantBalance, $metadata) {
            LedgerEntry::createTransaction(
                $merchant->id,
                $transaction,
                [
                    // Debit from merchant balance (reduce available)
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'credit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Sweep to wallet - {$transaction->reference}",
                        'metadata' => $metadata,
                    ],
                    // Credit to wallet balance
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance',
                        'entry_type' => 'debit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Sweep from merchant balance - {$transaction->reference}",
                        'metadata' => $metadata,
                    ],
                ]
            );
        });
    }

    /**
     * Record wallet hold in ledger
     */
    public function recordWalletHold($transaction)
    {
        $wallet = $transaction->wallet;
        $merchant = $wallet->merchant;

        $metadata = [
            'transaction_id' => $transaction->transaction_id,
            'wallet_id' => $wallet->wallet_id,
            'reference' => $transaction->reference,
        ];

        DB::transaction(function () use ($merchant, $transaction, $metadata) {
            LedgerEntry::createTransaction(
                $merchant->id,
                $transaction,
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance',
                        'entry_type' => 'credit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Funds held - {$transaction->transaction_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance_held',
                        'entry_type' => 'debit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Funds on hold - {$transaction->transaction_id}",
                        'metadata' => $metadata,
                    ],
                ]
            );
        });
    }

    /**
     * Record wallet release in ledger
     */
    public function recordWalletRelease($transaction)
    {
        $wallet = $transaction->wallet;
        $merchant = $wallet->merchant;

        $metadata = [
            'transaction_id' => $transaction->transaction_id,
            'wallet_id' => $wallet->wallet_id,
            'reference' => $transaction->reference,
        ];

        DB::transaction(function () use ($merchant, $transaction, $metadata) {
            LedgerEntry::createTransaction(
                $merchant->id,
                $transaction,
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance_held',
                        'entry_type' => 'credit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Funds released - {$transaction->transaction_id}",
                        'metadata' => $metadata,
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'wallet_balance',
                        'entry_type' => 'debit',
                        'amount' => $transaction->amount,
                        'currency' => $transaction->currency,
                        'description' => "Funds available - {$transaction->transaction_id}",
                        'metadata' => $metadata,
                    ],
                ]
            );
        });
    }

    /**
     * Get wallet balance from ledger
     */
    public function getWalletLedgerBalance($merchantId, $currency = null)
    {
        $walletBalance = $this->getAccountBalance($merchantId, 'assets', 'wallet_balance', $currency);
        $heldBalance = $this->getAccountBalance($merchantId, 'assets', 'wallet_balance_held', $currency);

        return [
            'available' => $walletBalance - $heldBalance,
            'held' => $heldBalance,
            'total' => $walletBalance,
            'currency' => $currency,
        ];
    }
}
