<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\LedgerEntry;
use App\Models\Refund;
use App\Models\MerchantBalance;

use Illuminate\Support\Facades\DB;

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

        $report = [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
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
}
