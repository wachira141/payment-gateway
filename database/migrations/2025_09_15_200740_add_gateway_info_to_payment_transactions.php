<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('gateway_code', 50)->nullable()->after('payment_gateway_id')->comment('Standardized gateway code');
            $table->string('payment_method_type', 50)->nullable()->after('gateway_code')->comment('mobile_money, card, bank_transfer, etc.');
            $table->decimal('commission_amount', 10, 2)->nullable()->after('metadata')->comment('Platform commission amount');
            $table->decimal('provider_amount', 10, 2)->nullable()->after('commission_amount')->comment('Amount after commission deduction');
            $table->boolean('commission_processed')->default(false)->after('provider_amount')->comment('Whether commission has been calculated');
            $table->json('commission_breakdown')->nullable()->after('commission_processed')->comment('Detailed commission calculation');
            
            $table->index(['gateway_code', 'payment_method_type'], 'idx_gateway_method');
            $table->index(['commission_processed'], 'idx_commission_processed');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_gateway_method');
            $table->dropIndex('idx_commission_processed');
            $table->dropColumn([
                'gateway_code',
                'payment_method_type', 
                'commission_amount',
                'provider_amount',
                'commission_processed',
                'commission_breakdown'
            ]);
        });
    }
};