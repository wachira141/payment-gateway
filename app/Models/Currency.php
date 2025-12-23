<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Currency extends BaseModel
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimals',
        'is_active',
    ];

    protected $casts = [
        'decimals' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Scope to get only active currencies
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Relationship to countries using this currency
     */
    public function countries(): HasMany
    {
        return $this->hasMany(Country::class, 'currency_code', 'code');
    }

    /**
     * Get decimals for a currency code from cache or database
     */
    public static function getDecimals(string $code): int
    {
        $currencies = self::getCachedCurrencies();
        return $currencies[strtoupper($code)]['decimals'] ?? 2;
    }

    /**
     * Get currency info by code
     */
    public static function getByCode(string $code): ?array
    {
        $currencies = self::getCachedCurrencies();
        return $currencies[strtoupper($code)] ?? null;
    }

    /**
     * Get all currencies as cached array
     */
    public static function getCachedCurrencies(): array
    {
        return Cache::remember('currencies_all', 3600, function () {
            return self::active()
                ->get()
                ->keyBy('code')
                ->map(fn($currency) => [
                    'code' => $currency->code,
                    'name' => $currency->name,
                    'symbol' => $currency->symbol,
                    'decimals' => $currency->decimals,
                ])
                ->toArray();
        });
    }

    /**
     * Get decimals mapping only (for CurrencyHelper)
     */
    public static function getDecimalsMap(): array
    {
        return Cache::remember('currencies_decimals', 3600, function () {
            return self::pluck('decimals', 'code')->toArray();
        });
    }

    /**
     * Clear all currency caches
     */
    public static function clearCache(): void
    {
        Cache::forget('currencies_all');
        Cache::forget('currencies_decimals');
    }

    /**
     * Boot method to clear cache on changes
     */
    protected static function booted(): void
    {
        static::saved(fn() => self::clearCache());
        static::deleted(fn() => self::clearCache());
    }
}
