<?php

namespace App\Models;

class SupportedPayoutMethod extends BaseModel
{
    protected $table = 'supported_payout_methods';

    protected $fillable = [
        'country_code',
        'currency',
        'method_type',
        'method_name',
        'is_active',
        'min_amount',
        'max_amount',
        'processing_time_hours',
        'configuration',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'processing_time_hours' => 'integer',
        'configuration' => 'array',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry($query, string $countryCode)
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    public function scopeForCurrency($query, string $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }

    public function scopeForId($query, string $id)
    {
        return $query->where('id', $id);
    }

    public function scopeForAmount($query, float $amount)
    {
        return $query->where(function ($q) use ($amount) {
            $q->whereNull('min_amount')->orWhere('min_amount', '<=', $amount);
        })->where(function ($q) use ($amount) {
            $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
        });
    }

    /**
     * Get supported payout methods for a country and currency
     */
    public static function getForCountryAndCurrency(string $countryCode, string $currency, ?float $amount = null): array
    {
        $query = static::query()
            ->active()
            ->forCountry($countryCode)
            ->forCurrency($currency);

        if ($amount !== null) {
            $query->forAmount($amount);
        }

        return $query->orderBy('method_name')->get()->toArray();
    }

    /**
     * Get method type by beneficiary type
     */
    public static function getMethodTypeByBeneficiaryType(string $beneficiaryType, string $countryCode, string $currency): ?string
    {
        $methods = static::getForCountryAndCurrency($countryCode, $currency);

        $mapping = [
            'bank_account' => 'bank_transfer',
            'mobile_wallet' => 'mobile_money',
            'mobile_money' => 'mobile_money',
            'international_wire' => 'international_wire',
        ];

        $targetMethodType = $mapping[$beneficiaryType] ?? 'bank_transfer';

        foreach ($methods as $method) {
            if ($method['method_type'] === $targetMethodType) {
                return $method['method_type'];
            }
        }

        // Fallback to first available method
        return $methods[0]['method_type'] ?? 'bank_transfer';
    }

    /**
     * Get method by method type for country and currency
     */
    public static function getMethodByType(string $methodType, string $countryCode, string $currency): ?array
    {
        $method = static::query()
            ->active()
            ->forCountry($countryCode)
            ->forCurrency($currency)
            ->where('id', $methodType)
            ->first();

        return $method ? $method->toArray() : null;
    }

    /**
     * Get required fields configuration for a method
     */
    public static function getRequiredFields(string $methodType, string $countryCode, string $currency): array
    {
        $method = static::getMethodByType($methodType, $countryCode, $currency);

        if (!$method || !isset($method['configuration']['required_fields'])) {
            return [];
        }

        return $method['configuration']['required_fields'];
    }

    /**
     * Generate validation rules for a method
     */
    public static function getValidationRules(string $methodType, string $countryCode, string $currency): array
    {
        $requiredFields = static::getRequiredFields($methodType, $countryCode, $currency);
        $rules = [];

        foreach ($requiredFields as $fieldName => $fieldConfig) {
            $validation = $fieldConfig['validation'] ?? 'required';
            $rules[$fieldName] = $validation;
        }

        return $rules;
    }
}
