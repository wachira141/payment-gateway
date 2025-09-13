<?php

namespace App\Models;

class PayoutRoutingRule extends BaseModel
{
    protected $table = 'payout_routing_rules';

    protected $fillable = [
        'country_code',
        'currency',
        'method',
        'min_amount',
        'max_amount',
        'fee_percentage',
        'fee_fixed',
        'is_active',
        'priority',
        'configuration',
    ];

    protected $casts = [
        'min_amount'     => 'float',
        'max_amount'     => 'float',
        'fee_percentage' => 'float',
        'fee_fixed'      => 'float',
        'is_active'      => 'boolean',
        'priority'       => 'integer',
        'configuration'  => 'array',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry($query, string $cc)
    {
        return $query->where('country_code', strtoupper($cc));
    }

    public function scopeForCurrency($query, string $cur)
    {
        return $query->where('currency', strtoupper($cur));
    }

    public function scopeForMethod($query, string $method)
    {
        return $query->where('method', strtolower($method));
    }

    public function scopeForAmount($query, float $amount)
    {
        return $query->where('min_amount', '<=', $amount)
                     ->where('max_amount', '>=', $amount);
    }

    public static function findBestRule(string $country, string $currency, string $method, float $amount): ?self
    {
        return static::query()
            ->active()
            ->forCountry($country)
            ->forCurrency($currency)
            ->forMethod($method)
            ->forAmount($amount)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->first();
    }
}
