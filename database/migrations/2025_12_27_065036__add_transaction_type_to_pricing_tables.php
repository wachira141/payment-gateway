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
        // Add transaction_type to gateway_pricing_configs (merchant-specific pricing)
        Schema::table('gateway_pricing_configs', function (Blueprint $table) {
            $table->enum('transaction_type', ['collection', 'disbursement'])->default('collection')->after('currency');
            
            // Update unique constraint to include transaction_type
            $table->index(['merchant_id', 'gateway_code', 'payment_method_type', 'currency', 'transaction_type'], 'gateway_pricing_full_index');
        });

        // Add transaction_type to default_gateway_pricing
        Schema::table('default_gateway_pricing', function (Blueprint $table) {
            $table->enum('transaction_type', ['collection', 'disbursement'])->default('collection')->after('currency');
            
            // Update index to include transaction_type
            $table->index(['gateway_code', 'payment_method_type', 'currency', 'transaction_type', 'tier'], 'default_pricing_full_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_pricing_configs', function (Blueprint $table) {
            $table->dropIndex('gateway_pricing_full_index');
            $table->dropColumn('transaction_type');
        });

        Schema::table('default_gateway_pricing', function (Blueprint $table) {
            $table->dropIndex('default_pricing_full_index');
            $table->dropColumn('transaction_type');
        });
    }
};
