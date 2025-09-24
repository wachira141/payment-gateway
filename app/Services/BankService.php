<?php

namespace App\Services;

use App\Models\SupportedBank;

class BankService extends BaseService
{
    /**
     * Get all active banks for a specific country
     */
    public function getBanksForCountry(string $countryCode): array
    {
        return SupportedBank::getForCountry($countryCode);
    }

    /**
     * Find bank by bank code
     */
    public function findBankByCode(string $bankCode): ?array
    {
        return SupportedBank::findByCode($bankCode);
    }

    /**
     * Validate bank code exists for country
     */
    public function validateBankForCountry(string $bankCode, string $countryCode): bool
    {
        return SupportedBank::validateBankForCountry($bankCode, $countryCode);
    }

    /**
     * Get bank details with validation
     */
    public function getBankDetails(string $bankCode, string $countryCode): array
    {
        $bank = SupportedBank::findByCode($bankCode);
        
        if (!$bank) {
            throw new \Exception("Bank with code '{$bankCode}' not found");
        }

        if (strtoupper($bank['country_code']) !== strtoupper($countryCode)) {
            throw new \Exception("Bank '{$bankCode}' is not available in country '{$countryCode}'");
        }

        if (!$bank['is_active']) {
            throw new \Exception("Bank '{$bank['bank_name']}' is currently not available");
        }

        return $bank;
    }
}