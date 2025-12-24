<?php

namespace App\Services;

use App\Models\KycCountryRequirement;
use App\Models\KycDocumentType;
use Illuminate\Support\Facades\Cache;

class KycConfigurationService extends BaseService
{
    /**
     * Cache TTL in seconds (1 hour)
     */
    protected const CACHE_TTL = 3600;

    /**
     * Get all tier requirements for a country
     */
    public function getCountryRequirements(string $countryCode): array
    {
        $cacheKey = "kyc_requirements_{$countryCode}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($countryCode) {
            $requirements = KycCountryRequirement::getForCountry($countryCode);

            return $requirements->map(function ($req) {
                return $this->formatTierRequirement($req);
            })->toArray();
        });
    }

    /**
     * Get specific tier requirements for a country
     */
    public function getTierRequirements(string $countryCode, int $tierLevel): ?array
    {
        $requirement = KycCountryRequirement::getForCountryAndTier($countryCode, $tierLevel);

        if (!$requirement) {
            return null;
        }

        return $this->formatTierRequirement($requirement);
    }

    /**
     * Get all document types for a country
     */
    public function getDocumentTypes(string $countryCode): array
    {
        $cacheKey = "kyc_document_types_{$countryCode}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($countryCode) {
            $documentTypes = KycDocumentType::getForCountry($countryCode);

            return $documentTypes->map(function ($doc) {
                return $this->formatDocumentType($doc);
            })->toArray();
        });
    }

    /**
     * Get document types by category
     */
    public function getDocumentTypesByCategory(string $countryCode, string $category): array
    {
        $documentTypes = KycDocumentType::getByCategory($countryCode, $category);

        return $documentTypes->map(function ($doc) {
            return $this->formatDocumentType($doc);
        })->toArray();
    }

    /**
     * Get specific document type
     */
    public function getDocumentType(string $countryCode, string $documentKey): ?array
    {
        $docType = KycDocumentType::getByKey($countryCode, $documentKey);

        if (!$docType) {
            return null;
        }

        return $this->formatDocumentType($docType);
    }

    /**
     * Get all active countries with KYC configured
     */
    public function getActiveCountries(): array
    {
        return Cache::remember('kyc_active_countries', self::CACHE_TTL, function () {
            return KycCountryRequirement::getActiveCountries();
        });
    }

    /**
     * Get complete KYC configuration for a country (tiers + documents)
     */
    public function getFullCountryConfig(string $countryCode): array
    {
        return [
            'country_code' => $countryCode,
            'tiers' => $this->getCountryRequirements($countryCode),
            'document_types' => $this->getDocumentTypes($countryCode),
        ];
    }

    /**
     * Validate document value against country-specific rules
     */
    public function validateDocumentValue(string $countryCode, string $documentKey, string $value): array
    {
        $docType = KycDocumentType::getByKey($countryCode, $documentKey);

        if (!$docType) {
            return [
                'valid' => false,
                'errors' => ["Document type '{$documentKey}' not found for country '{$countryCode}'"],
            ];
        }

        return $docType->validateValue($value);
    }

    /**
     * Check if country has KYC configured
     */
    public function isCountrySupported(string $countryCode): bool
    {
        return in_array($countryCode, $this->getActiveCountries());
    }

    /**
     * Get required documents for upgrading to a tier
     */
    public function getUpgradeRequirements(string $countryCode, int $currentTier, int $targetTier): array
    {
        $currentReq = $this->getTierRequirements($countryCode, $currentTier);
        $targetReq = $this->getTierRequirements($countryCode, $targetTier);

        if (!$targetReq) {
            return ['error' => 'Target tier not found'];
        }

        $currentDocs = $currentReq ? $currentReq['required_documents'] : [];
        $targetDocs = $targetReq['required_documents'];

        // Find documents needed for upgrade (in target but not in current)
        $additionalDocs = array_diff($targetDocs, $currentDocs);

        // Get document type details for each additional document
        $additionalDocDetails = [];
        foreach ($additionalDocs as $docKey) {
            $docType = $this->getDocumentType($countryCode, $docKey);
            if ($docType) {
                $additionalDocDetails[] = $docType;
            }
        }

        return [
            'current_tier' => $currentTier,
            'target_tier' => $targetTier,
            'target_tier_name' => $targetReq['tier_name'],
            'additional_documents' => $additionalDocDetails,
            'target_limits' => [
                'daily' => $targetReq['daily_limit'],
                'monthly' => $targetReq['monthly_limit'],
                'single_transaction' => $targetReq['single_transaction_limit'],
                'currency' => $targetReq['limit_currency'],
            ],
            'tier_benefits' => $targetReq['tier_benefits'] ?? [],
        ];
    }

    /**
     * Clear cache for a country
     */
    public function clearCountryCache(string $countryCode): void
    {
        Cache::forget("kyc_requirements_{$countryCode}");
        Cache::forget("kyc_document_types_{$countryCode}");
        Cache::forget('kyc_active_countries');
    }

    /**
     * Format tier requirement for API response
     */
    protected function formatTierRequirement(KycCountryRequirement $req): array
    {
        return [
            'id' => $req->id,
            'country_code' => $req->country_code,
            'tier_level' => $req->tier_level,
            'tier_name' => $req->tier_name,
            'required_documents' => $req->required_documents,
            'optional_documents' => $req->optional_documents ?? [],
            'required_fields' => $req->required_fields,
            'daily_limit' => (float) $req->daily_limit,
            'monthly_limit' => (float) $req->monthly_limit,
            'single_transaction_limit' => (float) $req->single_transaction_limit,
            'limit_currency' => $req->limit_currency,
            'description' => $req->description,
            'tier_benefits' => $req->tier_benefits ?? [],
        ];
    }

    /**
     * Format document type for API response
     */
    protected function formatDocumentType(KycDocumentType $doc): array
    {
        return [
            'id' => $doc->id,
            'country_code' => $doc->country_code,
            'document_key' => $doc->document_key,
            'display_name' => $doc->display_name,
            'local_name' => $doc->local_name,
            'description' => $doc->description,
            'accepted_formats' => $doc->accepted_formats ?? ['PDF', 'JPG', 'PNG'],
            'validation_rules' => $doc->validation_rules,
            'example_value' => $doc->example_value,
            'requires_expiry' => $doc->requires_expiry,
            'requires_front_back' => $doc->requires_front_back,
            'requires_verification_api' => $doc->requires_verification_api,
            'verification_provider' => $doc->verification_provider,
            'category' => $doc->category,
            'display_order' => $doc->display_order,
        ];
    }
}
