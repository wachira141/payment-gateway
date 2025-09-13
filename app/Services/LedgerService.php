<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\LedgerEntry;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    /**
     * Record successful payment in ledger
     */
    public function recordPayment(Charge $charge)
    {
        $merchant = $charge->merchant;
        $grossAmount = $charge->amount;
        $feeAmount = $charge->fee_amount;
        $netAmount = $grossAmount - $feeAmount;

        DB::transaction(function () use ($merchant, $charge, $grossAmount, $feeAmount, $netAmount) {
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
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'platform_fees_receivable',
                        'entry_type' => 'debit',
                        'amount' => $feeAmount,
                        'currency' => $charge->currency,
                        'description' => "Platform fee - {$charge->charge_id}",
                    ],
                    [
                        'account_type' => 'revenue',
                        'account_name' => 'payment_processing_revenue',
                        'entry_type' => 'credit',
                        'amount' => $grossAmount,
                        'currency' => $charge->currency,
                        'description' => "Payment processed - {$charge->charge_id}",
                    ],
                ]
            );
        });
    }

    /**
     * Record refund in ledger
     */
    public function recordRefund(Refund $refund)
    {
        $charge = $refund->charge;
        $merchant = $charge->merchant;
        $refundAmount = $refund->amount;
        
        // Calculate fee refund (if applicable)
        $feeRefund = ($refund->amount / $charge->amount) * $charge->fee_amount;

        DB::transaction(function () use ($merchant, $refund, $charge, $refundAmount, $feeRefund) {
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
                        'description' => "Refund processed - {$refund->refund_id}",
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'credit',
                        'amount' => $refundAmount - $feeRefund,
                        'currency' => $refund->currency,
                        'description' => "Refund deducted from balance - {$refund->refund_id}",
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'platform_fees_receivable',
                        'entry_type' => 'credit',
                        'amount' => $feeRefund,
                        'currency' => $refund->currency,
                        'description' => "Fee refund - {$refund->refund_id}",
                    ],
                ]
            );
        });
    }

    /**
     * Record balance settlement (move from pending to available)
     */
    public function recordBalanceSettlement($merchantId, $currency, $amount)
    {
        DB::transaction(function () use ($merchantId, $currency, $amount) {
            LedgerEntry::createTransaction(
                $merchantId,
                null, // No related model for settlement
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_pending',
                        'entry_type' => 'credit',
                        'amount' => $amount,
                        'currency' => $currency,
                        'description' => 'Balance settlement - pending to available',
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'debit',
                        'amount' => $amount,
                        'currency' => $currency,
                        'description' => 'Balance settlement - pending to available',
                    ],
                ]
            );
        });
    }

    /**
     * Record payout in ledger
     */
    public function recordPayout($payout)
    {
        $merchant = $payout->merchant;
        $payoutAmount = $payout->gross_amount;
        $feeAmount = $payout->fee_amount;
        $netAmount = $payout->net_amount;

        DB::transaction(function () use ($merchant, $payout, $payoutAmount, $feeAmount, $netAmount) {
            LedgerEntry::createTransaction(
                $merchant->id,
                $payout,
                [
                    [
                        'account_type' => 'assets',
                        'account_name' => 'merchant_balance_available',
                        'entry_type' => 'credit',
                        'amount' => $payoutAmount,
                        'currency' => $payout->currency,
                        'description' => "Payout processed - {$payout->payout_id}",
                    ],
                    [
                        'account_type' => 'fees',
                        'account_name' => 'payout_processing_fees',
                        'entry_type' => 'debit',
                        'amount' => $feeAmount,
                        'currency' => $payout->currency,
                        'description' => "Payout fee - {$payout->payout_id}",
                    ],
                    [
                        'account_type' => 'assets',
                        'account_name' => 'cash_disbursed',
                        'entry_type' => 'debit',
                        'amount' => $netAmount,
                        'currency' => $payout->currency,
                        'description' => "Cash disbursed - {$payout->payout_id}",
                    ],
                ]
            );
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