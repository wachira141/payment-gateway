<?php

namespace App\Helpers;

use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

/**
 * CurrencyHelper - Handles multi-currency amount conversions
 * 
 * All amounts in the database are stored in MINOR units (cents/paise/etc.)
 * This helper provides currency-aware conversion functions.
 * 
 * Decimals are loaded from the currencies database table with caching.
 */
class CurrencyHelper
{
    /**
     * Local cache for decimals (within request lifecycle)
     */
    protected static ?array $decimalsCache = null;

    /**
     * Fallback decimals for when database is unavailable
     */
    protected static array $fallbackDecimals = [
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'UGX' => 0, 'RWF' => 0,
        'BHD' => 3, 'JOD' => 3, 'KWD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /**
     * Get the number of decimal places for a currency
     * Fetches from database with caching, falls back to defaults
     */
    public static function getDecimals(string $currency): int
    {
        $code = strtoupper($currency);
        $decimals = self::getDecimalsMap();
        
        return $decimals[$code] ?? self::$fallbackDecimals[$code] ?? 2;
    }

    /**
     * Get all currency decimals as a map
     */
    protected static function getDecimalsMap(): array
    {
        if (self::$decimalsCache !== null) {
            return self::$decimalsCache;
        }

        try {
            self::$decimalsCache = Currency::getDecimalsMap();
        } catch (\Exception $e) {
            // Database unavailable, use fallback
            self::$decimalsCache = self::$fallbackDecimals;
        }

        return self::$decimalsCache;
    }

    /**
     * Clear the local cache (useful for testing or after updates)
     */
    public static function clearCache(): void
    {
        self::$decimalsCache = null;
        Currency::clearCache();
    }

    /**
     * Check if a currency uses minor units (has decimal places)
     */
    public static function hasMinorUnits(string $currency): bool
    {
        return self::getDecimals($currency) > 0;
    }

    /**
     * Convert from minor units (cents) to major units (dollars)
     * 
     * @param int|float $amount Amount in minor units
     * @param string $currency Currency code
     * @return float Amount in major units
     */
    public static function fromMinorUnits($amount, string $currency): float
    {
        $decimals = self::getDecimals($currency);
        if ($decimals === 0) {
            return (float) $amount; // No conversion needed for zero-decimal currencies
        }
        return $amount / pow(10, $decimals);
    }

    /**
     * Convert from major units (dollars) to minor units (cents)
     * 
     * @param float $amount Amount in major units
     * @param string $currency Currency code
     * @return int Amount in minor units
     */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        $decimals = self::getDecimals($currency);
        if ($decimals === 0) {
            return (int) round($amount); // No conversion needed for zero-decimal currencies
        }
        return (int) round($amount * pow(10, $decimals));
    }

    /**
     * Format amount for display (from minor units)
     * 
     * @param int|float $amount Amount in minor units
     * @param string $currency Currency code
     * @param string $locale Locale for formatting
     * @return string Formatted currency string
     */
    public static function format($amount, string $currency, string $locale = 'en_US'): string
    {
        $majorAmount = self::fromMinorUnits($amount, $currency);
        $decimals = self::getDecimals($currency);
        
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
        
        $result = $formatter->formatCurrency($majorAmount, strtoupper($currency));
        
        // Fallback if NumberFormatter fails
        if ($result === false) {
            return strtoupper($currency) . ' ' . number_format($majorAmount, $decimals);
        }
        
        return $result;
    }

    /**
     * Parse a display amount string to minor units
     * 
     * @param string|float $displayAmount Amount as displayed (major units)
     * @param string $currency Currency code
     * @return int Amount in minor units
     */
    public static function parseToMinorUnits($displayAmount, string $currency): int
    {
        $amount = is_string($displayAmount) ? (float) preg_replace('/[^0-9.-]/', '', $displayAmount) : $displayAmount;
        return self::toMinorUnits($amount, $currency);
    }

    /**
     * Validate that an amount is valid for a currency
     * 
     * @param int|float $amount Amount in minor units
     * @param string $currency Currency code
     * @return bool Whether the amount is valid
     */
    public static function isValidAmount($amount, string $currency): bool
    {
        if (!is_numeric($amount)) {
            return false;
        }
        
        // Amount must be non-negative
        if ($amount < 0) {
            return false;
        }
        
        // For zero-decimal currencies, amount should be a whole number
        if (self::getDecimals($currency) === 0 && floor($amount) !== (float) $amount) {
            return false;
        }
        
        return true;
    }

    /**
     * Get multiplier for converting to minor units
     */
    public static function getMultiplier(string $currency): int
    {
        return (int) pow(10, self::getDecimals($currency));
    }

    /**
     * Compare two amounts in minor units with proper precision
     */
    public static function compare($amount1, $amount2, string $currency): int
    {
        $diff = (int) $amount1 - (int) $amount2;
        if ($diff > 0) return 1;
        if ($diff < 0) return -1;
        return 0;
    }
}
