<?php

namespace App\Services;

use App\Models\SupportedPayoutMethod;

class PayoutMethodService extends BaseService
{
    /**
     * Get supported payout methods for country and currency
     */
    public function getMethodsForCountryAndCurrency(string $countryCode, string $currency, ?float $amount = null): array
    {
        return SupportedPayoutMethod::getForCountryAndCurrency($countryCode, $currency, $amount);
    }

    /**
     * Get method type by beneficiary type (database-driven)
     */
    public function getMethodTypeByBeneficiaryType(string $beneficiaryType, string $countryCode, string $currency): string
    {
        return SupportedPayoutMethod::getMethodTypeByBeneficiaryType($beneficiaryType, $countryCode, $currency);
    }

    /**
     * Validate payout method is supported
     */
    public function validateMethodForCountryAndCurrency(string $methodType, string $countryCode, string $currency, ?float $amount = null): bool
    {
        $methods = $this->getMethodsForCountryAndCurrency($countryCode, $currency, $amount);
        
        foreach ($methods as $method) {
            if ($method['method_type'] === $methodType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get processing time for method
     */
    public function getProcessingTimeForMethod(string $methodType, string $countryCode, string $currency): int
    {
        $methods = $this->getMethodsForCountryAndCurrency($countryCode, $currency);
        
        foreach ($methods as $method) {
            if ($method['method_type'] === $methodType) {
                return $method['processing_time_hours'];
            }
        }

        return 24; // Default fallback
    }
}