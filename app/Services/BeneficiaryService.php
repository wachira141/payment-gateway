<?php

namespace App\Services;

use App\Models\Beneficiary;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BeneficiaryService extends BaseService
{
    /**
     * Get beneficiaries for a merchant with filters
     */
    public function getBeneficiariesForMerchant(string $merchantId, array $filters = []): array
    {
        return Beneficiary::getForMerchant($merchantId, $filters);
    }

    /**
     * Get a beneficiary by ID for a merchant
     */
    public function getBeneficiaryById(string $beneficiaryId, string $merchantId): ?array
    {
        return Beneficiary::findByIdAndMerchant($beneficiaryId, $merchantId);
    }

    /**
     * Create a new beneficiary
     */
    public function createBeneficiary(string $merchantId, array $data): array
    {
        // Validate bank details based on type
        $this->validateBeneficiaryData($data);

        $data['beneficiary_id'] = 'ben_' . Str::random(24);
        $data['merchant_id'] = $merchantId;

        // If setting as default, remove default from other beneficiaries with same currency
        if (!empty($data['is_default']) && $data['is_default']) {
            Beneficiary::setAsDefault($data['beneficiary_id'], $merchantId);
        }

        $beneficiary = Beneficiary::create($data);
        return $beneficiary->toArray();
    }

    /**
     * Update beneficiary
     */
    public function updateBeneficiary(string $beneficiaryId, string $merchantId, array $data): ?array
    {
        $beneficiary = Beneficiary::findByIdAndMerchant($beneficiaryId, $merchantId);
        
        if (!$beneficiary) {
            return null;
        }

        // If setting as default, handle it properly
        if (!empty($data['is_default']) && $data['is_default']) {
            Beneficiary::setAsDefault($beneficiaryId, $merchantId);
        }
        // if status is being changed to inactive or suspended, ensure no pending payouts
        if (isset($data['status'])) {
            if ($this->hasPendingPayouts($beneficiaryId)) {
                throw new \Exception('Cannot change status while there are pending payouts');
            }
        }

        return Beneficiary::updateById($beneficiaryId, $data);
    }

    /**
     * Delete beneficiary
     */
    public function deleteBeneficiary(string $beneficiaryId, string $merchantId): bool
    {
        return Beneficiary::deleteByIdAndMerchant($beneficiaryId, $merchantId);
    }

    /**
     * Validate beneficiary data based on type
     */
    private function validateBeneficiaryData(array $data): void
    {
        switch ($data['type']) {
            case 'bank_account':
                if (empty($data['bank_code']) || empty($data['bank_name'])) {
                    throw new \Exception('Bank code and bank name are required for bank account beneficiaries');
                }
                
                // Validate account number format for different countries
                $this->validateAccountNumber($data['account_number'], $data['country']);
                break;

            case 'mobile_money':
                if (empty($data['mobile_number'])) {
                    throw new \Exception('Mobile number is required for mobile money beneficiaries');
                }
                
                // Validate mobile number format
                $this->validateMobileNumber($data['mobile_number'], $data['country']);
                break;

            default:
                throw new \Exception('Invalid beneficiary type');
        }
    }

    /**
     * Validate account number format based on country
     */
    private function validateAccountNumber(string $accountNumber, string $country): void
    {
        $patterns = [
            'KE' => '/^[0-9]{10,13}$/', // Kenya bank accounts
            'UG' => '/^[0-9]{10,15}$/', // Uganda bank accounts
            'TZ' => '/^[0-9]{10,16}$/', // Tanzania bank accounts
            'NG' => '/^[0-9]{10}$/',    // Nigeria bank accounts (NUBAN)
            'GH' => '/^[0-9]{13}$/',    // Ghana bank accounts
            'ZA' => '/^[0-9]{9,11}$/',  // South Africa bank accounts
            'US' => '/^[0-9]{8,17}$/',  // US bank accounts
            'GB' => '/^[0-9]{8}$/',     // UK bank accounts
        ];

        if (isset($patterns[$country])) {
            if (!preg_match($patterns[$country], $accountNumber)) {
                throw new \Exception('Invalid account number format for ' . $country);
            }
        }
    }

    /**
     * Validate mobile number format based on country
     */
    private function validateMobileNumber(string $mobileNumber, string $country): void
    {
        // Remove any non-digit characters for validation
        $cleanNumber = preg_replace('/[^0-9]/', '', $mobileNumber);

        $patterns = [
            'KE' => '/^(254|0)[0-9]{9}$/',    // Kenya: +254 or 0
            'UG' => '/^(256|0)[0-9]{9}$/',    // Uganda: +256 or 0
            'TZ' => '/^(255|0)[0-9]{9}$/',    // Tanzania: +255 or 0
            'NG' => '/^(234|0)[0-9]{10}$/',   // Nigeria: +234 or 0
            'GH' => '/^(233|0)[0-9]{9}$/',    // Ghana: +233 or 0
        ];

        if (isset($patterns[$country])) {
            if (!preg_match($patterns[$country], $cleanNumber)) {
                throw new \Exception('Invalid mobile number format for ' . $country);
            }
        }
    }

    /**
     * Unset default flag for other beneficiaries of the same currency
     */
    private function unsetDefaultBeneficiaries(string $merchantId, string $currency): void
    {
        Beneficiary::where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Check if beneficiary has pending payouts
     */
    private function hasPendingPayouts(string $beneficiaryId): bool
    {
        // This would check the Payout model for pending payouts
        // For now, return false as placeholder
        return false;
    }

    /**
     * Initiate verification process for beneficiary
     */
    private function initiateVerification(string $beneficiaryId): void
    {
        // Simulate bank account verification process
        // In real implementation, this would integrate with bank verification services
        
        // For demo purposes, automatically verify after a short delay
        // In production, this would be handled by background jobs
        Beneficiary::updateById($beneficiaryId, [
            'is_verified' => true,
            'status' => 'verified',
            'verified_at' => now()
        ]);
    }

    /**
     * Get default beneficiary for currency
     */
    public function getDefaultBeneficiary(string $merchantId, string $currency): ?array
    {
        $beneficiary = Beneficiary::where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->where('is_default', true)
            ->where('is_verified', true)
            ->first();

        return $beneficiary ? $beneficiary->toArray() : null;
    }

    /**
     * Verify beneficiary details with banking system
     */
    public function verifyBeneficiary(string $beneficiaryId, string $merchantId): array
    {
        $beneficiary = Beneficiary::findByIdAndMerchant($beneficiaryId, $merchantId);
        
        if (!$beneficiary) {
            throw new \Exception('Beneficiary not found');
        }

        if ($beneficiary['is_verified']) {
            return $beneficiary;
        }

        // Simulate verification process
        $verificationResult = $this->performBankVerification($beneficiary);
        
        if ($verificationResult['success']) {
            return Beneficiary::updateById($beneficiaryId, [
                'is_verified' => true,
                'status' => 'verified',
                'verified_at' => now(),
                'verification_details' => $verificationResult['details']
            ]);
        } else {
            return Beneficiary::updateById($beneficiaryId, [
                'status' => 'verification_failed',
                'verification_failure_reason' => $verificationResult['reason']
            ]);
        }
    }

    /**
     * Perform bank verification (simulation)
     */
    private function performBankVerification(array $beneficiary): array
    {
        // Simulate bank verification API call
        // In real implementation, this would integrate with banking APIs
        
        // Simulate 95% success rate
        $success = rand(1, 100) > 5;
        
        if ($success) {
            return [
                'success' => true,
                'details' => [
                    'account_name' => $beneficiary['name'],
                    'account_status' => 'active',
                    'bank_verification_id' => 'bvn_' . Str::random(12)
                ]
            ];
        } else {
            return [
                'success' => false,
                'reason' => 'Account not found or inactive'
            ];
        }
    }
}