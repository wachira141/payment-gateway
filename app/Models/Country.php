<?php

namespace App\Models;

use App\Models\BaseModel;

class Country extends BaseModel
{
    protected $fillable = [
        'code',
        'name',
        'iso3',
        'numeric_code',
        'phone_code',
        'currency_code',
        'currency_name',
        'currency_symbol',
        'region',
        'subregion',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_active' => 'boolean',
    ];


    /**
     * Relationships to payment intents
     */
    public function paymentIntents()
    {
        return $this->hasMany(PaymentIntent::class, 'country_code', 'code');
    }

    /**
     * Scope to get only active countries
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by region
     */
    public function scopeByRegion($query, $region)
    {
        return $query->where('region', $region);
    }

    /**
     * Get countries by currency
     */
    public function scopeByCurrency($query, $currencyCode)
    {
        return $query->where('currency_code', $currencyCode);
    }

    /**
     * Search countries by name or code
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('code', 'like', "%{$search}%")
              ->orWhere('iso3', 'like', "%{$search}%");
        });
    }
}