<?php

namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SettlementController extends Controller
{
    private SettlementService $settlementService;

    public function __construct(SettlementService $settlementService)
    {
        $this->settlementService = $settlementService;
    }

    /**
     * Get all settlements for the authenticated merchant
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'status' => $request->query('status'),
                'currency' => $request->query('currency'),
                'limit' => $request->query('limit', 10),
                'offset' => $request->query('offset', 0),
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
            ];

            $settlements = $this->settlementService->getSettlementsForMerchant(
                $request->user()->id,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => $settlements
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve settlements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific settlement by ID
     */
    public function show(Request $request, string $settlementId): JsonResponse
    {
        try {
            $settlement = $this->settlementService->getSettlementById(
                $settlementId,
                $request->user()->id
            );

            if (!$settlement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Settlement not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $settlement
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve settlement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get settlement transactions
     */
    public function transactions(Request $request, string $settlementId): JsonResponse
    {
        try {
            $transactions = $this->settlementService->getSettlementTransactions(
                $settlementId,
                $request->user()->id
            );

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve settlement transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}