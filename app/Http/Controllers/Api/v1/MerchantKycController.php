<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\MerchantKycService;
use App\Services\KycConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MerchantKycController extends Controller
{
    protected MerchantKycService $kycService;
    protected KycConfigurationService $configService;

    public function __construct(
        MerchantKycService $kycService,
        KycConfigurationService $configService
    ) {
        $this->kycService = $kycService;
        $this->configService = $configService;
    }

    /**
     * Get current merchant's KYC status
     * GET /api/v1/merchants/kyc/status
     */
    public function getStatus(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        try {
            $status = $this->kycService->getKycStatus($merchant->id);

            return response()->json([
                'object' => 'kyc_status',
                'data' => $status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get merchant's uploaded documents
     * GET /api/v1/merchants/kyc/documents
     */
    public function getDocuments(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        try {
            $documents = $this->kycService->getMerchantDocuments($merchant->id);

            return response()->json([
                'object' => 'list',
                'data' => $documents,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Upload a KYC document
     * POST /api/v1/merchants/kyc/documents
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $request->validate([
            'document_type' => 'required|string',
            'file' => 'required|file|max:10240', // Max 10MB
            'side' => 'sometimes|string|in:front,back',
        ]);

        $merchant = $request->user()->merchant;

        try {
            $document = $this->kycService->submitDocument(
                $merchant->id,
                $request->document_type,
                $request->file('file'),
                $request->input('side', 'front'),
                $request->user()->id
            );

            return response()->json([
                'object' => 'kyc_document',
                'data' => [
                    'id' => $document->id,
                    'document_type' => $document->document_type,
                    'file_name' => $document->file_name,
                    'status' => $document->status,
                    'side' => $document->side,
                    'created_at' => $document->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Delete a KYC document
     * DELETE /api/v1/merchants/kyc/documents/{documentId}
     */
    public function deleteDocument(Request $request, string $documentId): JsonResponse
    {
        $merchant = $request->user()->merchant;

        try {
            $this->kycService->deleteDocument($merchant->id, $documentId);

            return response()->json([
                'deleted' => true,
                'id' => $documentId,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Submit KYC for review
     * POST /api/v1/merchants/kyc/submit
     */
    public function submitForReview(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        try {
            $this->kycService->submitForReview($merchant->id);

            return response()->json([
                'object' => 'kyc_submission',
                'data' => [
                    'status' => 'in_review',
                    'submitted_at' => now()->toIso8601String(),
                    'message' => 'Your KYC documents have been submitted for review. You will be notified once the review is complete.',
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get requirements for upgrading to next tier
     * GET /api/v1/merchants/kyc/upgrade-requirements
     */
    public function getUpgradeRequirements(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        try {
            $requirements = $this->kycService->getUpgradeRequirements($merchant->id);

            if (isset($requirements['error'])) {
                return response()->json([
                    'error' => [
                        'type' => 'invalid_request_error',
                        'message' => $requirements['error'],
                    ],
                ], 400);
            }

            return response()->json([
                'object' => 'upgrade_requirements',
                'data' => $requirements,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Check if transaction is within limits
     * POST /api/v1/merchants/kyc/check-limit
     */
    public function checkTransactionLimit(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
        ]);

        $merchant = $request->user()->merchant;

        try {
            $result = $this->kycService->checkTransactionLimit(
                $merchant->id,
                $request->amount,
                strtoupper($request->currency)
            );

            return response()->json([
                'object' => 'limit_check',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get KYC configuration for merchant's country
     * GET /api/v1/merchants/kyc/config
     */
    public function getConfig(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        try {
            $config = $this->configService->getFullCountryConfig($merchant->country_code);

            return response()->json([
                'object' => 'kyc_config',
                'data' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Update KYC profile information
     * PUT /api/v1/merchants/kyc/profile
     */
    public function updateKycProfile(Request $request): JsonResponse
    {
        $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'date_of_birth' => 'sometimes|nullable|date|before:today',
            'address' => 'sometimes|nullable|string|max:500',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'postal_code' => 'sometimes|nullable|string|max:20',
        ]);

        $merchant = $request->user()->merchant;

        // Don't allow profile updates if KYC is already approved
        if ($merchant->kyc_status === 'approved') {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Cannot update profile after KYC approval. Please contact support.',
                ],
            ], 400);
        }

        try {
            $profileData = $request->only([
                'full_name',
                'phone',
                'date_of_birth',
                'address',
                'city',
                'state',
                'postal_code',
            ]);

            // Filter out null values
            $profileData = array_filter($profileData, fn($value) => $value !== null);

            // Update merchant KYC profile data
            $merchant->update([
                'kyc_full_name' => $profileData['full_name'] ?? $merchant->kyc_full_name,
                'kyc_phone' => $profileData['phone'] ?? $merchant->kyc_phone,
                'kyc_date_of_birth' => $profileData['date_of_birth'] ?? $merchant->kyc_date_of_birth,
                'kyc_address' => $profileData['address'] ?? $merchant->kyc_address,
                'kyc_city' => $profileData['city'] ?? $merchant->kyc_city,
                'kyc_state' => $profileData['state'] ?? $merchant->kyc_state,
                'kyc_postal_code' => $profileData['postal_code'] ?? $merchant->kyc_postal_code,
            ]);

            return response()->json([
                'object' => 'kyc_profile',
                'data' => [
                    'full_name' => $merchant->kyc_full_name,
                    'phone' => $merchant->kyc_phone,
                    'date_of_birth' => $merchant->kyc_date_of_birth,
                    'address' => $merchant->kyc_address,
                    'city' => $merchant->kyc_city,
                    'state' => $merchant->kyc_state,
                    'postal_code' => $merchant->kyc_postal_code,
                    'message' => 'KYC profile updated successfully',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get KYC profile information
     * GET /api/v1/merchants/kyc/profile
     */
    public function getKycProfile(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;

        return response()->json([
            'object' => 'kyc_profile',
            'data' => [
                'full_name' => $merchant->kyc_full_name,
                'phone' => $merchant->kyc_phone,
                'date_of_birth' => $merchant->kyc_date_of_birth,
                'address' => $merchant->kyc_address,
                'city' => $merchant->kyc_city,
                'state' => $merchant->kyc_state,
                'postal_code' => $merchant->kyc_postal_code,
                'country_code' => $merchant->country_code,
                'can_edit' => $merchant->kyc_status !== 'approved',
            ],
        ]);
    }
}
