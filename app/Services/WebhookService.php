<?php

namespace App\Services;

use App\Models\AppWebhook;
use App\Models\App;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class WebhookService extends BaseService
{
    /**
     * Get all webhooks for a merchant across all apps
     */
    public function getMerchantWebhooks(string $merchantId): Collection
    {
        return AppWebhook::with('app')
            ->whereHas('app', function ($query) use ($merchantId) {
                $query->where('merchant_id', $merchantId);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($webhook) {
                return $this->formatWebhook($webhook, $webhook->app);
            })
            ->values();
    }

    /**
     * Get webhook statistics for a merchant
     * 
     * NOTE: This is a simplified version. For accurate last 24h deliveries, Currently not being used
     * @wilson.muriuki@paycart.com
     */
    public function getMerchantWebhookStats(string $merchantId): array
    {
        $apps = App::where('merchant_id', $merchantId)->get();
        $totalDeliveries = 0;
        $successfulDeliveries = 0;
        $failedDeliveries = 0;
        $last24hDeliveries = 0;

        foreach ($apps as $app) {
            $webhooks = AppWebhook::where('app_id', $app->id)->get();

            foreach ($webhooks as $webhook) {
                $totalDeliveries += $webhook->success_count + $webhook->failure_count;
                $successfulDeliveries += $webhook->success_count;
                $failedDeliveries += $webhook->failure_count;

                // For last 24h, you'd need delivery logs table
                // For now, use a rough estimate
                if (
                    $webhook->last_delivery_at &&
                    $webhook->last_delivery_at->greaterThan(now()->subDay())
                ) {
                    $last24hDeliveries += 1;
                }
            }
        }

        $successRate = $totalDeliveries > 0 ?
            round(($successfulDeliveries / $totalDeliveries) * 100, 1) : 100;

        return [
            'total_deliveries' => $totalDeliveries,
            'success_rate' => $successRate,
            'last_24h_deliveries' => $last24hDeliveries,
            'failed_deliveries' => $failedDeliveries
        ];
    }

    /**
     * Format webhook data for response
     */
    private function formatWebhook(AppWebhook $webhook, App $app): array
    {
        return [
            'id' => $webhook->id,
            'url' => $webhook->url,
            'description' => $webhook->description,
            'events' => match (true) {
                is_array($webhook->events) => $webhook->events,
                is_string($webhook->events) => json_decode($webhook->events, true) ?? [],
                default => [],
            },

            'is_active' => $webhook->is_active,
            'signing_secret_masked' => $webhook->signing_secret ?
                substr($webhook->signing_secret, 0, 8) . str_repeat('•', 24) : null,
            'last_delivery_at' => $webhook->last_delivery_at?->toISOString(),
            'success_count' => $webhook->success_count ?? 0,
            'failure_count' => $webhook->failure_count ?? 0,
            'created_at' => $webhook->created_at->toISOString(),
            'app_id' => $app->id,
            'app_name' => $app->name
        ];
    }
}
