<?php

namespace App\Http\Controllers\Api\v1;
use App\Http\Controllers\Controller;
use App\Services\ChargeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ChargeController extends Controller
{
    private ChargeService $chargeService;

    public function __construct(ChargeService $chargeService)
    {
        $this->chargeService = $chargeService;
    }

    /**
     * Get all charges for the authenticated merchant
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'status' => $request->query('status'),
                'currency' => $request->query('currency'),
                'limit' => $request->query('limit', 15),
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
            ];

            $charges = $this->chargeService->getChargesForMerchant(
                $request->user()->merchant_id,
                $filters
            );

            return response()->json($this->paginatedResponse($charges));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve charges',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific charge by ID
     */
    public function show(Request $request, string $chargeId): JsonResponse
    {
        try {
            $charge = $this->chargeService->getChargeById(
                $chargeId,
                $request->user()->id
            );

            if (!$charge) {
                return response()->json([
                    'success' => false,
                    'message' => 'Charge not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $charge
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve charge',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Capture a charge
     */
    public function capture(Request $request, string $chargeId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'sometimes|integer|min:1',
                'description' => 'sometimes|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid input',
                    'errors' => $validator->errors()
                ], 422);
            }

            $charge = $this->chargeService->captureCharge(
                $chargeId,
                $request->user()->id,
                $validator->validated()
            );

            return response()->json([
                'success' => true,
                'data' => $charge,
                'message' => 'Charge captured successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to capture charge',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}