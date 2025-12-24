<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\KycConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycConfigController extends Controller
{
    protected KycConfigurationService $kycConfigService;

    public function __construct(KycConfigurationService $kycConfigService)
    {
        $this->kycConfigService = $kycConfigService;
    }

    /**
     * Get all KYC configuration for a country
     * GET /api/v1/kyc/config/{countryCode}
     */
    public function getCountryConfig(string $countryCode): JsonResponse
    {
        $countryCode = strtoupper($countryCode);

        if (!$this->kycConfigService->isCountrySupported($countryCode)) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => "KYC not configured for country: {$countryCode}",
                ],
            ], 404);
        }

        $config = $this->kycConfigService->getFullCountryConfig($countryCode);

        return response()->json([
            'object' => 'kyc_config',
            'data' => $config,
        ]);
    }

    /**
     * Get tier requirements for a country
     * GET /api/v1/kyc/tiers/{countryCode}
     */
    public function getTiers(string $countryCode): JsonResponse
    {
        $countryCode = strtoupper($countryCode);

        if (!$this->kycConfigService->isCountrySupported($countryCode)) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => "KYC not configured for country: {$countryCode}",
                ],
            ], 404);
        }

        $tiers = $this->kycConfigService->getCountryRequirements($countryCode);

        return response()->json([
            'object' => 'list',
            'data' => $tiers,
            'country_code' => $countryCode,
        ]);
    }

    /**
     * Get document types for a country
     * GET /api/v1/kyc/document-types/{countryCode}
     */
    public function getDocumentTypes(string $countryCode): JsonResponse
    {
        $countryCode = strtoupper($countryCode);

        if (!$this->kycConfigService->isCountrySupported($countryCode)) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => "KYC not configured for country: {$countryCode}",
                ],
            ], 404);
        }

        $documentTypes = $this->kycConfigService->getDocumentTypes($countryCode);

        return response()->json([
            'object' => 'list',
            'data' => $documentTypes,
            'country_code' => $countryCode,
        ]);
    }

    /**
     * Get specific tier requirements
     * GET /api/v1/kyc/tiers/{countryCode}/{tierLevel}
     */
    public function getTierRequirements(string $countryCode, int $tierLevel): JsonResponse
    {
        $countryCode = strtoupper($countryCode);

        $tierReq = $this->kycConfigService->getTierRequirements($countryCode, $tierLevel);

        if (!$tierReq) {
            return response()->json([
                'error' => [
                    'type' => 'not_found_error',
                    'message' => "Tier {$tierLevel} not found for country {$countryCode}",
                ],
            ], 404);
        }

        return response()->json([
            'object' => 'kyc_tier_requirement',
            'data' => $tierReq,
        ]);
    }

    /**
     * Get all supported countries
     * GET /api/v1/kyc/countries
     */
    public function getSupportedCountries(): JsonResponse
    {
        $countries = $this->kycConfigService->getActiveCountries();

        return response()->json([
            'object' => 'list',
            'data' => $countries,
        ]);
    }

    /**
     * Validate a document value
     * POST /api/v1/kyc/validate-document-value
     */
    public function validateDocumentValue(Request $request): JsonResponse
    {
        $request->validate([
            'country_code' => 'required|string|size:2',
            'document_key' => 'required|string',
            'value' => 'required|string',
        ]);

        $countryCode = strtoupper($request->country_code);
        $result = $this->kycConfigService->validateDocumentValue(
            $countryCode,
            $request->document_key,
            $request->value
        );

        return response()->json([
            'object' => 'validation_result',
            'data' => $result,
        ]);
    }
}
