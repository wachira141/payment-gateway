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
     * Get gateway fee analysis with currency and gateway filtering
     */
    public function getGatewayFeeAnalysis($merchantId, $startDate = null, $endDate = null, $currency = null, $gatewayCode = null): array
    {
        $query = LedgerEntry::where('merchant_id', $merchantId)
            ->whereIn('account_name', ['gateway_processing_fees', 'platform_application_fees']);
        
        if ($startDate) {
            $query->where('posted_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('posted_at', '<=', $endDate);
        }
        if ($currency && $currency !== 'all') {
            $query->where('currency', $currency);
        }

        $entries = $query->get();
        $analysis = [];

        foreach ($entries as $entry) {
            $metadata = $entry->metadata ?? [];
            $entryGatewayCode = $metadata['gateway_code'] ?? 'unknown';
            $paymentMethodType = $metadata['payment_method_type'] ?? 'unknown';
            
            // Filter by gateway code if specified
            if ($gatewayCode && $entryGatewayCode !== $gatewayCode) {
                continue;
            }
            
            $key = "{$entryGatewayCode}_{$paymentMethodType}_{$entry->currency}";
            
            if (!isset($analysis[$key])) {
                $analysis[$key] = [
                    'gateway_code' => $entryGatewayCode,
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

        // Get volume data from revenue entries to calculate accurate rates
        $revenueQuery = LedgerEntry::where('merchant_id', $merchantId)
            ->where('account_name', 'payment_processing_revenue')
            ->whereBetween('posted_at', [$startDate, $endDate]);

        if ($currency && $currency !== 'all') {
            $revenueQuery->where('currency', $currency);
        }

        $revenueEntries = $revenueQuery->get();
        $volumeByGateway = [];

        foreach ($revenueEntries as $entry) {
            $metadata = $entry->metadata ?? [];
            $entryGatewayCode = $metadata['gateway_code'] ?? 'unknown';
            $paymentMethodType = $metadata['payment_method_type'] ?? 'unknown';
            
            if ($gatewayCode && $entryGatewayCode !== $gatewayCode) {
                continue;
            }
            
            $key = "{$entryGatewayCode}_{$paymentMethodType}_{$entry->currency}";
            
            if (!isset($volumeByGateway[$key])) {
                $volumeByGateway[$key] = ['volume' => 0, 'transactions' => 0];
            }
            
            $volumeByGateway[$key]['volume'] += $entry->amount;
            $volumeByGateway[$key]['transactions']++;
        }

        // Calculate accurate rates using actual volume data
        foreach ($analysis as $key => &$data) {
            $volume = $volumeByGateway[$key]['volume'] ?? 0;
            $data['total_volume'] = $volume;
            $data['total_fees'] = $data['processing_fees'] + $data['application_fees'];
            $data['effective_rate'] = $volume > 0 ? $data['total_fees'] / $volume : 0;
            $data['transaction_count'] = $volumeByGateway[$key]['transactions'] ?? $data['transaction_count'];
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

    /*
    * Get multi-currency reconciliation report matching frontend interface
    */
      /**
     * Get multi-currency reconciliation with enhanced currency metadata
     */
    public function getMultiCurrencyReconciliation($merchantId, $accountType = null, $currency = null)
    {
        $query = LedgerEntry::where('merchant_id', $merchantId);

        if ($accountType) {
            $query->where('account_type', $accountType);
        }

        if ($currency && $currency !== 'all') {
            $query->where('currency', $currency);
        }

        $entries = $query->get();
        
        // Group by account type and currency
        $reconciliation = [
            'assets' => [],
            'revenue' => []
        ];

        $currencyTotals = [];
        $allCurrencies = [];
        
        foreach ($entries as $entry) {
            $accountType = $entry->account_type;
            $accountName = $entry->account_name;
            $entryCurrency = $entry->currency;

            if (!in_array($entryCurrency, $allCurrencies)) {
                $allCurrencies[] = $entryCurrency;
            }

            if (!isset($reconciliation[$accountType][$accountName])) {
                $reconciliation[$accountType][$accountName] = [
                    'debits' => 0,
                    'credits' => 0,
                    'balance' => 0,
                    'entry_count' => 0,
                    'currencies' => []
                ];
            }

            $account = &$reconciliation[$accountType][$accountName];
            
            if ($entry->entry_type === 'debit') {
                $account['debits'] += $entry->amount;
            } else {
                $account['credits'] += $entry->amount;
            }
            
            $account['entry_count']++;
            
            if (!in_array($entryCurrency, $account['currencies'])) {
                $account['currencies'][] = $entryCurrency;
            }

            // Track currency totals
            if (!isset($currencyTotals[$entryCurrency])) {
                $currencyTotals[$entryCurrency] = [
                    'debits' => 0, 
                    'credits' => 0,
                    'balance' => 0,
                    'transaction_count' => 0
                ];
            }
            
            if ($entry->entry_type === 'debit') {
                $currencyTotals[$entryCurrency]['debits'] += $entry->amount;
            } else {
                $currencyTotals[$entryCurrency]['credits'] += $entry->amount;
            }
            $currencyTotals[$entryCurrency]['transaction_count']++;
        }

        // Calculate balances based on account type
        foreach (['assets', 'revenue'] as $type) {
            foreach ($reconciliation[$type] as &$account) {
                if ($type === 'assets') {
                    $account['balance'] = $account['debits'] - $account['credits'];
                } else {
                    $account['balance'] = $account['credits'] - $account['debits'];
                }
            }
        }

        // Calculate currency balance differences
        foreach ($currencyTotals as $curr => &$totals) {
            $totals['balance'] = $totals['credits'] - $totals['debits'];
        }

        return [
            'assets' => $reconciliation['assets'],
            'revenue' => $reconciliation['revenue'],
            'currency_summary' => $currencyTotals,
            'available_currencies' => $allCurrencies,
            'total_currencies' => count($allCurrencies),
            'period' => 'Current',
            'generated_at' => now()->toISOString()
        ];
    }
}
