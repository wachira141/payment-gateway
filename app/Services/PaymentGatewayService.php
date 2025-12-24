<?php

namespace App\Services;

use App\Models\PaymentGateway;

class PaymentGatewayService
{
    /**
     * Get available payment gateways for a specific country
     */
    public function getAvailableGateways($countryCode, $currency = null)
    {
        return PaymentGateway::getAvailableForCountryAndCurrency($countryCode, $currency);
    }

    /**
     * Get the best payment gateway for a country (hybrid selection)
     */
    public function getBestGateway($countryCode, $currency = 'USD', $userPreference = null)
    {
        $availableGateways = $this->getAvailableGateways($countryCode, $currency);

        if ($availableGateways->isEmpty()) {
            return null;
        }

        // If user has a preference and it's available, use it
        if ($userPreference) {
            $preferredGateway = $availableGateways->where('code', $userPreference)->first();
            if ($preferredGateway) {
                return $preferredGateway;
            }
        }

        // Auto-select based on country and priority
        return $this->autoSelectGateway($countryCode, $availableGateways);
    }

    /**
     * Auto-select the best gateway based on country
     */
    private function autoSelectGateway($countryCode, $gateways)
    {
        // Priority rules for different regions
        $regionPriorities = [
            // East Africa - prioritize M-Pesa and Telebirr
            'KE' => ['mpesa', 'stripe', 'paypal'],
            'TZ' => ['mpesa', 'stripe', 'paypal'],
            'UG' => ['mpesa', 'stripe', 'paypal'],
            'RW' => ['mpesa', 'stripe', 'paypal'],
            'ET' => ['telebirr', 'stripe', 'paypal'],

            // Default for other countries
            'default' => ['stripe', 'paypal', 'mpesa', 'telebirr']
        ];

        $priorities = $regionPriorities[$countryCode] ?? $regionPriorities['default'];

        foreach ($priorities as $gatewayType) {
            $gateway = $gateways->where('type', $gatewayType)->first();
            if ($gateway) {
                return $gateway;
            }
        }

        // Fallback to first available gateway
        return $gateways->first();
    }

    /**
     * Get gateway by code
     */
    public function getGatewayByCode($code)
    {
        return PaymentGateway::getByCode($code);
    }

    /**
     * Check if a payment method is supported
     */
    public function isPaymentMethodSupported($countryCode, $gatewayType, $currency = 'USD')
    {
        return PaymentGateway::isPaymentMethodSupported($countryCode, $gatewayType, $currency);
    }

    /**
     * Get supported currencies for a country
     */
    public function getSupportedCurrencies($countryCode)
    {
        return PaymentGateway::getSupportedCurrenciesForCountry($countryCode);
    }

    /**
     * Detect user's country (can be enhanced with IP geolocation)
     */
    public function detectUserCountry($user = null, $ipAddress = null)
    {
        // Try to get from user profile first
        if ($user && isset($user->country)) {
            return $user->country;
        }

        // TODO: Implement IP-based geolocation
        // For now, return default
        return 'US';
    }

       /**
     * Get available gateways filtered by payment method types
     */
    // public function getAvailableGatewaysForPaymentMethods(
    //     string $countryCode, 
    //     string $currency = 'USD', 
    //     array $paymentMethodTypes = []
    // ) {
    //     if (empty($paymentMethodTypes)) {
    //         return $this->getAvailableGateways($countryCode, $currency);
    //     }

    //     $gatewayMapper = new \App\Services\PaymentMethodGatewayMapper();
    //     $filteredGateways = collect();

    //     foreach ($paymentMethodTypes as $paymentMethodType) {
    //         $gateway = $gatewayMapper->getGatewayForPaymentMethod(
    //             $paymentMethodType, 
    //             $currency, 
    //             $countryCode
    //         );
            
    //         if ($gateway && !$filteredGateways->contains('id', $gateway->id)) {
    //             $filteredGateways->push($gateway);
    //         }
    //     }

    //     // Sort by priority
    //     return $filteredGateways->sortByDesc('priority')->values();
    // }
    public function getAvailableGatewaysForPaymentMethods(
        string $countryCode, 
        string $currency = 'USD', 
        array $paymentMethodTypes = []
    ) {
        if (empty($paymentMethodTypes)) {
            return $this->getAvailableGateways($countryCode, $currency);
        }
    
        $gatewayMapper = new \App\Services\PaymentMethodGatewayMapper();
        $filteredGateways = collect();
    
        foreach ($paymentMethodTypes as $paymentMethodType) {
            $gateways = $gatewayMapper->getGatewaysForPaymentMethod(
                $paymentMethodType, 
                $currency, 
                $countryCode
            );
            
            // Handle both single gateway (for backward compatibility) 
            // and collection of gateways
            $gateways = is_iterable($gateways) ? $gateways : [$gateways];
            
            foreach ($gateways as $gateway) {
                if ($gateway && !$filteredGateways->contains('id', $gateway->id)) {
                    $filteredGateways->push($gateway);
                }
            }
        }
    
        // Sort by priority
        return $filteredGateways->sortByDesc('priority')->values();
    }


    /**
     * Calculate disbursement fee for Kenya
     */
    public function calculateGatewayDisbursementFee($amount, $method)
    {
        switch ($method) {
            case 'mpesa':
                // M-Pesa B2C fees are typically lower
                $feeRate = 0.01; // 1%
                $minFee = 5.00; // 5 KES minimum
                $maxFee = 50.00; // 50 KES maximum
                break;
            case 'bank':
                // Bank transfer fees
                $feeRate = 0.015; // 1.5%
                $minFee = 25.00; // 25 KES minimum
                $maxFee = 200.00; // 200 KES maximum
                break;
            default:
                $feeRate = 0.02; // 2% default
                $minFee = 10.00;
                $maxFee = 100.00;
        }

        $fee = $amount * $feeRate;
        return max($minFee, min($fee, $maxFee));
    }
}
