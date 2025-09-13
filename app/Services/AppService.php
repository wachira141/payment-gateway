<?php

namespace App\Services;

use App\Models\App;
use Illuminate\Support\Collection;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Str;

class AppService extends BaseService
{
    public function __construct(private HttpClient $http)
    {
        // Service now focuses on orchestration and external I/O
    }

    /**
     * Get apps for a merchant with optional filters and pagination
     */
    public function getAppsForMerchant(
        string $merchantId,
        array $filters = [],
        int $perPage = 15,
        array $with = ['apiKeys:id,app_id,name,is_active,last_used_at']
    ) {
        return App::getPaginatedForMerchant($merchantId, $filters, $perPage, $with);
    }

    /**
     * Get a specific app by ID for a merchant
     */
    public function getAppById(string $appId, string $merchantId, array $with = []): ?App
    {
        $query = App::forMerchant($merchantId)->where('id', $appId);
        
        if (!empty($with)) {
            $query->with($with);
        }
        
        return $query->first();
    }

    /**
     * Create a new app
     */
    public function createApp(string $merchantId, array $data): App
    {
        $this->logActivity('Creating app', ['merchant_id' => $merchantId, 'name' => $data['name']]);
        
        try {
            return App::createForMerchant($merchantId, $data);
        } catch (\Exception $e) {
            $this->handleException($e, 'App creation');
            throw $e;
        }
    }

    /**
     * Update an app
     */
    public function updateApp(string $appId, string $merchantId, array $data): ?App
    {
        $this->logActivity('Updating app', ['app_id' => $appId, 'merchant_id' => $merchantId]);
        
        try {
            return App::updateForMerchant($appId, $merchantId, $data);
        } catch (\Exception $e) {
            $this->handleException($e, 'App update');
            throw $e;
        }
    }

    /**
     * Delete an app (soft delete with checks)
     */
    public function deleteApp(string $appId, string $merchantId): bool
    {
        $app = App::findForMerchant($appId, $merchantId);
        
        if (!$app) {
            throw new \Exception('App not found');
        }

        $this->logActivity('Deleting app', ['app_id' => $appId, 'merchant_id' => $merchantId]);
        
        try {
            return $app->deactivateWithChecks() && $app->delete();
        } catch (\Exception $e) {
            $this->handleException($e, 'App deletion');
            throw $e;
        }
    }

    /**
     * Create API key for app
     */
    public function createApiKeyForApp(string $appId, string $merchantId, array $data): \App\Models\ApiKey
    {
        $app = App::findForMerchant($appId, $merchantId);
        
        if (!$app) {
            throw new \Exception('App not found');
        }

        if (!$app->is_active) {
            throw new \Exception('Cannot create API key for inactive app');
        }

        $this->logActivity('Creating API key for app', [
            'app_id' => $appId, 
            'merchant_id' => $merchantId,
            'key_name' => $data['name']
        ]);
        
        try {
            return $app->createApiKey(
                $data['name'],
                $data['scopes'] ?? null,
                [
                    'expires_at' => $data['expires_at'] ?? null,
                    'rate_limits' => $data['rate_limits'] ?? null,
                ]
            );
        } catch (\Exception $e) {
            $this->handleException($e, 'API key creation');
            throw $e;
        }
    }

    /**
     * Get app statistics
     */
    public function getAppStatistics(string $appId, string $merchantId): array
    {
        $app = App::findForMerchant($appId, $merchantId);
        
        if (!$app) {
            throw new \Exception('App not found');
        }

        return $app->getStatistics();
    }

    /**
     * Regenerate app client secret
     */
    public function regenerateClientSecret(string $appId, string $merchantId): App
    {
        $app = App::findForMerchant($appId, $merchantId);
        
        if (!$app) {
            throw new \Exception('App not found');
        }

        $this->logActivity('Regenerating client secret', [
            'app_id' => $appId, 
            'merchant_id' => $merchantId
        ]);

        return $app->regenerateClientSecret();
    }

    /**
     * Update app webhook settings
     */
    public function updateWebhookSettings(string $appId, string $merchantId, array $data): App
    {
        $app = App::findForMerchant($appId, $merchantId);
        
        if (!$app) {
            throw new \Exception('App not found');
        }

        $this->logActivity('Updating webhook settings', [
            'app_id' => $appId, 
            'merchant_id' => $merchantId
        ]);

        return $app->updateWebhookSettings($data);
    }

    /**
     * Test webhook endpoint
     */
    public function testWebhook(string $appId, string $merchantId): array
    {
        $app = App::findForMerchant($appId, $merchantId);
        
        if (!$app) {
            throw new \Exception('App not found');
        }

        if (!$app->webhook_url) {
            throw new \Exception('No webhook URL configured');
        }

        // Send test webhook
        $testPayload = [
            'id' => 'evt_test_' . Str::random(16),
            'type' => 'test.webhook',
            'created' => now()->timestamp,
            'data' => [
                'object' => [
                    'id' => 'test_object',
                    'message' => 'This is a test webhook from ' . config('app.name'),
                ]
            ]
        ];

        $this->logActivity('Testing webhook', [
            'app_id' => $appId,
            'webhook_url' => $app->webhook_url
        ]);

        $result = $this->deliverWebhook($app->webhook_url, $testPayload);

        return [
            'app_id' => $appId,
            'webhook_url' => $app->webhook_url,
            'status_code' => $result['status_code'],
            'response_time' => $result['response_time'],
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
            'payload' => $testPayload,
        ];
    }

    /**
     * Get app usage summary for merchant
     */
    public function getAppUsageSummary(string $merchantId, array $filters = []): array
    {
        return App::getUsageSummaryForMerchant($merchantId, $filters);
    }

    /**
     * Deliver webhook using HTTP client
     */
    private function deliverWebhook(string $url, array $payload): array
    {
        $startTime = microtime(true);
        
        try {
            $response = $this->http->timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => config('app.name') . ' Webhook/1.0',
                ])
                ->post($url, $payload);

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'response_time' => $responseTime,
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => false,
                'status_code' => 0,
                'response_time' => $responseTime,
                'error' => $e->getMessage(),
            ];
        }
    }
}