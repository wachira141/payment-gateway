<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Models\GatewayHealthCheck;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class SystemHealthService extends BaseService
{
    /**
     * Get overall system health status
     */
    public function getSystemHealth(): array
    {
        return [
            'api_gateway' => $this->getApiGatewayHealth(),
            'webhooks' => $this->getWebhookHealth(),
            'documentation' => $this->getDocumentationHealth(),
            'payment_gateways' => $this->getPaymentGatewayHealth(),
            'database' => $this->getDatabaseHealth(),
            'last_updated' => Carbon::now()->toISOString()
        ];
    }

    /**
     * Check API Gateway health
     */
    private function getApiGatewayHealth(): string
    {
        return Cache::remember('api_gateway_health', 60, function () {
            try {
                // Check if we can connect to our own API
                $response = Http::timeout(5)->get(url('/api/health'));
                
                if ($response->successful() && $response->json('status') === 'ok') {
                    return 'operational';
                }
                
                return 'degraded';
            } catch (\Exception $e) {
                return 'down';
            }
        });
    }

    /**
     * Check webhook delivery health
     */
    private function getWebhookHealth(): string
    {
        return Cache::remember('webhook_health', 300, function () {
            // Check recent webhook delivery failures
            $recentFailures = $this->getRecentWebhookFailures();
            
            if ($recentFailures === 0) {
                return 'operational';
            } elseif ($recentFailures < 5) {
                return 'degraded';
            } else {
                return 'down';
            }
        });
    }

    /**
     * Check documentation health
     */
    private function getDocumentationHealth(): string
    {
        return Cache::remember('documentation_health', 300, function () {
            try {
                // This would check if documentation endpoints are accessible
                // For now, return a simulated status
                return collect(['operational', 'degraded', 'operational', 'operational'])
                    ->random();
            } catch (\Exception $e) {
                return 'down';
            }
        });
    }

    /**
     * Get payment gateway health status
     */
    private function getPaymentGatewayHealth(): array
    {
        $gateways = PaymentGateway::where('is_active', true)->get();
        $healthStatus = [];

        foreach ($gateways as $gateway) {
            $health = $this->checkGatewayHealth($gateway);
            $healthStatus[$gateway->code] = $health;
        }

        return $healthStatus;
    }

    /**
     * Check individual gateway health
     */
    private function checkGatewayHealth(PaymentGateway $gateway): array
    {
        $cacheKey = "gateway_health_{$gateway->code}";
        
        return Cache::remember($cacheKey, 120, function () use ($gateway) {
            $latestCheck = GatewayHealthCheck::where('gateway_id', $gateway->id)
                ->orderBy('checked_at', 'desc')
                ->first();

            if (!$latestCheck) {
                return [
                    'status' => 'unknown',
                    'response_time' => 0,
                    'success_rate' => 0,
                    'last_check' => null,
                    'consecutive_failures' => 0
                ];
            }

            $status = 'operational';
            if ($latestCheck->consecutive_failures > 2) {
                $status = 'down';
            } elseif ($latestCheck->consecutive_failures > 0 || $latestCheck->response_time > 5000) {
                $status = 'degraded';
            }

            return [
                'status' => $status,
                'response_time' => $latestCheck->response_time,
                'success_rate' => $this->calculateGatewaySuccessRate($gateway->id),
                'last_check' => $latestCheck->checked_at->toISOString(),
                'consecutive_failures' => $latestCheck->consecutive_failures
            ];
        });
    }

    /**
     * Calculate gateway success rate over last 24 hours
     */
    private function calculateGatewaySuccessRate(int $gatewayId): float
    {
        $yesterday = Carbon::now()->subDay();
        
        $checks = GatewayHealthCheck::where('gateway_id', $gatewayId)
            ->where('checked_at', '>=', $yesterday)
            ->get();

        if ($checks->isEmpty()) {
            return 0.0;
        }

        $successfulChecks = $checks->where('is_healthy', true)->count();
        return round(($successfulChecks / $checks->count()) * 100, 2);
    }

    /**
     * Check database health
     */
    private function getDatabaseHealth(): string
    {
        return Cache::remember('database_health', 60, function () {
            try {
                // Simple database connectivity check
                DB::select('SELECT 1');
                
                // Check query performance
                $start = microtime(true);
                DB::table('merchants')->count();
                $queryTime = (microtime(true) - $start) * 1000;

                if ($queryTime < 100) {
                    return 'operational';
                } elseif ($queryTime < 500) {
                    return 'degraded';
                } else {
                    return 'down';
                }
            } catch (\Exception $e) {
                return 'down';
            }
        });
    }

    /**
     * Get recent webhook delivery failures
     */
    private function getRecentWebhookFailures(): int
    {
        // This would check webhook delivery logs
        // For now, return a simulated value
        return rand(0, 10);
    }

    /**
     * Perform health check for a specific gateway
     */
    public function performGatewayHealthCheck(PaymentGateway $gateway): void
    {
        $startTime = microtime(true);
        $isHealthy = false;
        $errorMessage = null;

        try {
            // This would make actual health check request to gateway
            // For simulation, we'll randomly determine health
            $isHealthy = rand(1, 10) > 2; // 80% success rate
            
            if (!$isHealthy) {
                $errorMessage = 'Gateway timeout or error response';
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
        }

        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Get previous consecutive failures
        $lastCheck = GatewayHealthCheck::where('gateway_id', $gateway->id)
            ->orderBy('checked_at', 'desc')
            ->first();

        $consecutiveFailures = 0;
        if (!$isHealthy) {
            $consecutiveFailures = $lastCheck ? $lastCheck->consecutive_failures + 1 : 1;
        }

        // Record the health check
        GatewayHealthCheck::create([
            'gateway_id' => $gateway->id,
            'is_healthy' => $isHealthy,
            'response_time' => $responseTime,
            'error_message' => $errorMessage,
            'consecutive_failures' => $consecutiveFailures,
            'checked_at' => Carbon::now()
        ]);

        // Clear cache to refresh health status
        Cache::forget("gateway_health_{$gateway->code}");
    }

    /**
     * Get system performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'avg_api_response_time' => $this->getAverageApiResponseTime(),
            'database_query_time' => $this->getAverageDatabaseQueryTime(),
            'memory_usage' => $this->getCurrentMemoryUsage(),
            'cpu_usage' => $this->getCurrentCpuUsage(),
        ];
    }

    /**
     * Get average API response time
     */
    private function getAverageApiResponseTime(): float
    {
        // This would come from application performance monitoring
        return round(rand(50, 200), 2); // Simulated value in milliseconds
    }

    /**
     * Get average database query time
     */
    private function getAverageDatabaseQueryTime(): float
    {
        // This would come from database monitoring
        return round(rand(5, 50), 2); // Simulated value in milliseconds
    }

    /**
     * Get current memory usage
     */
    private function getCurrentMemoryUsage(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->convertToBytes(ini_get('memory_limit'));

        return [
            'current' => $memoryUsage,
            'limit' => $memoryLimit,
            'percentage' => round(($memoryUsage / $memoryLimit) * 100, 2)
        ];
    }

    /**
     * Get current CPU usage (simulated)
     */
    private function getCurrentCpuUsage(): float
    {
        // This would come from system monitoring
        return round(rand(10, 80), 2); // Simulated percentage
    }

    /**
     * Convert memory limit string to bytes
     */
    private function convertToBytes(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $value = (int) $value;

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }
}