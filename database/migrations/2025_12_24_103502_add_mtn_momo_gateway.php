<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Models\PaymentGateway;
use App\Models\DefaultGatewayPricing;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Payment Gateway ----
        $gateway = PaymentGateway::updateOrCreate(
            ['code' => 'mtn_momo'],
            [
                'name' => 'MTN Mobile Money',
                'type' => 'mobile_money',
                'description' => 'MTN Mobile Money for collections and disbursements across Africa',
                'icon' => '💳', // optional icon
                'is_active' => true,
                'is_enabled' => true,
                'priority' => 2,
                'supported_countries' => ['UG', 'GH', 'CI', 'CM', 'BJ', 'CG', 'ZA', 'RW', 'ZM'],
                'supported_currencies' => ['UGX', 'GHS', 'XOF', 'XAF', 'ZAR', 'RWF', 'ZMW'],
                'supports_refunds' => false,
                'supports_recurring' => false,
                'configuration' => [
                    'supports_c2b' => true,
                    'supports_b2c' => true,
                    'supports_refunds' => false,
                    'webhook_enabled' => true,
                    'async_confirmation' => true,
                ],
            ]
        );

        // ---- Default Pricing ----
        if (Schema::hasTable('default_gateway_pricing')) {
            foreach ($gateway->supported_currencies as $currency) {
                DefaultGatewayPricing::updateOrCreate(
                    [
                        'payment_method_type' => 'mobile_money',
                        'currency' => $currency,
                        'tier' => 'standard',
                        'gateway_code' => $gateway->code,
                    ],
                    [
                        'processing_fee_rate' => 0.02,
                        'processing_fee_fixed' => 0,
                        'application_fee_rate' => 0.0,
                        'application_fee_fixed' => 0,
                        'min_fee' => 0,
                        'max_fee' => null,
                        'is_active' => true,
                        'metadata' => [
                            'min_amount' => 100,
                            'max_amount' => 5000000,
                            'collection_rate' => 0.02,
                            'disbursement_rate' => 0.01,
                            'disbursement_fixed_fee' => 50,
                        ],
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('default_gateway_pricing')) {
            DefaultGatewayPricing::where('gateway_code', 'mtn_momo')->delete();
        }

        PaymentGateway::where('code', 'mtn_momo')->delete();
    }
};
