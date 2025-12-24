<?php

namespace App\Models;

use App\Models\BaseModel;

class KycCountryRequirement extends BaseModel
{
    protected $table = 'kyc_country_requirements';

    protected $fillable = [
        'country_code',
        'tier_level',
        'tier_name',
        'required_documents',
        'optional_documents',
        'required_fields',
        'daily_limit',
        'monthly_limit',
        'single_transaction_limit',
        'limit_currency',
        'description',
        'tier_benefits',
        'is_active',
    ];

    protected $casts = [
        'tier_level' => 'integer',
        'required_documents' => 'array',
        'optional_documents' => 'array',
        'required_fields' => 'array',
        'tier_benefits' => 'array',
        'daily_limit' => 'decimal:2',
        'monthly_limit' => 'decimal:2',
        'single_transaction_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get requirements for a specific country
     */
    public static function getForCountry(string $countryCode): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('country_code', $countryCode)
            ->where('is_active', true)
            ->orderBy('tier_level')
            ->get();
    }

    /**
     * Get specific tier requirements for a country
     */
    public static function getForCountryAndTier(string $countryCode, int $tierLevel): ?self
    {
        return static::where('country_code', $countryCode)
            ->where('tier_level', $tierLevel)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get all active countries with KYC configured
     */
    public static function getActiveCountries(): array
    {
        return static::where('is_active', true)
            ->distinct('country_code')
            ->pluck('country_code')
            ->toArray();
    }

    /**
     * Get document types for this tier
     */
    public function getDocumentTypes()
    {
        return KycDocumentType::where('country_code', $this->country_code)
            ->whereIn('document_key', $this->required_documents)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Check if a document is required for this tier
     */
    public function requiresDocument(string $documentKey): bool
    {
        return in_array($documentKey, $this->required_documents ?? []);
    }

    /**
     * Check if a document is optional for this tier
     */
    public function isDocumentOptional(string $documentKey): bool
    {
        return in_array($documentKey, $this->optional_documents ?? []);
    }

    /**
     * Get all documents (required + optional) for this tier
     */
    public function getAllDocuments(): array
    {
        return array_merge(
            $this->required_documents ?? [],
            $this->optional_documents ?? []
        );
    }
}
