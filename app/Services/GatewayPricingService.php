<?php

namespace App\Services;

use App\Models\GatewayPricingConfig;
use App\Models\DefaultGatewayPricing;
use App\Models\PaymentTransaction;
use App\Models\PayoutMethodGateway;
use App\Models\Merchant;
use App\Helpers\CurrencyHelper;
use App\Models\SupportedPayoutMethod;
use Illuminate\Support\Facades\Log;

/**
 * Gateway Pricing Service
 * 
 * Handles fee calculation for payment transactions and disbursements.
 * 
 * ================================================================================
 * FEE TERMINOLOGY & DOCUMENTATION
 * ================================================================================
 * 
 * GATEWAY PROCESSING FEE (processing_fee_rate + processing_fee_fixed):
 * ----------------------------------------------------------------------------
 * - The fee charged to cover payment gateway/provider costs
 * - This INCLUDES: actual gateway cost + optional platform markup (processing margin)
 * - Example: Gateway charges 2.9% + $0.30, we charge merchant 3.0% + $0.35
 *   - Actual gateway cost: 2.9% + $0.30
 *   - Processing margin (hidden profit): 0.1% + $0.05
 * 
 * PLATFORM APPLICATION FEE (application_fee_rate + application_fee_fixed):
 * ----------------------------------------------------------------------------
 * - The platform's EXPLICIT commission/revenue
 * - This is 100% platform profit (no gateway cost component)
 * - Clearly visible to merchants as "platform fee" or "service fee"
 * - Example: 0.5% platform fee on all transactions
 * 
 * TOTAL FEES = Gateway Processing Fee + Platform Application Fee
 * 
 * ================================================================================
 * TRUE PROFITABILITY CALCULATION
 * ================================================================================
 * 
 * For accurate profit tracking, we distinguish between:
 * 
 * 1. EXPLICIT PROFIT (Application Fee):
 *    - Platform application fee charged to merchant
 *    - 100% profit, clearly labeled
 * 
 * 2. IMPLICIT PROFIT (Processing Margin):
 *    - Processing fee charged MINUS actual gateway cost
 *    - Hidden markup built into processing fee
 *    - Formula: processing_fee_charged - actual_gateway_cost
 * 
 * 3. TOTAL PLATFORM REVENUE:
 *    - application_fee + processing_margin
 *    - True profit from each transaction
 * 
 * 4. ESTIMATED GATEWAY COST:
 *    - What we estimate the gateway actually charges us
 *    - Used when actual_gateway_cost is not configured
 *    - Can be refined with actual_gateway_cost_rate/fixed fields
 * 
 * ================================================================================
 * EXAMPLE CALCULATION (on $100 transaction)
 * ================================================================================
 * 
 * Configured rates:
 * - processing_fee_rate: 3.0%
 * - processing_fee_fixed: $0.35
 * - application_fee_rate: 0.5%
 * - actual_gateway_cost_rate: 2.9% (what gateway charges us)
 * - actual_gateway_cost_fixed: $0.30
 * 
 * Calculation:
 * - Gateway Processing Fee: $100 * 3.0% + $0.35 = $3.35 (charged to merchant)
 * - Platform Application Fee: $100 * 0.5% = $0.50 (charged to merchant)
 * - Actual Gateway Cost: $100 * 2.9% + $0.30 = $3.20 (paid to gateway)
 * - Processing Margin: $3.35 - $3.20 = $0.15 (hidden profit)
 * - Total Fees: $3.35 + $0.50 = $3.85
 * - Total Platform Revenue: $0.50 + $0.15 = $0.65 (true profit)
 * 
 * ================================================================================
 */
class GatewayPricingService extends BaseService
{
    /**
     * Calculate fees for a payment transaction
     * 
     * Returns a detailed fee breakdown including:
     * - gateway_processing_fee: Fee charged to cover gateway costs (may include margin)
     * - platform_application_fee: Platform's explicit commission (100% profit)
     * - estimated_gateway_cost: What we estimate the gateway charges us
     * - processing_margin: Hidden profit in processing fee
     * - total_platform_revenue: True profit = application_fee + processing_margin
     */
    public function calculateFeesForTransaction(PaymentTransaction $transaction): array
    {
        $pricing = $this->getPricingConfig(
            $transaction->merchant_id,
            $transaction->gateway_code,
            $transaction->payment_method_type,
            $transaction->currency
        );

        if (!$pricing) {
            Log::warning('No pricing configuration found, using fallback rates', [
                'merchant_id' => $transaction->merchant_id,
                'gateway_code' => $transaction->gateway_code,
                'payment_method_type' => $transaction->payment_method_type,
                'currency' => $transaction->currency,
            ]);
            
            return $this->getFallbackPricing($transaction);
        }

        return $this->calculateFees($transaction->amount, $pricing);
    }

    /**
     * Get pricing configuration with intelligent fallback
     */
    private function getPricingConfig(
        string $merchantId, 
        string $gatewayCode, 
        string $paymentMethodType, 
        string $currency,
        string $transactionType = 'collection'
    ): ?array {
        // Try merchant-specific pricing first
        $config = GatewayPricingConfig::getActiveConfig(
            $merchantId, 
            $gatewayCode, 
            $paymentMethodType, 
            $currency,
            $transactionType
        );

        if ($config) {
            return $config->toArray();
        }

        // Fall back to default pricing
        $defaultConfig = $this->getDefaultPricing(
            $gatewayCode, 
            $paymentMethodType, 
            $currency, 
            $merchantId,
            $transactionType
        );

        return $defaultConfig ? $defaultConfig->toArray() : null;
    }

    /**
     * Get default pricing with merchant tier consideration
     */
    private function getDefaultPricing(
        string $gatewayCode, 
        string $paymentMethodType, 
        string $currency, 
        string $merchantId,
        string $transactionType = 'collection'
    ): ?DefaultGatewayPricing {
        // Determine merchant tier (could be enhanced based on volume, etc.)
        $tier = $this->getMerchantTier($merchantId);

        // Try to get tier-specific pricing
        $config = DefaultGatewayPricing::getDefaultConfig(
            $gatewayCode, 
            $paymentMethodType, 
            $currency, 
            $tier,
            $transactionType
        );

        // Fall back to standard tier if tier-specific not found
        if (!$config && $tier !== 'standard') {
            $config = DefaultGatewayPricing::getDefaultConfig(
                $gatewayCode, 
                $paymentMethodType, 
                $currency, 
                'standard',
                $transactionType
            );
        }

        return $config;
    }

    // ==================== DISBURSEMENT FEE CALCULATION ====================

    /**
     * Calculate fees for a disbursement with proper gateway resolution
     * 
     * Returns enhanced fee breakdown with profitability metrics:
     * - gateway_processing_fee: Fee charged for gateway processing
     * - platform_application_fee: Platform's explicit commission
     * - estimated_gateway_cost: What gateway actually charges us
     * - processing_margin: Profit hidden in processing fee
     * - total_platform_revenue: True profit from transaction
     */
    public function calculateDisbursementFees(
        string $merchantId,
        array $beneficiary,
        int $amount,
        string $currency
    ): array {
        // Resolve gateway code using PayoutMethodGateway
        $gatewayCode = $this->resolvePayoutGatewayCode(
            $beneficiary['payout_method_id'] ?? null,
            $beneficiary['country'] ?? $beneficiary['country_code'] ?? 'KE',
            $currency,
            $amount
        );

        // Determine payment method type from beneficiary
        $paymentMethodType = $beneficiary['type'] ?? 'bank_transfer';

        // Get pricing config with transaction_type = 'disbursement'
        $pricing = $this->getPricingConfig(
            $merchantId,
            $gatewayCode,
            $paymentMethodType,
            $currency,
            'disbursement'
        );

        // Calculate fees and return detailed breakdown
        return $this->calculateFeesWithBreakdown($amount, $pricing, $gatewayCode, $paymentMethodType);
    }

    /**
     * Resolve gateway code from payout method using PayoutMethodGateway
     */
    private function resolvePayoutGatewayCode(
        ?string $payoutMethodId,
        string $countryCode,
        string $currency,
        ?int $amount = null
    ): string {
        if ($payoutMethodId) {
            $gatewayCode = PayoutMethodGateway::resolveGatewayCode(
                $payoutMethodId,
                $countryCode,
                $currency,
                $amount
            );

            if ($gatewayCode) {
                return $gatewayCode;
            }
        }

        // Fallback based on country/currency
        return $this->getDefaultPayoutGateway($countryCode, $currency);
    }

    /**
     * Get default payout gateway based on country and currency
     */
    private function getDefaultPayoutGateway(string $countryCode, string $currency): string
    {
        $countryGatewayMap = [
            'KE' => 'mpesa',
            'ET' => 'telebirr',
            'UG' => 'mtn_momo',
            'TZ' => 'mpesa',
            'GH' => 'mtn_momo',
        ];

        return $countryGatewayMap[strtoupper($countryCode)] ?? 'bank_transfer';
    }

    /**
     * Calculate fees with separate gateway and platform breakdown
     * 
     * Enhanced to include profitability metrics:
     * - estimated_gateway_cost: Estimated actual cost paid to gateway
     * - processing_margin: Hidden profit (processing_fee - estimated_gateway_cost)
     * - total_platform_revenue: True profit (application_fee + processing_margin)
     */
    private function calculateFeesWithBreakdown(
        int $amount, 
        ?array $pricing, 
        string $gatewayCode,
        string $paymentMethodType
    ): array {
        if (!$pricing) {
            return $this->getFallbackDisbursementPricing($amount, $gatewayCode, $paymentMethodType);
        }

        // Calculate fees charged to merchant
        $gatewayProcessingFee = ($amount * $pricing['processing_fee_rate']) + $pricing['processing_fee_fixed'];
        $platformApplicationFee = ($amount * $pricing['application_fee_rate']) + $pricing['application_fee_fixed'];

        // Apply min/max to processing fee
        if (isset($pricing['min_fee']) && $pricing['min_fee'] > 0) {
            $gatewayProcessingFee = max($gatewayProcessingFee, $pricing['min_fee']);
        }
        if (isset($pricing['max_fee']) && $pricing['max_fee'] > 0) {
            $gatewayProcessingFee = min($gatewayProcessingFee, $pricing['max_fee']);
        }

        $totalFees = $gatewayProcessingFee + $platformApplicationFee;

        // Calculate estimated actual gateway cost (what gateway charges us)
        $estimatedGatewayCost = $this->estimateActualGatewayCost($amount, $pricing, $gatewayCode);
        
        // Calculate processing margin (hidden profit in processing fee)
        $processingMargin = max(0, $gatewayProcessingFee - $estimatedGatewayCost);
        
        // Calculate total platform revenue (true profit)
        $totalPlatformRevenue = $platformApplicationFee + $processingMargin;

        return [
            // === FEES CHARGED TO MERCHANT ===
            'gateway_processing_fee' => (int) round($gatewayProcessingFee),
            'platform_application_fee' => (int) round($platformApplicationFee),
            'total_fees' => (int) round($totalFees),
            
            // === PROFITABILITY BREAKDOWN ===
            'estimated_gateway_cost' => (int) round($estimatedGatewayCost),
            'processing_margin' => (int) round($processingMargin),
            'total_platform_revenue' => (int) round($totalPlatformRevenue),
            
            // === BACKWARD COMPATIBILITY ===
            'processing_fee' => (int) round($gatewayProcessingFee),
            'application_fee' => (int) round($platformApplicationFee),
            'commission_amount' => (int) round($platformApplicationFee),
            
            // === TRANSACTION DETAILS ===
            'net_amount' => $amount - (int) round($totalFees),
            'gateway_code' => $gatewayCode,
            'payment_method_type' => $paymentMethodType,
            
            // === RATE BREAKDOWN ===
            'breakdown' => $pricing,
            
            // === FEE CLASSIFICATION (for documentation) ===
            'fee_classification' => [
                'gateway_processing_fee' => [
                    'description' => 'Fee charged to cover payment gateway costs',
                    'includes_margin' => $processingMargin > 0,
                    'margin_amount' => (int) round($processingMargin),
                ],
                'platform_application_fee' => [
                    'description' => 'Platform commission (100% explicit profit)',
                    'type' => 'explicit_commission',
                ],
                'total_platform_revenue' => [
                    'description' => 'True platform profit (application_fee + processing_margin)',
                    'components' => [
                        'application_fee' => (int) round($platformApplicationFee),
                        'processing_margin' => (int) round($processingMargin),
                    ],
                ],
            ],
        ];
    }

    /**
     * Estimate the actual cost the gateway charges us
     * 
     * Uses actual_gateway_cost_rate/fixed if configured,
     * otherwise falls back to industry-standard estimates
     */
    private function estimateActualGatewayCost(int $amount, array $pricing, string $gatewayCode): float
    {
        // Use configured actual gateway cost if available
        if (isset($pricing['actual_gateway_cost_rate']) || isset($pricing['actual_gateway_cost_fixed'])) {
            $rate = $pricing['actual_gateway_cost_rate'] ?? 0;
            $fixed = $pricing['actual_gateway_cost_fixed'] ?? 0;
            return ($amount * $rate) + $fixed;
        }

        // Fallback: estimate based on typical gateway costs
        $estimatedCostRates = [
            'mpesa' => ['rate' => 0.008, 'fixed' => 1000],      // M-Pesa ~0.8% + 10 KES
            'telebirr' => ['rate' => 0.01, 'fixed' => 300],     // Telebirr ~1% + 3 ETB
            'mtn_momo' => ['rate' => 0.012, 'fixed' => 800],    // MTN MoMo ~1.2% + 8 local
            'bank_transfer' => ['rate' => 0.003, 'fixed' => 4000], // Bank ~0.3% + 40 local
            'stripe' => ['rate' => 0.029, 'fixed' => 30],       // Stripe 2.9% + $0.30
            'paypal' => ['rate' => 0.029, 'fixed' => 30],       // PayPal 2.9% + $0.30
        ];

        $costs = $estimatedCostRates[$gatewayCode] ?? ['rate' => 0.02, 'fixed' => 50];
        return ($amount * $costs['rate']) + $costs['fixed'];
    }

    /**
     * Get fallback disbursement pricing when no configuration found
     * 
     * Includes profitability metrics with estimated gateway costs
     */
    private function getFallbackDisbursementPricing(
        int $amount,
        string $gatewayCode,
        string $paymentMethodType
    ): array {
        $fallbackRates = [
            'mpesa' => ['processing_fee_rate' => 0.01, 'processing_fee_fixed' => 1500, 'application_fee_rate' => 0.002],
            'telebirr' => ['processing_fee_rate' => 0.012, 'processing_fee_fixed' => 500, 'application_fee_rate' => 0.002],
            'mtn_momo' => ['processing_fee_rate' => 0.015, 'processing_fee_fixed' => 1000, 'application_fee_rate' => 0.002],
            'bank_transfer' => ['processing_fee_rate' => 0.005, 'processing_fee_fixed' => 5000, 'application_fee_rate' => 0.003],
        ];

        $rates = $fallbackRates[$gatewayCode] ?? $fallbackRates['bank_transfer'];

        $gatewayProcessingFee = ($amount * $rates['processing_fee_rate']) + $rates['processing_fee_fixed'];
        $platformApplicationFee = $amount * $rates['application_fee_rate'];
        $totalFees = $gatewayProcessingFee + $platformApplicationFee;

        // Estimate gateway cost for fallback
        $estimatedGatewayCost = $this->estimateActualGatewayCost($amount, [], $gatewayCode);
        $processingMargin = max(0, $gatewayProcessingFee - $estimatedGatewayCost);
        $totalPlatformRevenue = $platformApplicationFee + $processingMargin;

        return [
            // === FEES CHARGED TO MERCHANT ===
            'gateway_processing_fee' => (int) round($gatewayProcessingFee),
            'platform_application_fee' => (int) round($platformApplicationFee),
            'total_fees' => (int) round($totalFees),
            
            // === PROFITABILITY BREAKDOWN ===
            'estimated_gateway_cost' => (int) round($estimatedGatewayCost),
            'processing_margin' => (int) round($processingMargin),
            'total_platform_revenue' => (int) round($totalPlatformRevenue),
            
            // === BACKWARD COMPATIBILITY ===
            'processing_fee' => (int) round($gatewayProcessingFee),
            'application_fee' => (int) round($platformApplicationFee),
            'commission_amount' => (int) round($platformApplicationFee),
            
            // === TRANSACTION DETAILS ===
            'net_amount' => $amount - (int) round($totalFees),
            'gateway_code' => $gatewayCode,
            'payment_method_type' => $paymentMethodType,
            'breakdown' => array_merge($rates, ['fallback' => true]),
            
            // === FEE CLASSIFICATION ===
            'fee_classification' => [
                'gateway_processing_fee' => [
                    'description' => 'Fee charged to cover payment gateway costs',
                    'includes_margin' => $processingMargin > 0,
                    'margin_amount' => (int) round($processingMargin),
                ],
                'platform_application_fee' => [
                    'description' => 'Platform commission (100% explicit profit)',
                    'type' => 'explicit_commission',
                ],
            ],
        ];
    }

    /**
     * Calculate fees based on pricing configuration
     * 
     * Enhanced to include true profitability metrics:
     * - gateway_processing_fee: What we charge for gateway processing
     * - platform_application_fee: Explicit platform commission
     * - estimated_gateway_cost: What gateway charges us
     * - processing_margin: Hidden profit in processing fee
     * - total_platform_revenue: True profit
     */
    private function calculateFees(float $amount, array $pricing): array
    {
        // Calculate fees charged to merchant (amount already in smallest unit)
        $gatewayProcessingFee = ($amount * $pricing['processing_fee_rate']) + $pricing['processing_fee_fixed'];
        $platformApplicationFee = ($amount * $pricing['application_fee_rate']) + $pricing['application_fee_fixed'];
        
        // Apply min/max limits to processing fee
        if ($pricing['min_fee'] > 0) {
            $gatewayProcessingFee = max($gatewayProcessingFee, $pricing['min_fee']);
        }
        if ($pricing['max_fee'] > 0) {
            $gatewayProcessingFee = min($gatewayProcessingFee, $pricing['max_fee']);
        }

        $totalFees = $gatewayProcessingFee + $platformApplicationFee;

        // Estimate actual gateway cost
        $gatewayCode = $pricing['gateway_code'] ?? 'unknown';
        $estimatedGatewayCost = $this->estimateActualGatewayCost((int) $amount, $pricing, $gatewayCode);
        
        // Calculate processing margin and total platform revenue
        $processingMargin = max(0, $gatewayProcessingFee - $estimatedGatewayCost);
        $totalPlatformRevenue = $platformApplicationFee + $processingMargin;

        // Convert back to main currency units for display
        return [
            // === FEES CHARGED TO MERCHANT (in main currency units) ===
            'gateway_processing_fee' => round($gatewayProcessingFee / 100, 2),
            'platform_application_fee' => round($platformApplicationFee / 100, 2),
            'total_fees' => round($totalFees / 100, 2),
            
            // === PROFITABILITY BREAKDOWN (in main currency units) ===
            'estimated_gateway_cost' => round($estimatedGatewayCost / 100, 2),
            'processing_margin' => round($processingMargin / 100, 2),
            'total_platform_revenue' => round($totalPlatformRevenue / 100, 2),
            
            // === BACKWARD COMPATIBILITY ===
            'processing_fee' => round($gatewayProcessingFee / 100, 2),
            'application_fee' => round($platformApplicationFee / 100, 2),
            'commission_amount' => round($platformApplicationFee / 100, 2),
            'provider_amount' => round(($amount - $totalFees) / 100, 2),
            
            // === TRANSACTION DETAILS ===
            'gateway_code' => $gatewayCode,
            'payment_method_type' => $pricing['payment_method_type'],
            'breakdown' => $pricing,
            
            // === FEE CLASSIFICATION ===
            'fee_classification' => [
                'gateway_processing_fee' => [
                    'description' => 'Fee charged to cover payment gateway costs',
                    'includes_margin' => $processingMargin > 0,
                    'margin_amount' => round($processingMargin / 100, 2),
                ],
                'platform_application_fee' => [
                    'description' => 'Platform commission (100% explicit profit)',
                    'type' => 'explicit_commission',
                ],
                'total_platform_revenue' => [
                    'description' => 'True platform profit = application_fee + processing_margin',
                    'components' => [
                        'explicit_commission' => round($platformApplicationFee / 100, 2),
                        'processing_margin' => round($processingMargin / 100, 2),
                    ],
                ],
            ],
        ];
    }

    /**
     * Get fallback pricing when no configuration is found
     * 
     * Includes estimated gateway costs and profitability metrics
     */
    private function getFallbackPricing(PaymentTransaction $transaction): array
    {
        // Fallback rates based on gateway type
        $fallbackRates = [
            'mpesa' => [
                'processing_fee_rate' => 0.015, // 1.5%
                'application_fee_rate' => 0.005, // 0.5%
                'processing_fee_fixed' => 0,
                'min_fee' => 500, // 5 KES in cents
            ],
            'stripe' => [
                'processing_fee_rate' => 0.029, // 2.9%
                'application_fee_rate' => 0.005, // 0.5%
                'processing_fee_fixed' => 30, // $0.30 in cents
                'min_fee' => 50, // $0.50 in cents
            ],
            'telebirr' => [
                'processing_fee_rate' => 0.02, // 2.0%
                'application_fee_rate' => 0.005, // 0.5%
                'processing_fee_fixed' => 0,
                'min_fee' => 200, // 2 ETB in cents
            ],
        ];

        $rates = $fallbackRates[$transaction->gateway_code] ?? $fallbackRates['stripe'];

        $gatewayProcessingFee = ($transaction->amount * $rates['processing_fee_rate']) + $rates['processing_fee_fixed'];
        $platformApplicationFee = ($transaction->amount * $rates['application_fee_rate']);
        
        if (isset($rates['min_fee'])) {
            $gatewayProcessingFee = max($gatewayProcessingFee, $rates['min_fee']);
        }

        $totalFees = $gatewayProcessingFee + $platformApplicationFee;

        // Estimate gateway cost
        $estimatedGatewayCost = $this->estimateActualGatewayCost((int) $transaction->amount, [], $transaction->gateway_code);
        $processingMargin = max(0, $gatewayProcessingFee - $estimatedGatewayCost);
        $totalPlatformRevenue = $platformApplicationFee + $processingMargin;

        return [
            // === FEES CHARGED TO MERCHANT ===
            'gateway_processing_fee' => round($gatewayProcessingFee / 100, 2),
            'platform_application_fee' => round($platformApplicationFee / 100, 2),
            'total_fees' => round($totalFees / 100, 2),
            
            // === PROFITABILITY BREAKDOWN ===
            'estimated_gateway_cost' => round($estimatedGatewayCost / 100, 2),
            'processing_margin' => round($processingMargin / 100, 2),
            'total_platform_revenue' => round($totalPlatformRevenue / 100, 2),
            
            // === BACKWARD COMPATIBILITY ===
            'processing_fee' => round($gatewayProcessingFee / 100, 2),
            'application_fee' => round($platformApplicationFee / 100, 2),
            'commission_amount' => round($platformApplicationFee / 100, 2),
            'provider_amount' => round(($transaction->amount - $totalFees) / 100, 2),
            
            // === TRANSACTION DETAILS ===
            'gateway_code' => $transaction->gateway_code,
            'payment_method_type' => $transaction->payment_method_type,
            'breakdown' => array_merge($rates, ['fallback' => true]),
            
            // === FEE CLASSIFICATION ===
            'fee_classification' => [
                'gateway_processing_fee' => [
                    'description' => 'Fee charged to cover payment gateway costs',
                    'includes_margin' => $processingMargin > 0,
                ],
                'platform_application_fee' => [
                    'description' => 'Platform commission (100% explicit profit)',
                    'type' => 'explicit_commission',
                ],
            ],
        ];
    }

    /**
     * Determine merchant tier based on volume or other factors
     */
    private function getMerchantTier(string $merchantId): string
    {
        // This could be enhanced to consider:
        // - Monthly transaction volume
        // - Total processed amount
        // - Account age
        // - Negotiated rates
        
        $merchant = Merchant::find($merchantId);
        
        // Simple tier logic - could be more sophisticated
        if ($merchant && isset($merchant->metadata['tier'])) {
            return $merchant->metadata['tier'];
        }

        return 'standard';
    }

    /**
     * Create or update merchant-specific pricing
     */
    public function createMerchantPricing(array $pricingData): GatewayPricingConfig
    {
        return GatewayPricingConfig::create($pricingData);
    }

    /**
     * Get pricing summary for merchant
     */
    public function getMerchantPricingSummary(string $merchantId): array
    {
        $merchantConfigs = GatewayPricingConfig::where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->get();

        $defaultConfigs = DefaultGatewayPricing::where('is_active', true)->get();

        return [
            'merchant_specific' => $merchantConfigs,
            'default_configs' => $defaultConfigs,
            'coverage' => $this->calculatePricingCoverage($merchantConfigs, $defaultConfigs)
        ];
    }

    /**
     * Calculate how much gateway coverage merchant has
     */
    private function calculatePricingCoverage($merchantConfigs, $defaultConfigs): array
    {
        $merchantGateways = $merchantConfigs->map(function ($config) {
            return "{$config->gateway_code}_{$config->payment_method_type}_{$config->currency}";
        })->unique();

        $totalGateways = $defaultConfigs->map(function ($config) {
            return "{$config->gateway_code}_{$config->payment_method_type}_{$config->currency}";
        })->unique();

        $coveragePercentage = $totalGateways->count() > 0 
            ? ($merchantGateways->count() / $totalGateways->count()) * 100 
            : 0;

        return [
            'merchant_specific_count' => $merchantGateways->count(),
            'total_available_count' => $totalGateways->count(),
            'coverage_percentage' => round($coveragePercentage, 2)
        ];
    }


    /**
     * Calculate payout fees based on beneficiary and amount
     */
    public function calculatePayoutFees(
        string $merchantId,
        array $beneficiary,
        int $amount,
        string $currency
    ): array {
        $payoutMethod = $this->mapBeneficiaryToPayoutMethod($beneficiary);
        
        $pricing = $this->getPricingConfig(
            $merchantId,
            'payout',
            $payoutMethod,
            $currency
        );

        if (!$pricing) {
            Log::warning('No payout pricing configuration found, using fallback rates', [
                'merchant_id' => $merchantId,
                'payout_method' => $payoutMethod,
                'currency' => $currency,
            ]);
            
            return $this->getFallbackPayoutPricing($amount, $currency, $payoutMethod);
        }

        return $this->calculateFees($amount, $pricing);
    }

    /**
     * Map beneficiary type to payout method
     */
    private function mapBeneficiaryToPayoutMethod(array $beneficiary): string
    {
        return SupportedPayoutMethod::forId($beneficiary['payout_method_id']);
    }

    /**
     * Get fallback payout pricing when no configuration is found
     */
    private function getFallbackPayoutPricing(int $amount, string $currency, string $payoutMethod): array
    {
        $fallbackRates = [
            'bank_transfer' => [
                'KES' => ['processing_fee_rate' => 0.005, 'processing_fee_fixed' => 5000, 'min_fee' => 10000],
                'USD' => ['processing_fee_rate' => 0.008, 'processing_fee_fixed' => 50, 'min_fee' => 100],
                'ETB' => ['processing_fee_rate' => 0.006, 'processing_fee_fixed' => 2000, 'min_fee' => 5000],
                'default' => ['processing_fee_rate' => 0.01, 'processing_fee_fixed' => 100, 'min_fee' => 100],
            ],
            'mobile_money' => [
                'KES' => ['processing_fee_rate' => 0.003, 'processing_fee_fixed' => 2000, 'min_fee' => 5000],
                'ETB' => ['processing_fee_rate' => 0.004, 'processing_fee_fixed' => 1000, 'min_fee' => 3000],
                'default' => ['processing_fee_rate' => 0.005, 'processing_fee_fixed' => 100, 'min_fee' => 100],
            ],
        ];

        $methodRates = $fallbackRates[$payoutMethod] ?? $fallbackRates['bank_transfer'];
        $rates = $methodRates[$currency] ?? $methodRates['default'];
        
        $gatewayProcessingFee = ($amount * $rates['processing_fee_rate']) + $rates['processing_fee_fixed'];
        $platformApplicationFee = $amount * 0.002; // 0.2% platform fee
        
        if (isset($rates['min_fee'])) {
            $gatewayProcessingFee = max($gatewayProcessingFee, $rates['min_fee']);
        }

        $totalFees = $gatewayProcessingFee + $platformApplicationFee;

        // Estimate gateway cost
        $estimatedGatewayCost = $this->estimateActualGatewayCost($amount, [], $payoutMethod === 'bank_transfer' ? 'bank_transfer' : 'mobile_money');
        $processingMargin = max(0, $gatewayProcessingFee - $estimatedGatewayCost);
        $totalPlatformRevenue = $platformApplicationFee + $processingMargin;

        return [
            'gateway_processing_fee' => round($gatewayProcessingFee / 100, 2),
            'platform_application_fee' => round($platformApplicationFee / 100, 2),
            'total_fees' => round($totalFees / 100, 2),
            'estimated_gateway_cost' => round($estimatedGatewayCost / 100, 2),
            'processing_margin' => round($processingMargin / 100, 2),
            'total_platform_revenue' => round($totalPlatformRevenue / 100, 2),
            'processing_fee' => round($gatewayProcessingFee / 100, 2),
            'application_fee' => round($platformApplicationFee / 100, 2),
            'commission_amount' => round($platformApplicationFee / 100, 2),
            'provider_amount' => round(($amount - $totalFees) / 100, 2),
            'gateway_code' => 'payout',
            'payment_method_type' => $payoutMethod,
            'breakdown' => array_merge($rates, ['fallback' => true])
        ];
    }
}
