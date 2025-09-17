<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('default_gateway_pricing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway_code', 50)->comment('mpesa, stripe, telebirr, banking, etc.');
            $table->string('payment_method_type', 50)->comment('mobile_money, card, bank_transfer, etc.');
            $table->string('currency', 3);
            $table->decimal('processing_fee_rate', 5, 4)->default(0)->comment('Gateway processing fee rate');
            $table->integer('processing_fee_fixed')->default(0)->comment('Fixed fee in smallest currency unit');
            $table->decimal('application_fee_rate', 5, 4)->default(0)->comment('Platform commission rate');
            $table->integer('application_fee_fixed')->default(0)->comment('Fixed platform fee');
            $table->integer('min_fee')->default(0)->comment('Minimum total fee');
            $table->integer('max_fee')->nullable()->comment('Maximum total fee');
            $table->string('tier', 20)->default('standard')->comment('standard, premium, enterprise');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable()->comment('Additional configuration data');
            $table->timestamps();
            
            $table->unique(['gateway_code', 'payment_method_type', 'currency', 'tier'], 'unique_gateway_pricing');
            $table->index(['gateway_code', 'is_active'], 'idx_gateway_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('default_gateway_pricing');
    }
};