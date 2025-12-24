<?php

namespace App\Models;

use App\Models\BaseModel;

class KycDocumentType extends BaseModel
{
    protected $table = 'kyc_document_types';

    protected $fillable = [
        'country_code',
        'document_key',
        'display_name',
        'local_name',
        'description',
        'accepted_formats',
        'validation_rules',
        'example_value',
        'requires_expiry',
        'requires_front_back',
        'requires_verification_api',
        'verification_provider',
        'display_order',
        'category',
        'is_active',
    ];

    protected $casts = [
        'accepted_formats' => 'array',
        'validation_rules' => 'array',
        'requires_expiry' => 'boolean',
        'requires_front_back' => 'boolean',
        'requires_verification_api' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get document types for a country
     */
    public static function getForCountry(string $countryCode): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('country_code', $countryCode)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Get specific document type
     */
    public static function getByKey(string $countryCode, string $documentKey): ?self
    {
        return static::where('country_code', $countryCode)
            ->where('document_key', $documentKey)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get document types by category
     */
    public static function getByCategory(string $countryCode, string $category): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('country_code', $countryCode)
            ->where('category', $category)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Validate a document value against rules
     */
    public function validateValue(string $value): array
    {
        $errors = [];
        $rules = $this->validation_rules ?? [];

        // Check minimum length
        if (isset($rules['min_length']) && strlen($value) < $rules['min_length']) {
            $errors[] = "Value must be at least {$rules['min_length']} characters";
        }

        // Check maximum length
        if (isset($rules['max_length']) && strlen($value) > $rules['max_length']) {
            $errors[] = "Value must be at most {$rules['max_length']} characters";
        }

        // Check pattern (regex)
        if (isset($rules['pattern'])) {
            $pattern = '/' . $rules['pattern'] . '/';
            if (!preg_match($pattern, $value)) {
                $errors[] = $rules['pattern_message'] ?? "Value does not match the required format";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get accepted MIME types
     */
    public function getAcceptedMimeTypes(): array
    {
        $formats = $this->accepted_formats ?? ['PDF', 'JPG', 'PNG'];
        $mimeMap = [
            'PDF' => 'application/pdf',
            'JPG' => 'image/jpeg',
            'JPEG' => 'image/jpeg',
            'PNG' => 'image/png',
            'GIF' => 'image/gif',
            'WEBP' => 'image/webp',
        ];

        return array_values(array_filter(
            array_map(fn($f) => $mimeMap[strtoupper($f)] ?? null, $formats)
        ));
    }

    /**
     * Check if file type is accepted
     */
    public function isAcceptedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, $this->getAcceptedMimeTypes());
    }
}
