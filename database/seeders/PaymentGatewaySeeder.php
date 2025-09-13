<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentGateway;

class PaymentGatewaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $gateways = [
            [
                'name' => 'Stripe',
                'code' => 'stripe',
                'type' => 'stripe',
                'supported_countries' => [
                    'US', 'CA', 'GB', 'AU', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 
                    'AT', 'CH', 'SE', 'NO', 'DK', 'FI', 'IE', 'PT', 'LU', 'CZ', 
                    'SK', 'SI', 'EE', 'LV', 'LT', 'PL', 'HU', 'RO', 'BG', 'HR', 
                    'CY', 'MT', 'GR', 'IN', 'JP', 'SG', 'MY', 'TH', 'PH', 'HK',
                    'KE', 'TZ', 'UG', 'RW', 'CD', 'GH', 'ZA', 'MZ', 'EG', 'ET'
                ],
                'supported_currencies' => [
                    'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'SEK', 'NOK', 'DKK', 
                    'PLN', 'CZK', 'HUF', 'INR', 'JPY', 'SGD', 'MYR', 'THB', 'PHP', 'HKD'
                ],
                'icon' => '💳',
                'description' => 'Credit/Debit Cards, Apple Pay, Google Pay',
                'is_active' => true,
                'supports_recurring' => true,
                'supports_refunds' => true,
                'priority' => 100,
                'configuration' => [
                    'supports_payment_intents' => true,
                    'supports_setup_intents' => true,
                    'supports_webhooks' => true,
                ]
            ],
            [
                'name' => 'M-Pesa',
                'code' => 'mpesa',
                'type' => 'mpesa',
                'supported_countries' => [
                    'KE', 'TZ', 'UG', 'RW', 'CD', 'GH', 'ZA', 'MZ', 'EG', 'ET'
                ],
                'supported_currencies' => [
                    'KES', 'TZS', 'UGX', 'RWF', 'CDF', 'GHS', 'ZAR', 'MZN', 'EGP', 'ETB'
                ],
                'icon' => '📱',
                'description' => 'Mobile Money Payment',
                'is_active' => true,
                'supports_recurring' => false,
                'supports_refunds' => false,
                'priority' => 90,
                'configuration' => [
                    'requires_phone_number' => true,
                    'supports_stk_push' => true,
                    'supports_callbacks' => true,
                ]
            ],
            [
                'name' => 'Telebirr',
                'code' => 'telebirr',
                'type' => 'telebirr',
                'supported_countries' => ['ET'],
                'supported_currencies' => ['ETB'],
                'icon' => '🇪🇹',
                'description' => 'Ethiopia Mobile Payment',
                'is_active' => true,
                'supports_recurring' => false,
                'supports_refunds' => false,
                'priority' => 95,
                'configuration' => [
                    'requires_phone_number' => true,
                    'supports_web_redirect' => true,
                    'supports_notifications' => true,
                ]
            ]
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::updateOrCreate(
                ['code' => $gateway['code']],
                $gateway
            );
        }
    }
}
