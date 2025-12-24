<?php

namespace App\Services;
use Illuminate\Support\Facades\Cache;

//models
use App\Models\Country;
use App\Models\Language;
use App\Models\SupportedBank;
use App\Models\SupportedPayoutMethod;

class ApplicationDataService
{

     /**
     * Convert entity type to class name
     * 
     * @param string $type
     * @return string|null
     */
    public function getClassFromType($type)
    {
        $typeToClass = [
            'user' => \App\Models\User::class,
            'user_profile' => \App\Models\User::class,
            'payment_intent' => \App\Models\PaymentIntent::class,

            // Add more entity types as needed
        ];
        
        return $typeToClass[$type] ?? \App\Models\User::class;
    }
    

    /**
     * Get all hours and cache them.
     */
    public function getHours()
    {
        return Cache::remember('hours', now()->addDay(), function () {
            return range(0, 23);
        });
    }

    /**
     * Get a  list of countries and their currencies
     * 
     */
    public function getCountriesAndCurrencies()
    {
        return Country::all();
        // return Cache::remember('countries_and_currencies', now()->addDay(), function () {
        // });
    }

    /**
     * Get a list of all languages
     */
    public function getLanguages()
    {
        return Cache::remember('languages', now()->addDay(), function () {
            return Language::getAllActive();
        });
    }

    /**
     * system languages english, france, spanish
     * @return array
     * 
     */
    public function getSystemLanguages()
    {
        return Cache::remember('system_languages', now()->addDay(), function () {
            return [
                'en' => 'English',
                'fr' => 'Français',
                'es' => 'Español',
                // Add more languages as needed
            ];
        });
    }

     /**
     * Get supported banks for a specific country
     */
    public function getSupportedBanks(string | null $countryCode = null)
    {
        $cacheKey = $countryCode ? "supported_banks_{$countryCode}" : 'supported_banks_all';
        
        return Cache::remember($cacheKey, now()->addDay(), function () use ($countryCode) {
            if ($countryCode) {
                return SupportedBank::getForCountry($countryCode);
            }
            return SupportedBank::active()->orderBy('bank_name')->get()->toArray();
        });
    }

    /**
     * Get supported payout methods for a specific country and currency
     */
    public function getSupportedPayoutMethods(string | null $countryCode = null, string | null $currency = null)
    {
        $cacheKey = "supported_payout_methods";
        if ($countryCode) $cacheKey .= "_{$countryCode}";
        if ($currency) $cacheKey .= "_{$currency}";
        
        return Cache::remember($cacheKey, now()->addDay(), function () use ($countryCode, $currency) {
            if ($countryCode && $currency) {
                return SupportedPayoutMethod::getForCountryAndCurrency($countryCode, $currency);
            }
            return SupportedPayoutMethod::active()->orderBy('method_name')->get()->toArray();
        });
    }

    /**
     * Get application status constants
     */
    public function getApplicationStatuses()
    {
        return Cache::remember('application_statuses', now()->addDay(), function () {
            return [
                'payment_intent_statuses' => [
                    'requires_payment_method' => 'Requires Payment Method',
                    'requires_confirmation' => 'Requires Confirmation',
                    'requires_action' => 'Requires Action',
                    'processing' => 'Processing',
                    'requires_capture' => 'Requires Capture',
                    'canceled' => 'Canceled',
                    'succeeded' => 'Succeeded'
                ],
                'payout_statuses' => [
                    'pending' => 'Pending',
                    'in_transit' => 'In Transit',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'cancelled' => 'Cancelled'
                ],
                'refund_statuses' => [
                    'pending' => 'Pending',
                    'succeeded' => 'Succeeded',
                    'failed' => 'Failed',
                    'canceled' => 'Canceled'
                ],
                'settlement_statuses' => [
                    'pending' => 'Pending',
                    'in_progress' => 'In Progress',
                    'completed' => 'Completed',
                    'failed' => 'Failed'
                ],
                'webhook_statuses' => [
                    'pending' => 'Pending',
                    'processing' => 'Processing',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'retry' => 'Retry'
                ],
                'beneficiary_statuses' => [
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                    'suspended' => 'Suspended',
                    'verified' => 'Verified',
                    'pending_verification' => 'Pending Verification'
                ]
            ];
        });
    }

    /**
     * Clear the cache for all application data.
     */
    public function clearCache()
    {
        $keys = [
            'frequencies',
            'hours',
            'countries_and_currencies',
            'languages',
            'system_languages',
            'application_statuses'
        ];

        // Clear country-specific caches
        $countries = ['KE', 'UG', 'NG', 'GH', 'TZ']; // Add more as needed
        foreach ($countries as $country) {
            $keys[] = "supported_banks_{$country}";
            $keys[] = "supported_payout_methods_{$country}";
            foreach (['KES', 'UGX', 'NGN', 'GHS', 'TZS'] as $currency) {
                $keys[] = "supported_payout_methods_{$country}_{$currency}";
            }
        }

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
