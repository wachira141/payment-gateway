<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to add profitability tracking columns to platform_earnings table.
 * 
 * These columns enable accurate tracking of:
 * - processing_fee_charged: The gateway fee charged to merchant (may include margin)
 * - processing_margin: Hidden profit = charged fee - actual gateway cost
 * - total_platform_revenue: True total profit = application_fee + processing_margin
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_earnings', function (Blueprint $table) {
            // Processing fee charged to merchant (may include margin above actual gateway cost)
            $table->bigInteger('processing_fee_charged')->default(0)->after('net_amount')
                ->comment('Gateway processing fee charged to merchant (in cents)');
            
            // Processing margin = processing_fee_charged - actual_gateway_cost
            $table->bigInteger('processing_margin')->default(0)->after('processing_fee_charged')
                ->comment('Hidden profit in processing fee: charged minus actual cost (in cents)');
            
            // Total platform revenue = gross_amount (application fee) + processing_margin
            $table->bigInteger('total_platform_revenue')->default(0)->after('processing_margin')
                ->comment('True total platform profit: application_fee + processing_margin (in cents)');
        });
    }

    public function down(): void
    {
        Schema::table('platform_earnings', function (Blueprint $table) {
            $table->dropColumn([
                'processing_fee_charged',
                'processing_margin',
                'total_platform_revenue',
            ]);
        });
    }
};
