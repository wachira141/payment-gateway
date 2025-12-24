<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDisbursementRequest;
use App\Http\Requests\CreateBatchDisbursementRequest;
use App\Services\DisbursementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DisbursementController extends Controller
{
    private DisbursementService $disbursementService;

    public function __construct(DisbursementService $disbursementService)
    {
        $this->disbursementService = $disbursementService;
    }

    /**
     * Get all disbursements for the authenticated merchant
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'status' => $request->query('status'),
                'funding_source' => $request->query('funding_source'),
                'wallet_id' => $request->query('wallet_id'),
                'beneficiary_id' => $request->query('beneficiary_id'),
                'currency' => $request->query('currency'),
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
                'amount_min' => $request->query('amount_min'),
                'amount_max' => $request->query('amount_max'),
            ];

            $perPage = $request->query('limit', 15);

            $disbursements = $this->disbursementService->getMerchantDisbursements(
                $request->user()->merchant_id,
                array_filter($filters),
                $perPage
            );

            return response()->json($this->paginatedResponse($disbursements, true, 'Disbursements retrieved successfully'));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve disbursements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new disbursement
     */
    public function store(CreateDisbursementRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $disbursement = $this->disbursementService->createDisbursement(
                $request->user()->merchant_id,
                $data['wallet_id'],
                $data['beneficiary_id'],
                $data['amount'],
                [
                    'description' => $data['description'] ?? null,
                    'external_reference' => $data['external_reference'] ?? null,
                    'metadata' => $data['metadata'] ?? [],
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Disbursement created successfully',
                'data' => $disbursement->transform()
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create disbursement: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get a specific disbursement
     */
    public function show(Request $request, string $disbursementId): JsonResponse
    {
        try {
            $disbursement = $this->disbursementService->getDisbursementById(
                $disbursementId,
                $request->user()->merchant_id
            );

            if (!$disbursement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Disbursement not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $disbursement->transform()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve disbursement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a disbursement
     */
    public function cancel(Request $request, string $disbursementId): JsonResponse
    {
        try {
            $reason = $request->input('reason');

            $disbursement = $this->disbursementService->cancelDisbursement(
                $disbursementId,
                $request->user()->merchant_id,
                $reason
            );

            return response()->json([
                'success' => true,
                'message' => 'Disbursement cancelled successfully',
                'data' => $disbursement->transform()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel disbursement: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Retry a failed disbursement
     */
    public function retry(Request $request, string $disbursementId): JsonResponse
    {
        try {
            $disbursement = $this->disbursementService->retryDisbursement(
                $disbursementId,
                $request->user()->merchant_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Disbursement retry initiated',
                'data' => $disbursement->transform()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry disbursement: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Create a batch disbursement
     */
    public function storeBatch(CreateBatchDisbursementRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            $batch = $this->disbursementService->createBatchDisbursement(
                $request->user()->merchant_id,
                $data['wallet_id'],
                $data['disbursements'],
                [
                    'batch_name' => $data['batch_name'] ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Batch disbursement created successfully',
                'data' => $batch->transform()
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create batch disbursement: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get all batches
     */
    public function batches(Request $request): JsonResponse
    {
        try {
            $filters = [
                'status' => $request->query('status'),
                'wallet_id' => $request->query('wallet_id'),
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
            ];

            $perPage = $request->query('limit', 15);

            $batches = $this->disbursementService->getMerchantBatches(
                $request->user()->merchant_id,
                array_filter($filters),
                $perPage
            );

            return response()->json($this->paginatedResponse($batches, true, 'Disbursement Batches Retrieved Successfully'));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batches',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific batch
     */
    public function showBatch(Request $request, string $batchId): JsonResponse
    {
        try {
            $batch = $this->disbursementService->getBatchById(
                $batchId,
                $request->user()->merchant_id
            );

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $batch->transformWithDisbursements()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batch',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get disbursement statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $filters = [
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
            ];

            $statistics = $this->disbursementService->getDisbursementStatistics(
                $request->user()->merchant_id,
                array_filter($filters)
            );

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get disbursement summary
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $summary = $this->disbursementService->getDisbursementSummary(
                $request->user()->merchant_id
            );

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payout estimation
     */
    public function estimate(Request $request): JsonResponse
    {
        try {
            $walletId = $request->input('wallet_id');
            $amount = (float) $request->input('amount');

            if (!$walletId || !$amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'wallet_id and amount are required'
                ], 400);
            }

            $estimation = $this->disbursementService->getPayoutEstimation(
                $request->user()->merchant_id,
                $walletId,
                $amount
            );

            return response()->json([
                'success' => true,
                'data' => $estimation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get estimation',
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
