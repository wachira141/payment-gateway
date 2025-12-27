<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to add actual gateway cost columns to pricing tables.
 * 
 * These columns enable accurate profitability tracking by storing the real
 * cost charged by the gateway provider, separate from what we charge merchants.
 * 
 * This allows calculating:
 * - Processing margin = processing_fee_charged - actual_gateway_cost
 * - True platform revenue = application_fee + processing_margin
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add to gateway_pricing_configs table (merchant-specific pricing)
        Schema::table('gateway_pricing_configs', function (Blueprint $table) {
            $table->decimal('actual_gateway_cost_rate', 5, 4)->default(0)->after('processing_fee_fixed')
                ->comment('Actual percentage rate charged by gateway provider (e.g., 0.0150 = 1.5%)');
            $table->integer('actual_gateway_cost_fixed')->default(0)->after('actual_gateway_cost_rate')
                ->comment('Actual fixed fee charged by gateway provider (in cents)');
        });

        // Add to default_gateway_pricing table (platform-wide defaults)
        Schema::table('default_gateway_pricing', function (Blueprint $table) {
            $table->decimal('actual_gateway_cost_rate', 5, 4)->default(0)->after('processing_fee_fixed')
                ->comment('Actual percentage rate charged by gateway provider (e.g., 0.0150 = 1.5%)');
            $table->integer('actual_gateway_cost_fixed')->default(0)->after('actual_gateway_cost_rate')
                ->comment('Actual fixed fee charged by gateway provider (in cents)');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_pricing_configs', function (Blueprint $table) {
            $table->dropColumn([
                'actual_gateway_cost_rate',
                'actual_gateway_cost_fixed',
            ]);
        });

        Schema::table('default_gateway_pricing', function (Blueprint $table) {
            $table->dropColumn([
                'actual_gateway_cost_rate',
                'actual_gateway_cost_fixed',
            ]);
        });
    }
};
