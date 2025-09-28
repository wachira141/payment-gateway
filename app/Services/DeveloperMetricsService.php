<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\AppWebhook;
use App\Models\PaymentIntent;
use App\Models\SystemActivity;
use Carbon\Carbon;

class DeveloperMetricsService extends BaseService
{
    /**
     * Get developer-specific metrics for a merchant
     */
    public function getDeveloperMetrics(string $merchantId): array
    {
        $now = Carbon::now();
        $yesterday = $now->copy()->subDay();
        $twoDaysAgo = $now->copy()->subDays(2);

        return [
            'api_calls_24h' => $this->getApiCallsCount($merchantId, $yesterday, $now),
            'api_calls_change' => $this->getApiCallsChange($merchantId, $twoDaysAgo, $yesterday, $yesterday, $now),
            'active_api_keys' => $this->getActiveApiKeysCount($merchantId),
            'total_webhooks' => $this->getTotalWebhooksCount($merchantId),
            'active_webhooks' => $this->getActiveWebhooksCount($merchantId),
            'success_rate' => $this->getApiSuccessRate($merchantId),
        ];
    }

    /**
     * Get API calls count for a period
     */
    private function getApiCallsCount(string $merchantId, Carbon $start, Carbon $end): int
    {
        // This would typically come from API access logs
        // For now, we'll use payment intents as a proxy for API activity
        return PaymentIntent::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * Calculate API calls change percentage
     */
    private function getApiCallsChange(string $merchantId, Carbon $prevStart, Carbon $prevEnd, Carbon $currStart, Carbon $currEnd): float
    {
        $previousCalls = $this->getApiCallsCount($merchantId, $prevStart, $prevEnd);
        $currentCalls = $this->getApiCallsCount($merchantId, $currStart, $currEnd);

        if ($previousCalls == 0) {
            return $currentCalls > 0 ? 100.0 : 0.0;
        }

        return round((($currentCalls - $previousCalls) / $previousCalls) * 100, 2);
    }

    /**
     * Get active API keys count
     */
    private function getActiveApiKeysCount(string $merchantId): int
    {
        return ApiKey::whereHas('app', function ($query) use ($merchantId) {
            $query->where('merchant_id', $merchantId);
        })
        ->where('is_active', true)
        ->count();
    }

    /**
     * Get total webhooks count
     */
    private function getTotalWebhooksCount(string $merchantId): int
    {
        return AppWebhook::whereHas('app', function ($query) use ($merchantId) {
            $query->where('merchant_id', $merchantId);
        })
        ->count();
    }

    /**
     * Get active webhooks count
     */
    private function getActiveWebhooksCount(string $merchantId): int
    {
        return AppWebhook::whereHas('app', function ($query) use ($merchantId) {
            $query->where('merchant_id', $merchantId);
        })
        ->where('is_active', true)
        ->count();
    }

    /**
     * Calculate API success rate for last 30 days
     */
    private function getApiSuccessRate(string $merchantId): float
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $now = Carbon::now();

        $totalRequests = PaymentIntent::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$thirtyDaysAgo, $now])
            ->count();

        if ($totalRequests == 0) {
            return 0.0;
        }

        $successfulRequests = PaymentIntent::where('merchant_id', $merchantId)
            ->whereBetween('created_at', [$thirtyDaysAgo, $now])
            ->whereIn('status', ['succeeded', 'processing'])
            ->count();

        return round(($successfulRequests / $totalRequests) * 100, 2);
    }

    /**
     * Get API key usage statistics
     */
    public function getApiKeyUsageStats(string $merchantId): array
    {
        $apiKeys = ApiKey::whereHas('app', function ($query) use ($merchantId) {
            $query->where('merchant_id', $merchantId);
        })
        ->get();

        return [
            'total_keys' => $apiKeys->count(),
            'active_keys' => $apiKeys->where('is_active', true)->count(),
            'production_keys' => $apiKeys->where('environment', 'production')->count(),
            'test_keys' => $apiKeys->where('environment', 'test')->count(),
            'recently_used' => $apiKeys->whereNotNull('last_used_at')
                ->where('last_used_at', '>=', Carbon::now()->subDays(7))
                ->count()
        ];
    }

    /**
     * Get webhook delivery statistics
     */
    public function getWebhookStats(string $merchantId): array
    {
        $webhooks = AppWebhook::whereHas('app', function ($query) use ($merchantId) {
            $query->where('merchant_id', $merchantId);
        })
        ->get();

        $totalDeliveries = 0;
        $successfulDeliveries = 0;

        // This would typically come from webhook delivery logs
        // For now, return basic stats
        return [
            'total_webhooks' => $webhooks->count(),
            'active_webhooks' => $webhooks->where('is_active', true)->count(),
            'total_deliveries' => $totalDeliveries,
            'successful_deliveries' => $successfulDeliveries,
            'delivery_success_rate' => $totalDeliveries > 0 ? 
                round(($successfulDeliveries / $totalDeliveries) * 100, 2) : 0.0
        ];
    }
}