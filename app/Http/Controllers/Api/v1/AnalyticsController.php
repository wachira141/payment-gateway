<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\DeveloperMetricsService;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    protected $analyticsService;
    protected $developerMetricsService;
    protected $systemHealthService;

    public function __construct(
        AnalyticsService $analyticsService,
        DeveloperMetricsService $developerMetricsService,
        SystemHealthService $systemHealthService
    ) {
        $this->analyticsService = $analyticsService;
        $this->developerMetricsService = $developerMetricsService;
        $this->systemHealthService = $systemHealthService;
    }

    /**
     * Get dashboard metrics for main dashboard
     */
    public function getDashboardMetrics(Request $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;
            $currency = $request->query('currency', $merchant->default_currency ?? 'USD');

            $metrics = $this->analyticsService->getDashboardMetrics($merchant->id, $currency);

            return response()->json([
                'success' => true,
                'data' => $metrics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get developer-specific metrics
     */
    public function getDeveloperMetrics(Request $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;
            $metrics = $this->developerMetricsService->getDeveloperMetrics($merchant->id);

            return response()->json([
                'success' => true,
                'data' => $metrics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch developer metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system activity logs
     */
    public function getSystemActivity(Request $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;
            $limit = $request->query('limit', 10);

            $activity = $this->analyticsService->getSystemActivity($merchant->id, $limit);

            return response()->json([
                'success' => true,
                'data' => $activity
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch system activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get chart data for analytics
     */
    public function getChartData(Request $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;
            $period = $request->query('period', '30d');
            $currency = $request->query('currency', $merchant->default_currency ?? 'USD');

            $chartData = $this->analyticsService->getChartData($merchant->id, $period, $currency);

            return response()->json([
                'success' => true,
                'data' => $chartData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch chart data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system health status
     */
    public function getSystemHealth(Request $request): JsonResponse
    {
        try {
            $health = $this->systemHealthService->getSystemHealth();

            return response()->json([
                'success' => true,
                'data' => $health
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch system health',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}