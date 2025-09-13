<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends BaseModel
{
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'type',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];

    /**
     * Get all service languages that use this language.
     */
    public function serviceLanguages(): HasMany
    {
        return $this->hasMany(ServiceLanguage::class, 'language', 'code');
    }

    /**
     * Get active languages by type.
     */
    public static function getByType(string $type): array
    {
        return self::where('type', $type)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($language) {
                return [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name
                ];
            })
            ->toArray();
    }

    /**
     * Get all active languages grouped by type.
     */
    public static function getAllGroupedByType(): array
    {
        return [
            'international' => self::getByType('international'),
            'kenyan_local' => self::getByType('kenyan_local')
        ];
    }

    /**
     * Get all active languages.
     */
    public static function getAllActive(): array
    {
        return self::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($language) {
                return [
                    'id' => $language->id,
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                    'type' => $language->type
                ];
            })
            ->toArray();
    }

    /**
     * Validate if a language code exists and is active.
     */
    public static function isValidLanguageCode(string $code): bool
    {
        return self::where('code', $code)
            ->where('is_active', true)
            ->exists();
    }
}
