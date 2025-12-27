<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            // Add separate fee columns for gateway and platform fees
            $table->bigInteger('gateway_processing_fee')->default(0)->after('fee_amount');
            $table->bigInteger('platform_application_fee')->default(0)->after('gateway_processing_fee');
            
            // Add gateway tracking
            $table->string('gateway_code')->nullable()->after('platform_application_fee');
            $table->string('payout_method_type')->nullable()->after('gateway_code');
            
            // Add platform earning reference
            $table->uuid('platform_earning_id')->nullable()->after('payout_method_type');
            
            // Index for reporting
            $table->index('gateway_code');
            $table->index('platform_earning_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropIndex(['gateway_code']);
            $table->dropIndex(['platform_earning_id']);
            
            $table->dropColumn([
                'gateway_processing_fee',
                'platform_application_fee',
                'gateway_code',
                'payout_method_type',
                'platform_earning_id',
            ]);
        });
    }
};
