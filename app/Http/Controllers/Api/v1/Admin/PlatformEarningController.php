<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformEarningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformEarningController extends Controller
{
    protected PlatformEarningService $platformEarningService;

    public function __construct(PlatformEarningService $platformEarningService)
    {
        $this->platformEarningService = $platformEarningService;
    }

    /**
     * Get paginated list of platform earnings
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'merchant_id', 'source_type', 'currency', 'gateway_code',
            'status', 'fee_type', 'start_date', 'end_date'
        ]);

        $perPage = $request->get('per_page', 15);
        $earnings = $this->platformEarningService->getEarnings($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $earnings->items(),
            'meta' => [
                'current_page' => $earnings->currentPage(),
                'last_page' => $earnings->lastPage(),
                'per_page' => $earnings->perPage(),
                'total' => $earnings->total(),
            ]
        ]);
    }

    /**
     * Get revenue summary
     */
    public function summary(Request $request): JsonResponse
    {
        $summary = $this->platformEarningService->getRevenueSummary(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('currency')
        );

        return response()->json(['success' => true, 'data' => $summary]);
    }

    /**
     * Get revenue by source type
     */
    public function bySource(Request $request): JsonResponse
    {
        $data = $this->platformEarningService->getRevenueBySource(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('currency')
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get gateway profitability analysis
     */
    public function gatewayProfitability(Request $request): JsonResponse
    {
        $data = $this->platformEarningService->getGatewayProfitability(
            $request->get('start_date'),
            $request->get('end_date')
        );

        return response()->json(['success' => true, 'data' => $data]);
    }
      /**
     * Get TRUE profitability report with detailed breakdown
     * 
     * Returns:
     * - Explicit commission (application fees)
     * - Implicit profit (processing margins)
     * - Total platform revenue
     * - Breakdown by gateway and transaction type
     * - Profitability insights
     */
    public function profitability(Request $request): JsonResponse
    {
        $data = $this->platformEarningService->getTrueProfitabilityReport(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('currency')
        );

        return response()->json(['success' => true, 'data' => $data]);
    }


    /**
     * Get daily revenue trend
     */
    public function dailyTrend(Request $request): JsonResponse
    {
        $data = $this->platformEarningService->getDailyRevenueTrend(
            $request->get('start_date'),
            $request->get('end_date'),
            $request->get('currency'),
            $request->get('days', 30)
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Settle pending earnings
     */
    public function settle(Request $request): JsonResponse
    {
        $request->validate(['earning_ids' => 'required|array']);

        $result = $this->platformEarningService->settleEarnings($request->get('earning_ids'));

        return response()->json(['success' => true, 'data' => $result]);
    }
}
