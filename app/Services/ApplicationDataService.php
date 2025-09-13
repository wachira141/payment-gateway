<?php

namespace App\Services;
use Illuminate\Support\Facades\Cache;

//models
use App\Models\Country;
use App\Models\Language;

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
        return Cache::remember('countries_and_currencies', now()->addDay(), function () {
            return Country::all();
        });
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
     * Clear the cache for all application data.
     */
    public function clearCache()
    {
        $keys = [
            'frequencies',
            'hours',
            'countries_and_currencies',
            'languages',
            'system_languages'
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
