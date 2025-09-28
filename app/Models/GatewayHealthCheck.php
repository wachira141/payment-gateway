<?php

namespace App\Models;

use Carbon\Carbon;

class GatewayHealthCheck extends BaseModel
{
    protected $fillable = [
        'check_id',
        'gateway_id',
        'gateway_name',
        'gateway_endpoint',
        'check_type',
        'status',
        'is_healthy',
        'response_time',
        'response_time_ms',
        'response_data',
        'error_message',
        'health_metrics',
        'checked_at',
        'success_rate',
        'consecutive_failures',
        'last_success_at',
        'last_failure_at'
    ];

    protected $casts = [
        'is_healthy' => 'boolean',
        'response_time' => 'float',
        'health_metrics' => 'array',
        'checked_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'success_rate' => 'decimal:2',
        'consecutive_failures' => 'integer'
    ];

    /**
     * Generate unique check ID
     */
    public static function generateCheckId(string $gateway, string $type): string
    {
        return strtoupper($gateway) . '_' . strtoupper($type) . '_' . now()->format('YmdHis');
    }

    /**
     * Get latest status for each gateway
     */
    public static function getLatestStatusByGateway()
    {
        return static::select('gateway_name', 'status', 'checked_at', 'response_time_ms', 'success_rate')
            ->whereIn('id', function($query) {
                $query->selectRaw('MAX(id)')
                    ->from('gateway_health_checks')
                    ->groupBy('gateway_name');
            })
            ->get()
            ->keyBy('gateway_name');
    }

    /**
     * Get health history for gateway
     */
    public static function getHealthHistory(string $gateway, Carbon $from, Carbon $to)
    {
        return static::where('gateway_name', $gateway)
            ->whereBetween('checked_at', [$from, $to])
            ->orderBy('checked_at', 'asc')
            ->get();
    }

    /**
     * Calculate success rate for period
     */
    public static function getSuccessRate(string $gateway, Carbon $from, Carbon $to): float
    {
        $total = static::where('gateway_name', $gateway)
            ->whereBetween('checked_at', [$from, $to])
            ->count();

        if ($total === 0) {
            return 0;
        }

        $successful = static::where('gateway_name', $gateway)
            ->whereBetween('checked_at', [$from, $to])
            ->where('status', 'healthy')
            ->count();

        return round(($successful / $total) * 100, 2);
    }

    /**
     * Mark as successful
     */
    public function markSuccess(int $responseTime, array $metrics = [])
    {
        return $this->update([
            'status' => 'healthy',
            'response_time_ms' => $responseTime,
            'health_metrics' => $metrics,
            'consecutive_failures' => 0,
            'last_success_at' => now(),
            'error_message' => null
        ]);
    }

    /**
     * Mark as failed
     */
    public function markFailure(string $error, int $responseTime = null)
    {
        return $this->update([
            'status' => 'unhealthy',
            'response_time_ms' => $responseTime,
            'error_message' => $error,
            'last_failure_at' => now()
        ]);
    }

    /**
     * Increment consecutive failures
     */
    public function incrementFailures()
    {
        return $this->increment('consecutive_failures');
    }

    /**
     * Check if gateway is consistently failing
     */
    public function isConsistentlyFailing(): bool
    {
        return $this->consecutive_failures >= 5;
    }
}