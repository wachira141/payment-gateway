<?php
namespace App\Models;

use App\Models\BaseModel;

class PayoutAutomationRule extends BaseModel
{
    protected $fillable = [
        'country_code',
        'risk_level',
        'hold_period_hours',
        'min_threshold_mpesa',
        'min_threshold_bank',
        'max_mpesa_amount',
        'auto_payout_threshold',
        'instant_payout_enabled',
        'is_active',
        'is_default',
        'configuration',
    ];

    protected $casts = [
        'min_threshold_mpesa' => 'decimal:2',
        'min_threshold_bank' => 'decimal:2',
        'max_mpesa_amount' => 'decimal:2',
        'auto_payout_threshold' => 'decimal:2',
        'instant_payout_enabled' => 'boolean',
        'is_active' => 'boolean',
        'configuration' => 'array',
        'is_default' => 'boolean',
    ];

    /**
     * Scope for active rules
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for country
     */
    public function scopeForCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope for risk level
     */
    public function scopeForRiskLevel($query, $riskLevel)
    {
        return $query->where('risk_level', $riskLevel);
    }

    /**
     * Get rule for country and risk level
     */
    public static function getRule($countryCode, $riskLevel)
    {
        return static::forCountry($countryCode)
                    ->forRiskLevel($riskLevel)
                    ->active()
                    ->first();
    }

    /**
     * Get default rule for country and risk level
     */
    public static function getDefaultRule($countryCode, $riskLevel)
    {
        return static::forCountry($countryCode)
                    ->forRiskLevel($riskLevel)
                    ->default()
                    ->active()
                    ->first();
    }

    /**
     * Get all default rules for a country
     */
    public static function getCountryDefaults($countryCode)
    {
        return static::forCountry($countryCode)
                    ->default()
                    ->active()
                    ->get();
    }

    /**
     * Get default Kenya rules
     */
    public static function getKenyaDefaults()
    {
        return [
            [
                'country_code' => 'KE',
                'risk_level' => 'low',
                'hold_period_hours' => 24,
                'min_threshold_mpesa' => 500.00,
                'min_threshold_bank' => 2000.00,
                'max_mpesa_amount' => 70000.00,
                'auto_payout_threshold' => 5000.00,
                'instant_payout_enabled' => true,
                'is_active' => true,
            ],
            [
                'country_code' => 'KE',
                'risk_level' => 'medium',
                'hold_period_hours' => 48,
                'min_threshold_mpesa' => 1000.00,
                'min_threshold_bank' => 5000.00,
                'max_mpesa_amount' => 50000.00,
                'auto_payout_threshold' => 10000.00,
                'instant_payout_enabled' => false,
                'is_active' => true,
            ],
            [
                'country_code' => 'KE',
                'risk_level' => 'high',
                'hold_period_hours' => 120,
                'min_threshold_mpesa' => 2000.00,
                'min_threshold_bank' => 10000.00,
                'max_mpesa_amount' => 30000.00,
                'auto_payout_threshold' => 20000.00,
                'instant_payout_enabled' => false,
                'is_active' => true,
            ],
        ];
    }
}