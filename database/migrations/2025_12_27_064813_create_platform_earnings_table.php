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
        Schema::create('platform_earnings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Source reference (polymorphic)
            $table->string('source_type'); // 'payment', 'disbursement', 'subscription', etc.
            $table->uuid('source_id');
            
            // Fee breakdown
            $table->string('fee_type'); // 'application_fee', 'processing_margin', 'subscription_fee', etc.
            $table->bigInteger('gross_amount'); // Total amount before gateway cost (in smallest currency unit)
            $table->bigInteger('gateway_cost')->default(0); // What we pay to gateway provider
            $table->bigInteger('net_amount'); // Our actual profit (gross - gateway_cost)
            $table->string('currency', 3);
            
            // Context
            $table->uuid('merchant_id');
            $table->string('gateway_code')->nullable();
            $table->string('payment_method_type')->nullable();
            $table->string('transaction_id')->nullable(); // Original transaction reference
            
            // Status tracking
            $table->enum('status', ['pending', 'settled', 'refunded', 'partially_refunded'])->default('pending');
            $table->timestamp('earned_at');
            $table->timestamp('settled_at')->nullable();
            
            // Additional data
            $table->json('fee_breakdown')->nullable(); // Detailed breakdown of fee calculation
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['merchant_id', 'currency']);
            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'currency']);
            $table->index(['earned_at', 'currency']);
            $table->index('gateway_code');
            $table->index('fee_type');
            
            // Foreign key
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_earnings');
    }
};
