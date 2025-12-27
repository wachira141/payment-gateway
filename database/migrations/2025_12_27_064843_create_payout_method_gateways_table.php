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
        Schema::create('payout_method_gateways', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Link to supported payout method
            $table->uuid('payout_method_id');
            
            // Gateway information
            $table->string('gateway_code'); // 'mpesa', 'mtn_momo', 'airtel_money', 'flutterwave', etc.
            $table->string('gateway_method_code')->nullable(); // Gateway-specific method identifier
            
            // Country/Currency specificity (supports multi-country/multi-currency)
            $table->string('country_code', 2);
            $table->string('currency', 3);
            
            // Priority for routing (lower = higher priority)
            $table->integer('priority')->default(0);
            
            // Status
            $table->boolean('is_active')->default(true);
            
            // Gateway-specific configuration
            $table->json('gateway_config')->nullable(); // API keys, endpoints, etc.
            
            // Processing limits
            $table->bigInteger('min_amount')->nullable(); // In smallest currency unit
            $table->bigInteger('max_amount')->nullable();
            
            // Estimated processing time
            $table->integer('processing_time_minutes')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['payout_method_id', 'is_active']);
            $table->index(['country_code', 'currency', 'is_active']);
            $table->index(['gateway_code', 'is_active']);
            $table->index('priority');
            
            // Unique constraint: one gateway per payout method per country/currency
            $table->unique(['payout_method_id', 'gateway_code', 'country_code', 'currency'], 'payout_gateway_unique');
            
            // Foreign key
            $table->foreign('payout_method_id')->references('id')->on('supported_payout_methods')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_method_gateways');
    }
};
