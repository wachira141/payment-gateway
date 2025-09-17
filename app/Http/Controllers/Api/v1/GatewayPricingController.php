<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\GatewayPricingService;
use App\Models\GatewayPricingConfig;
use App\Models\DefaultGatewayPricing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class GatewayPricingController extends Controller
{
    protected $gatewayPricingService;

    public function __construct(GatewayPricingService $gatewayPricingService)
    {
        $this->gatewayPricingService = $gatewayPricingService;
    }

    /**
     * Get merchant's pricing configuration summary
     */
    public function getMerchantPricing(string $merchantId): JsonResponse
    {
        try {
            $summary = $this->gatewayPricingService->getMerchantPricingSummary($merchantId);
            
            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get default gateway pricing configurations
     */
    public function getDefaultPricing(): JsonResponse
    {
        try {
            $configs = DefaultGatewayPricing::getActiveConfigs();
            
            return response()->json([
                'success' => true,
                'data' => $configs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create merchant-specific pricing configuration
     */
    public function createMerchantPricing(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|string|exists:merchants,id',
            'gateway_code' => 'required|string|max:50',
            'payment_method_type' => 'required|string|max:50',
            'currency' => 'required|string|size:3',
            'processing_fee_rate' => 'required|numeric|between:0,1',
            'processing_fee_fixed' => 'required|integer|min:0',
            'application_fee_rate' => 'required|numeric|between:0,1',
            'application_fee_fixed' => 'required|integer|min:0',
            'min_fee' => 'nullable|integer|min:0',
            'max_fee' => 'nullable|integer|min:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after:effective_from',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            $config = $this->gatewayPricingService->createMerchantPricing($request->all());
            
            return response()->json([
                'success' => true,
                'data' => $config,
                'message' => 'Merchant pricing configuration created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update merchant-specific pricing configuration
     */
    public function updateMerchantPricing(Request $request, string $configId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'processing_fee_rate' => 'sometimes|numeric|between:0,1',
            'processing_fee_fixed' => 'sometimes|integer|min:0',
            'application_fee_rate' => 'sometimes|numeric|between:0,1',
            'application_fee_fixed' => 'sometimes|integer|min:0',
            'min_fee' => 'nullable|integer|min:0',
            'max_fee' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after:effective_from',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            $config = GatewayPricingConfig::findOrFail($configId);
            $config->update($request->all());
            
            return response()->json([
                'success' => true,
                'data' => $config->fresh(),
                'message' => 'Pricing configuration updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete merchant-specific pricing configuration
     */
    public function deleteMerchantPricing(string $configId): JsonResponse
    {
        try {
            $config = GatewayPricingConfig::findOrFail($configId);
            $config->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Pricing configuration deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate fees for specific amount and gateway
     */
    public function calculateFees(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|string|exists:merchants,id',
            'gateway_code' => 'required|string',
            'payment_method_type' => 'required|string',
            'currency' => 'required|string|size:3',
            'amount' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            // Create a mock transaction for fee calculation
            $mockTransaction = new \App\Models\PaymentTransaction([
                'merchant_id' => $request->merchant_id,
                'gateway_code' => $request->gateway_code,
                'payment_method_type' => $request->payment_method_type,
                'currency' => $request->currency,
                'amount' => $request->amount,
            ]);

            $feeCalculation = $this->gatewayPricingService->calculateFeesForTransaction($mockTransaction);
            
            return response()->json([
                'success' => true,
                'data' => $feeCalculation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available gateway codes and payment method types
     */
    public function getGatewayOptions(): JsonResponse
    {
        try {
            $gatewayOptions = [
                'gateway_codes' => ['stripe', 'mpesa', 'telebirr', 'banking'],
                'payment_method_types' => ['card', 'mobile_money', 'bank_transfer'],
                'currencies' => ['USD', 'KES', 'ETB', 'EUR', 'GBP'],
                'tiers' => ['standard', 'premium', 'enterprise'],
            ];
            
            return response()->json([
                'success' => true,
                'data' => $gatewayOptions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}