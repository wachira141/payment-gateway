<?php

namespace App\Services;

use App\Models\LedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LedgerValidationService
{
    /**
     * Validate that all transactions balance (debits = credits)
     */
    public function validateTransactionBalance($merchantId, $startDate = null, $endDate = null): array
    {
        $query = LedgerEntry::where('merchant_id', $merchantId);
        
        if ($startDate) {
            $query->where('posted_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('posted_at', '<=', $endDate);
        }

        $entries = $query->get();
        $transactionGroups = $entries->groupBy('transaction_id');
        
        $unbalancedTransactions = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($transactionGroups as $transactionId => $transactionEntries) {
            $debits = $transactionEntries->where('entry_type', 'debit')->sum('amount');
            $credits = $transactionEntries->where('entry_type', 'credit')->sum('amount');
            
            $totalDebits += $debits;
            $totalCredits += $credits;
            
            if (abs($debits - $credits) > 0.01) { // Allow for minor floating point differences
                $unbalancedTransactions[] = [
                    'transaction_id' => $transactionId,
                    'debits' => $debits,
                    'credits' => $credits,
                    'difference' => $debits - $credits,
                    'entries_count' => $transactionEntries->count()
                ];
            }
        }

        return [
            'is_balanced' => empty($unbalancedTransactions) && abs($totalDebits - $totalCredits) < 0.01,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'total_difference' => $totalDebits - $totalCredits,
            'unbalanced_transactions' => $unbalancedTransactions,
            'total_transactions' => $transactionGroups->count(),
            'total_entries' => $entries->count(),
        ];
    }

    /**
     * Get account reconciliation report
     */
    public function getAccountReconciliation($merchantId, $accountType = null, $currency = null): array
    {
        $query = LedgerEntry::where('merchant_id', $merchantId);
        
        if ($accountType) {
            $query->where('account_type', $accountType);
        }
        if ($currency) {
            $query->where('currency', $currency);
        }

        $entries = $query->get();
        $accounts = [];

        foreach ($entries->groupBy(['account_type', 'account_name']) as $type => $accountGroups) {
            foreach ($accountGroups as $name => $accountEntries) {
                $debits = $accountEntries->where('entry_type', 'debit')->sum('amount');
                $credits = $accountEntries->where('entry_type', 'credit')->sum('amount');
                
                // Calculate balance based on account type
                $balance = in_array($type, ['assets', 'fees']) ? $debits - $credits : $credits - $debits;
                
                $accounts[$type][$name] = [
                    'debits' => $debits,
                    'credits' => $credits,
                    'balance' => $balance,
                    'entry_count' => $accountEntries->count(),
                    'currencies' => $accountEntries->pluck('currency')->unique()->values(),
                ];
            }
        }

        return $accounts;
    }

    /**
     * Get gateway fee analysis
     */
    public function getGatewayFeeAnalysis($merchantId, $startDate = null, $endDate = null): array
    {
        $query = LedgerEntry::where('merchant_id', $merchantId)
            ->whereIn('account_name', ['gateway_processing_fees', 'platform_application_fees']);
        
        if ($startDate) {
            $query->where('posted_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('posted_at', '<=', $endDate);
        }

        $entries = $query->get();
        $analysis = [];

        foreach ($entries as $entry) {
            $metadata = $entry->metadata ?? [];
            $gatewayCode = $metadata['gateway_code'] ?? 'unknown';
            $paymentMethodType = $metadata['payment_method_type'] ?? 'unknown';
            
            $key = "{$gatewayCode}_{$paymentMethodType}_{$entry->currency}";
            
            if (!isset($analysis[$key])) {
                $analysis[$key] = [
                    'gateway_code' => $gatewayCode,
                    'payment_method_type' => $paymentMethodType,
                    'currency' => $entry->currency,
                    'processing_fees' => 0,
                    'application_fees' => 0,
                    'total_volume' => 0,
                    'transaction_count' => 0,
                ];
            }
            
            if ($entry->account_name === 'gateway_processing_fees') {
                $analysis[$key]['processing_fees'] += $entry->amount;
            } elseif ($entry->account_name === 'platform_application_fees') {
                $analysis[$key]['application_fees'] += $entry->amount;
            }
            
            $analysis[$key]['transaction_count']++;
        }

        // Calculate averages and totals
        foreach ($analysis as &$data) {
            $data['total_fees'] = $data['processing_fees'] + $data['application_fees'];
            $data['avg_processing_fee'] = $data['transaction_count'] > 0 ? $data['processing_fees'] / $data['transaction_count'] : 0;
            $data['avg_application_fee'] = $data['transaction_count'] > 0 ? $data['application_fees'] / $data['transaction_count'] : 0;
        }

        return array_values($analysis);
    }

    /**
     * Detect suspicious ledger activities
     */
    public function detectAnomalies($merchantId, $threshold = 10000): array
    {
        $largeTransactions = LedgerEntry::where('merchant_id', $merchantId)
            ->where('amount', '>', $threshold)
            ->orderBy('amount', 'desc')
            ->limit(50)
            ->get();

        $duplicateEntries = DB::select("
            SELECT transaction_id, COUNT(*) as entry_count, SUM(amount) as total_amount
            FROM ledger_entries 
            WHERE merchant_id = ? 
            GROUP BY transaction_id 
            HAVING COUNT(*) > 10
            ORDER BY entry_count DESC
            LIMIT 20
        ", [$merchantId]);

        $recentHighVolumeAccounts = DB::select("
            SELECT account_type, account_name, COUNT(*) as entry_count, SUM(amount) as total_amount
            FROM ledger_entries 
            WHERE merchant_id = ? 
            AND posted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY account_type, account_name
            HAVING COUNT(*) > 100
            ORDER BY entry_count DESC
            LIMIT 10
        ", [$merchantId]);

        return [
            'large_transactions' => $largeTransactions->toArray(),
            'high_entry_transactions' => $duplicateEntries,
            'high_volume_accounts' => $recentHighVolumeAccounts,
        ];
    }
}