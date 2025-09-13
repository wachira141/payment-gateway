<?php

namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller;
use App\Services\RefundService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class RefundController extends Controller
{
    private RefundService $refundService;

    public function __construct(RefundService $refundService)
    {
        $this->refundService = $refundService;
    }

    /**
     * Get all refunds for the authenticated merchant
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

            $refunds = $this->refundService->getRefundsForMerchant(
                $request->user()->id,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => $refunds
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve refunds',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new refund
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'payment_intent_id' => 'sometimes|string',
                'charge_id' => 'sometimes|string',
                'amount' => 'required|integer|min:1',
                'currency' => 'required|string|size:3',
                'reason' => 'sometimes|string|in:duplicate,fraudulent,requested_by_customer,expired_uncaptured_charge',
                'metadata' => 'sometimes|array',
                'refund_application_fee' => 'sometimes|boolean',
                'reverse_transfer' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input',
                    'errors' => $validator->errors()
                ], 422);
            }

            $refund = $this->refundService->createRefund(
                $request->user()->id,
                $validator->validated()
            );

            return response()->json([
                'success' => true,
                'data' => $refund,
                'message' => 'Refund created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create refund',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific refund by ID
     */
    public function show(Request $request, string $refundId): JsonResponse
    {
        try {
            $refund = $this->refundService->getRefundById(
                $refundId,
                $request->user()->id
            );

            if (!$refund) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $refund
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve refund',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}