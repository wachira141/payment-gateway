<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePayoutRequest;
use App\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PayoutController extends Controller
{
    private PayoutService $payoutService;

    public function __construct(PayoutService $payoutService)
    {
        $this->payoutService = $payoutService;
    }

    /**
     * Get all payouts for the authenticated merchant
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
            $payouts = $this->payoutService->getPayoutsForMerchant(
                $request->user()->merchant_id,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => $payouts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payouts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new payout
     */
    public function store(CreatePayoutRequest $request): JsonResponse
    {
        try {
            $payout = $this->payoutService->createPayout(
                $request->user()->merchant_id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Payout created successfully',
                'data' => $payout
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payout. '. $e->getMessage(),
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get a specific payout by ID
     */
    public function show(Request $request, string $payoutId): JsonResponse
    {
        try {
            $payout = $this->payoutService->getPayoutById(
                $payoutId,
                $request->user()->merchant_id
            );

            if (!$payout) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payout not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $payout
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payout',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a payout
     */
    public function cancel(Request $request, string $payoutId): JsonResponse
    {
        try {
            $payout = $this->payoutService->cancelPayout(
                $payoutId,
                $request->user()->merchant_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Payout cancelled successfully',
                'data' => $payout
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel payout',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get payout statistics
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $filters = [
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
            ];

            $stats = $this->payoutService->getPayoutStatistics(
                $request->user()->merchant_id,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payout statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}