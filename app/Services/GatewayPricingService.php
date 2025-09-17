<?php

namespace App\Services;

use App\Models\GatewayPricingConfig;
use App\Models\DefaultGatewayPricing;
use App\Models\PaymentTransaction;
use App\Models\Merchant;
use Illuminate\Support\Facades\Log;

class GatewayPricingService extends BaseService
{
    /**
     * Calculate fees for a payment transaction
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
        string $currency
    ): ?array {
        // Try merchant-specific pricing first
        $config = GatewayPricingConfig::getActiveConfig(
            $merchantId, 
            $gatewayCode, 
            $paymentMethodType, 
            $currency
        );

        if ($config) {
            return $config->toArray();
        }

        // Fall back to default pricing
        $defaultConfig = $this->getDefaultPricing(
            $gatewayCode, 
            $paymentMethodType, 
            $currency, 
            $merchantId
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
        string $merchantId
    ): ?DefaultGatewayPricing {
        // Determine merchant tier (could be enhanced based on volume, etc.)
        $tier = $this->getMerchantTier($merchantId);

        // Try to get tier-specific pricing
        $config = DefaultGatewayPricing::getDefaultConfig(
            $gatewayCode, 
            $paymentMethodType, 
            $currency, 
            $tier
        );

        // Fall back to standard tier if tier-specific not found
        if (!$config && $tier !== 'standard') {
            $config = DefaultGatewayPricing::getDefaultConfig(
                $gatewayCode, 
                $paymentMethodType, 
                $currency, 
                'standard'
            );
        }

        return $config;
    }

    /**
     * Calculate fees based on pricing configuration
     */
    private function calculateFees(float $amount, array $pricing): array
    {
        // Convert amount to smallest currency unit (cents/kobo/etc.)
        $amountInCents = round($amount * 100);

        $processingFee = ($amountInCents * $pricing['processing_fee_rate']) + $pricing['processing_fee_fixed'];
        $applicationFee = ($amountInCents * $pricing['application_fee_rate']) + $pricing['application_fee_fixed'];
        
        // Apply min/max limits to processing fee
        if ($pricing['min_fee'] > 0) {
            $processingFee = max($processingFee, $pricing['min_fee']);
        }
        if ($pricing['max_fee'] > 0) {
            $processingFee = min($processingFee, $pricing['max_fee']);
        }

        $totalFees = $processingFee + $applicationFee;

        // Convert back to main currency units
        return [
            'processing_fee' => round($processingFee / 100, 2),
            'application_fee' => round($applicationFee / 100, 2),
            'total_fees' => round($totalFees / 100, 2),
            'commission_amount' => round($applicationFee / 100, 2), // Platform commission
            'provider_amount' => round(($amountInCents - $totalFees) / 100, 2), // Amount after all fees
            'gateway_code' => $pricing['gateway_code'],
            'payment_method_type' => $pricing['payment_method_type'],
            'breakdown' => $pricing
        ];
    }

    /**
     * Get fallback pricing when no configuration is found
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
        
        $amountInCents = round($transaction->amount * 100);
        $processingFee = ($amountInCents * $rates['processing_fee_rate']) + $rates['processing_fee_fixed'];
        $applicationFee = ($amountInCents * $rates['application_fee_rate']);
        
        if (isset($rates['min_fee'])) {
            $processingFee = max($processingFee, $rates['min_fee']);
        }

        $totalFees = $processingFee + $applicationFee;

        return [
            'processing_fee' => round($processingFee / 100, 2),
            'application_fee' => round($applicationFee / 100, 2),
            'total_fees' => round($totalFees / 100, 2),
            'commission_amount' => round($applicationFee / 100, 2),
            'provider_amount' => round(($amountInCents - $totalFees) / 100, 2),
            'gateway_code' => $transaction->gateway_code,
            'payment_method_type' => $transaction->payment_method_type,
            'breakdown' => array_merge($rates, ['fallback' => true])
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
}