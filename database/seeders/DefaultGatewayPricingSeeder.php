<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DefaultGatewayPricing;

class DefaultGatewayPricingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pricingConfigs = [
            // M-Pesa Kenya
            [
                'gateway_code' => 'mpesa',
                'payment_method_type' => 'mobile_money',
                'currency' => 'KES',
                'processing_fee_rate' => 0.015, // 1.5%
                'processing_fee_fixed' => 0,
                'application_fee_rate' => 0.005, // 0.5% platform fee
                'application_fee_fixed' => 0,
                'min_fee' => 500, // 5 KES in cents
                'max_fee' => 15000, // 150 KES in cents
                'tier' => 'standard',
                'is_active' => true,
            ],
            
            // Stripe Cards - USD
            [
                'gateway_code' => 'stripe',
                'payment_method_type' => 'card',
                'currency' => 'USD',
                'processing_fee_rate' => 0.029, // 2.9%
                'processing_fee_fixed' => 30, // $0.30 in cents
                'application_fee_rate' => 0.005, // 0.5% platform fee
                'application_fee_fixed' => 0,
                'min_fee' => 50, // $0.50 in cents
                'max_fee' => null,
                'tier' => 'standard',
                'is_active' => true,
            ],
            
            // Stripe Cards - KES
            [
                'gateway_code' => 'stripe',
                'payment_method_type' => 'card',
                'currency' => 'KES',
                'processing_fee_rate' => 0.039, // 3.9% for international cards in Kenya
                'processing_fee_fixed' => 1500, // 15 KES in cents
                'application_fee_rate' => 0.005, // 0.5% platform fee
                'application_fee_fixed' => 0,
                'min_fee' => 2000, // 20 KES in cents
                'max_fee' => null,
                'tier' => 'standard',
                'is_active' => true,
            ],
            
            // Telebirr Ethiopia
            [
                'gateway_code' => 'telebirr',
                'payment_method_type' => 'mobile_money',
                'currency' => 'ETB',
                'processing_fee_rate' => 0.02, // 2.0%
                'processing_fee_fixed' => 0,
                'application_fee_rate' => 0.005, // 0.5% platform fee
                'application_fee_fixed' => 0,
                'min_fee' => 200, // 2 ETB in cents
                'max_fee' => 10000, // 100 ETB in cents
                'tier' => 'standard',
                'is_active' => true,
            ],
            
            // Bank Transfer - KES
            [
                'gateway_code' => 'banking',
                'payment_method_type' => 'bank_transfer',
                'currency' => 'KES',
                'processing_fee_rate' => 0.01, // 1.0%
                'processing_fee_fixed' => 10000, // 100 KES in cents
                'application_fee_rate' => 0.005, // 0.5% platform fee
                'application_fee_fixed' => 0,
                'min_fee' => 20000, // 200 KES in cents
                'max_fee' => 50000, // 500 KES in cents
                'tier' => 'standard',
                'is_active' => true,
            ],
            
            // Premium tier pricing (lower rates for high-volume merchants)
            [
                'gateway_code' => 'mpesa',
                'payment_method_type' => 'mobile_money',
                'currency' => 'KES',
                'processing_fee_rate' => 0.012, // 1.2% (reduced)
                'processing_fee_fixed' => 0,
                'application_fee_rate' => 0.003, // 0.3% platform fee (reduced)
                'application_fee_fixed' => 0,
                'min_fee' => 400, // 4 KES in cents
                'max_fee' => 12000, // 120 KES in cents
                'tier' => 'premium',
                'is_active' => true,
            ],
            
            [
                'gateway_code' => 'stripe',
                'payment_method_type' => 'card',
                'currency' => 'USD',
                'processing_fee_rate' => 0.027, // 2.7% (reduced)
                'processing_fee_fixed' => 30, // $0.30 in cents
                'application_fee_rate' => 0.003, // 0.3% platform fee (reduced)
                'application_fee_fixed' => 0,
                'min_fee' => 50, // $0.50 in cents
                'max_fee' => null,
                'tier' => 'premium',
                'is_active' => true,
            ],
        ];

        foreach ($pricingConfigs as $config) {
            DefaultGatewayPricing::updateOrCreate(
                [
                    'gateway_code' => $config['gateway_code'],
                    'payment_method_type' => $config['payment_method_type'],
                    'currency' => $config['currency'],
                    'tier' => $config['tier'],
                ],
                $config
            );
        }
    }
}