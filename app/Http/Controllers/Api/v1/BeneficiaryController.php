<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBeneficiaryRequest;
use App\Http\Requests\UpdateBeneficiaryRequest;
use App\Services\BeneficiaryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BeneficiaryController extends Controller
{
    private BeneficiaryService $beneficiaryService;

    public function __construct(BeneficiaryService $beneficiaryService)
    {
        $this->beneficiaryService = $beneficiaryService;
    }

    /**
     * Get all beneficiaries for the authenticated merchant
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'status' => $request->query('status'),
                'currency' => $request->query('currency'),
                'type' => $request->query('type'),
                'limit' => $request->query('limit', 10),
                'offset' => $request->query('offset', 0),
            ];

            $beneficiaries = $this->beneficiaryService->getBeneficiariesForMerchant(
                $request->user()->merchant_id,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => $beneficiaries
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve beneficiaries',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new beneficiary
     */
    public function store(CreateBeneficiaryRequest $request): JsonResponse
    {
        try {
            $beneficiary = $this->beneficiaryService->createBeneficiary(
                $request->user()->merchant_id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Beneficiary created successfully',
                'data' => $beneficiary
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create beneficiary',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get a specific beneficiary by ID
     */
    public function show(Request $request, string $beneficiaryId): JsonResponse
    {
        try {
            $beneficiary = $this->beneficiaryService->getBeneficiaryById(
                $beneficiaryId,
                $request->user()->merchant_id
            );

            if (!$beneficiary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Beneficiary not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $beneficiary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve beneficiary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a beneficiary
     */
    public function update(UpdateBeneficiaryRequest $request, string $beneficiaryId): JsonResponse
    {
        try {
            $beneficiary = $this->beneficiaryService->updateBeneficiary(
                $beneficiaryId,
                $request->user()->merchant_id,
                $request->validated()
            );

            if (!$beneficiary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Beneficiary not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Beneficiary updated successfully',
                'data' => $beneficiary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update beneficiary',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Delete a beneficiary
     */
    public function destroy(Request $request, string $beneficiaryId): JsonResponse
    {
        try {
            $deleted = $this->beneficiaryService->deleteBeneficiary(
                $beneficiaryId,
                $request->user()->merchant_id
            );

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Beneficiary not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Beneficiary deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete beneficiary',
                'error' => $e->getMessage()
            ], 400);
        }
    }
}