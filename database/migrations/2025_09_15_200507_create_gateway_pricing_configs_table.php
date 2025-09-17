<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_pricing_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_id');
            $table->string('gateway_code', 50)->comment('mpesa, stripe, telebirr, banking, etc.');
            $table->string('payment_method_type', 50)->comment('mobile_money, card, bank_transfer, etc.');
            $table->string('currency', 3);
            $table->decimal('processing_fee_rate', 5, 4)->default(0)->comment('Gateway processing fee rate (e.g., 0.029 for 2.9%)');
            $table->integer('processing_fee_fixed')->default(0)->comment('Fixed fee in smallest currency unit');
            $table->decimal('application_fee_rate', 5, 4)->default(0)->comment('Platform commission rate');
            $table->integer('application_fee_fixed')->default(0)->comment('Fixed platform fee');
            $table->integer('min_fee')->default(0)->comment('Minimum total fee');
            $table->integer('max_fee')->nullable()->comment('Maximum total fee');
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->default(now());
            $table->timestamp('effective_to')->nullable();
            $table->json('metadata')->nullable()->comment('Additional configuration data');
            $table->timestamps();
            
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->index(['merchant_id', 'gateway_code', 'payment_method_type', 'currency', 'is_active'], 'idx_merchant_gateway_lookup');
            $table->index(['effective_from', 'effective_to'], 'idx_effective_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_pricing_configs');
    }
};