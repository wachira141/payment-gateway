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
    public function getBeneficiariesForMerchant(string $merchantId, array $filters = [])
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
    /**
     * Create a new beneficiary
     */
    public function createBeneficiary(string $merchantId, array $data): array
    {
        // Generate unique beneficiary ID
        $data['beneficiary_id'] = 'ben_' . Str::random(24);
        $data['merchant_id'] = $merchantId;
        $data['status'] = 'active'; // Default to pending verification
        $data['is_verified'] = false;

        // Extract dynamic fields and payout method ID
        $payoutMethodId = $data['payout_method_id'] ?? null;
        $dynamicFields = $data['dynamic_fields'] ?? [];

        if (!$payoutMethodId) {
            throw new \Exception("Payout method ID is required");
        }

        // Set the payout method ID and dynamic fields
        $data['payout_method_id'] = $payoutMethodId;
        $data['dynamic_fields'] = $dynamicFields;

        // Validate beneficiary data using dynamic configuration
        $this->validateBeneficiaryDataDynamic($data);

        // If setting as default, remove default from other beneficiaries with same currency
        if (!empty($data['is_default']) && $data['is_default']) {
            $this->unsetDefaultBeneficiaries($merchantId, $data['currency']);
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
            $this->unsetDefaultBeneficiaries($merchantId, $beneficiary['currency']);
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



    /**
     * Validate beneficiary data using dynamic configuration
     */
    /**
     * Validate beneficiary data using dynamic configuration
     */
    private function validateBeneficiaryDataDynamic(array $data): void
    {
        $payoutMethodId = $data['payout_method_id'] ?? null;
        $dynamicFields = $data['dynamic_fields'] ?? [];

        if (!$payoutMethodId) {
            throw new \Exception("Payout method ID is required for beneficiary validation");
        }

        // Get the payout method configuration
        $payoutMethod = \App\Models\SupportedPayoutMethod::find($payoutMethodId);

        if (!$payoutMethod) {
            throw new \Exception("Invalid payout method ID");
        }

        // Get required fields configuration for this method
        $configuration = $payoutMethod->configuration ?? [];
        $requiredFields = $configuration['required_fields'] ?? [];

        // Validate each required field
        foreach ($requiredFields as $fieldName => $fieldConfig) {
            $isRequired = str_contains($fieldConfig['validation'] ?? '', 'required');
            $fieldValue = $dynamicFields[$fieldName] ?? null;

            if ($isRequired && empty($fieldValue)) {
                $label = $fieldConfig['label'] ?? $fieldName;
                throw new \Exception("Field '{$label}' is required for this payout method");
            }

            // Perform type-specific validation
            if (!empty($fieldValue)) {
                $this->validateFieldByType($fieldName, $fieldValue, $fieldConfig, $data);
            }
        }
    }

    /**
     * Validate individual field based on its type and configuration
     */
    private function validateFieldByType(string $fieldName, $value, array $fieldConfig, array $data): void
    {
        $fieldType = $fieldConfig['type'] ?? 'text';

        switch ($fieldType) {
            case 'phone':
                $this->validateMobileNumber($value, $data['country']);
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception("Invalid email format for field '{$fieldName}'");
                }
                break;

            case 'bank_select':
                // Bank code validation is handled in the request validation
                break;

            case 'text':
            default:
                // Basic text validation (length, format) can be added here
                break;
        }
    }

    /**
     * Map method type to legacy type for backward compatibility
     */
    private function mapMethodTypeToLegacyType(string $methodType): string
    {
        $mapping = [
            'bank_transfer' => 'bank_account',
            'mobile_money' => 'mobile_money',
            'international_wire' => 'bank_account',
            'paypal' => 'paypal',
            'stripe' => 'stripe',
        ];

        return $mapping[$methodType] ?? 'bank_account';
    }

    /**
     * Validate bank account specific data
     */
    private function validateBankAccountData(array $data): void
    {
        $required = ['account_number', 'bank_code'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \Exception("Field '{$field}' is required for bank account beneficiaries");
            }
        }

        // Validate account number format
        if (isset($data['account_number'])) {
            $this->validateAccountNumber($data['account_number'], $data['country']);
        }
    }

    /**
     * Validate mobile money specific data
     */
    private function validateMobileMoneyData(array $data): void
    {
        $required = ['mobile_number'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \Exception("Field '{$field}' is required for mobile money beneficiaries");
            }
        }

        // Validate mobile number format
        if (isset($data['mobile_number'])) {
            $this->validateMobileNumber($data['mobile_number'], $data['country']);
        }
    }
}
