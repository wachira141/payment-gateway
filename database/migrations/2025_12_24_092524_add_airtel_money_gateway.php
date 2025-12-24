<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\PaymentGateway;
use App\Models\DefaultGatewayPricing;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Payment Gateway ----
        $gateway = PaymentGateway::updateOrCreate(
            ['code' => 'airtel_money'],
            [
                'name' => 'Airtel Money',
                'type' => 'airtel_money',
                'description' => 'Airtel Money mobile money payments for Africa',
                'is_active' => true,
                'priority' => 20,
                'supported_countries' => ['UG', 'KE', 'TZ', 'RW', 'ZM', 'MW', 'NG', 'CD'],
                'supported_currencies' => ['UGX', 'KES', 'TZS', 'RWF', 'ZMW', 'MWK', 'NGN', 'CDF'],
                'configuration' => [
                    'supports_c2b' => true,
                    'supports_b2c' => true,
                    'supports_refunds' => false,
                    'webhook_enabled' => true,
                    'async_confirmation' => true,
                ],
                'supports_recurring' => false,
                'supports_refunds' => false,
            ]
        );

        // ---- Default Pricing ----
        if (Schema::hasTable('default_gateway_pricing')) {
            DefaultGatewayPricing::updateOrCreate(
                [
                    'gateway_code' => $gateway->code,
                    'payment_method_type' => 'mobile_money',
                    'currency' => 'UGX',
                    'tier' => 'standard',
                ],
                [
                    'processing_fee_rate' => 0.0250,
                    'processing_fee_fixed' => 0,
                    'application_fee_rate' => 0.0000,
                    'application_fee_fixed' => 0,
                    'min_fee' => 0,
                    'max_fee' => null,
                    'is_active' => true,
                    'metadata' => [
                        'min_amount' => 100,
                        'max_amount' => 500000,
                    ],
                ]
            );
        }
    }

    public function down(): void
    {
        DefaultGatewayPricing::where('gateway_code', 'airtel_money')->delete();
        PaymentGateway::where('code', 'airtel_money')->delete();
    }
};
