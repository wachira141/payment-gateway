<?php

namespace App\Models;

class SupportedBank extends BaseModel
{
    protected $table = 'supported_banks';

    protected $fillable = [
        'country_code',
        'bank_code',
        'bank_name',
        'swift_code',
        'routing_number',
        'is_active',
        'bank_type',
        'logo_url',
        'website_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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

    public function scopeByType($query, string $type)
    {
        return $query->where('bank_type', $type);
    }

    /**
     * Get banks for a specific country
     */
    public static function getForCountry(string $countryCode): array
    {
        return static::query()
            ->active()
            ->forCountry($countryCode)
            ->orderBy('bank_name')
            ->get()
            ->toArray();
    }

    /**
     * Find bank by code
     */
    public static function findByCode(string $bankCode): ?array
    {
        $bank = static::query()
            ->where('bank_code', $bankCode)
            ->first();

        return $bank ? $bank->toArray() : null;
    }

    /**
     * Validate bank exists for country
     */
    public static function validateBankForCountry(string $bankCode, string $countryCode): bool
    {
        return static::query()
            ->active()
            ->where('bank_code', $bankCode)
            ->forCountry($countryCode)
            ->exists();
    }
}