<?php

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\Customer;
use App\Models\SystemActivity;
use App\Models\MerchantBalance;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AnalyticsService extends BaseService
{
    /**
     * Get dashboard metrics for a merchant
     */
    public function getDashboardMetrics(string $merchantId, string $currency = 'USD'): array
    {
        $now = Carbon::now();
        $lastMonth = $now->copy()->subMonth();
        $twoMonthsAgo = $now->copy()->subMonths(2);

        // Current period metrics
        $currentMetrics = $this->calculatePeriodMetrics($merchantId, $lastMonth, $now, $currency);
        $previousMetrics = $this->calculatePeriodMetrics($merchantId, $twoMonthsAgo, $lastMonth, $currency);

        return [
            'total_volume' => $currentMetrics['volume'],
            'total_transactions' => $currentMetrics['transactions'],
            'success_rate' => $currentMetrics['success_rate'],
            'active_customers' => $currentMetrics['customers'],
            'volume_change' => $this->calculatePercentageChange($previousMetrics['volume'], $currentMetrics['volume']),
            'transactions_change' => $this->calculatePercentageChange($previousMetrics['transactions'], $currentMetrics['transactions']),
            'success_rate_change' => $this->calculatePercentageChange($previousMetrics['success_rate'], $currentMetrics['success_rate']),
            'customers_change' => $this->calculatePercentageChange($previousMetrics['customers'], $currentMetrics['customers']),
            'currency' => $currency
        ];
    }

    /**
     * Calculate metrics for a specific period
     */
    private function calculatePeriodMetrics(string $merchantId, Carbon $start, Carbon $end, string $currency): array
    {
        $paymentIntents = PaymentIntent::where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $totalVolume = $paymentIntents->where('status', 'succeeded')->sum('amount') / 100; // Convert from cents
        $totalTransactions = $paymentIntents->count();
        $successfulTransactions = $paymentIntents->where('status', 'succeeded')->count();
        $successRate = $totalTransactions > 0 ? ($successfulTransactions / $totalTransactions) * 100 : 0;

        // Get unique customers who made payments in this period
        $activeCustomers = $paymentIntents->where('status', 'succeeded')
            ->pluck('customer_id')
            ->unique()
            ->filter()
            ->count();

        return [
            'volume' => $totalVolume,
            'transactions' => $totalTransactions,
            'success_rate' => round($successRate, 2),
            'customers' => $activeCustomers
        ];
    }

    /**
     * Get system activity for a merchant
     */
    public function getSystemActivity(string $merchantId, int $limit = 10): Collection
    {
        return SystemActivity::where('merchant_id', $merchantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'message' => $activity->message,
                    'status' => $activity->status,
                    'created_at' => $activity->created_at->toISOString()
                ];
            });
    }

    /**
     * Get chart data for analytics dashboard
     */
    public function getChartData(string $merchantId, string $period, string $currency): array
    {
        $endDate = Carbon::now();
        $startDate = $this->getStartDateForPeriod($period, $endDate);
        $groupBy = $this->getGroupByForPeriod($period);

        $data = PaymentIntent::where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw("
                DATE({$groupBy}(created_at)) as period,
                SUM(CASE WHEN status = 'succeeded' THEN amount ELSE 0 END) / 100 as volume,
                COUNT(*) as transactions,
                ROUND(
                    (COUNT(CASE WHEN status = 'succeeded' THEN 1 END) * 100.0 / COUNT(*)), 2
                ) as success_rate
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $data->map(function ($item) {
            return [
                'period' => $item->period,
                'volume' => (float) $item->volume,
                'transactions' => (int) $item->transactions,
                'success_rate' => (float) $item->success_rate
            ];
        })->toArray();
    }

    /**
     * Get start date based on period
     */
    private function getStartDateForPeriod(string $period, Carbon $endDate): Carbon
    {
        switch ($period) {
            case '7d':
                return $endDate->copy()->subDays(7);
            case '30d':
                return $endDate->copy()->subDays(30);
            case '90d':
                return $endDate->copy()->subDays(90);
            case '1y':
                return $endDate->copy()->subYear();
            default:
                return $endDate->copy()->subDays(30);
        }
    }

    /**
     * Get SQL group by clause for period
     */
    private function getGroupByForPeriod(string $period): string
    {
        switch ($period) {
            case '7d':
                return 'DATE'; // Group by day
            case '30d':
                return 'DATE'; // Group by day
            case '90d':
                return 'WEEK'; // Group by week
            case '1y':
                return 'MONTH'; // Group by month
            default:
                return 'DATE';
        }
    }

    /**
     * Calculate percentage change between two values
     */
    private function calculatePercentageChange($previous, $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}