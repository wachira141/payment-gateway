<?php

namespace App\Services;

use App\Models\PaymentGateway;

/**
 * Service to map payment method types to appropriate payment gateways
 */
class PaymentMethodGatewayMapper
{
    /**
     * Map payment method types to gateway types based on various factors
     */
    public function getGatewayForPaymentMethod(
        string $paymentMethodType,
        string $currency = 'USD',
        string $countryCode = 'US',
        array $metadata = []
    ): ?PaymentGateway {
        
        // $gatewayType = $this->mapPaymentMethodToGatewayType($paymentMethodType, $countryCode);
        
        // if (!$gatewayType) {
        //     return null;
        // }

        // Get the best gateway for the type, country, and currency
        return PaymentGateway::active()
            ->where('type', $paymentMethodType)
            ->byCountry($countryCode)
            ->byCurrency($currency)
            ->orderBy('priority', 'desc')
            ->first();
    }

    /**
     * Get all available gateways for payment method types
     */
    public function getAvailableGateways(
        array $paymentMethodTypes,
        string $currency = 'USD',
        string $countryCode = 'US'
    ): array {
        $gateways = [];

        foreach ($paymentMethodTypes as $paymentMethodType) {
            $gateway = $this->getGatewayForPaymentMethod($paymentMethodType, $currency, $countryCode);
            if ($gateway) {
                $gateways[$paymentMethodType] = $gateway;
            }
        }

        return $gateways;
    }

    /**
     * Get gateway code for payment method
     */
    public function getGatewayCodeForPaymentMethod(
        string $paymentMethodType,
        string $currency = 'USD',
        string $countryCode = 'US'
    ): ?string {
        $gateway = $this->getGatewayForPaymentMethod($paymentMethodType, $currency, $countryCode);
        return $gateway ? $gateway->code : null;
    }

    /**
     * Determine if phone number suggests M-Pesa or Telebirr
     */
    public function detectMobileMoneyProvider(?string $phoneNumber, string $countryCode): string
    {
        if (!$phoneNumber) {
            return $this->getDefaultMobileMoneyProvider($countryCode);
        }

        // Clean phone number
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Kenya M-Pesa detection
        if ($countryCode === 'KE') {
            $mpesaPrefixes = ['254701', '254702', '254703', '254708', '254710', '254711', '254712', '254713', '254714', '254715'];
            foreach ($mpesaPrefixes as $prefix) {
                if (strpos($cleanPhone, $prefix) === 0) {
                    return 'mpesa';
                }
            }
        }

        // Ethiopia Telebirr detection
        if ($countryCode === 'ET') {
            $telebirrPrefixes = ['251911', '251912', '251913', '251914', '251915'];
            foreach ($telebirrPrefixes as $prefix) {
                if (strpos($cleanPhone, $prefix) === 0) {
                    return 'telebirr';
                }
            }
        }

        return $this->getDefaultMobileMoneyProvider($countryCode);
    }

    /**
     * Map payment method type to gateway type
     */
    private function mapPaymentMethodToGatewayType(string $paymentMethodType, string $countryCode): ?string
    {
        $mapping = [
            'card' => 'stripe',
            'mobile_money' => $this->getMobileMoneyGatewayType($countryCode),
            'bank' => $this->getBankTransferGatewayType($countryCode),
            'ussd' => 'ussd',
        ];

        return $mapping[$paymentMethodType] ?? null;
    }

    /**
     * Get mobile money gateway type based on country
     */
    private function getMobileMoneyGatewayType(string $countryCode): string
    {
        $countryMapping = [
            'KE' => 'mpesa',    // Kenya
            'ET' => 'telebirr', // Ethiopia
            'UG' => 'mpesa',    // Uganda (M-Pesa available)
            'TZ' => 'mpesa',    // Tanzania (M-Pesa available)
        ];

        return $countryMapping[$countryCode] ?? 'mpesa'; // Default to M-Pesa
    }

    /**
     * Get bank transfer gateway type based on country
     */
    private function getBankTransferGatewayType(string $countryCode): string
    {
        $countryMapping = [
            'KE' => 'kenya_bank_transfer',
            'ET' => 'ethiopia_bank_transfer',
            'US' => 'ach',
            'GB' => 'bacs',
        ];

        return $countryMapping[$countryCode] ?? 'bank_transfer';
    }

    /**
     * Get default mobile money provider for country
     */
    private function getDefaultMobileMoneyProvider(string $countryCode): string
    {
        return $this->getMobileMoneyGatewayType($countryCode);
    }

    /**
     * Check if payment method is supported in country
     */
    public function isPaymentMethodSupported(
        string $paymentMethodType,
        string $countryCode,
        string $currency = 'USD'
    ): bool {
        $gatewayType = $this->mapPaymentMethodToGatewayType($paymentMethodType, $countryCode);
        
        if (!$gatewayType) {
            return false;
        }

        return PaymentGateway::isPaymentMethodSupported($countryCode, $gatewayType, $currency);
    }

     /**
     * Get supported payment methods for country and currency
     */
    public function getSupportedPaymentMethods(string $countryCode, string $currency = 'USD'): array
    {
        $supportedMethods = [];
        $paymentMethods = ['card', 'mobile_money', 'bank'];

        foreach ($paymentMethods as $method) {
            if ($this->isPaymentMethodSupported($method, $countryCode, $currency)) {
                $supportedMethods[] = $method;
            }
        }

        return $supportedMethods;
    }
}