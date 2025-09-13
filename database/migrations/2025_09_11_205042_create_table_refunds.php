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
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id', 36);
            $table->uuid('charge_id', 36);
            $table->uuid('payment_intent_id', 36);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('refund_application_fee')->default(false);
            $table->boolean('reverse_transfer')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->uuid('settlement_id', 36)->nullable();
            $table->timestamp('settled_at')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Indexes
            $table->index('merchant_id');
            $table->index('charge_id');
            $table->index('payment_intent_id');
            $table->index('status');
            $table->index('settlement_id');
            $table->index(['merchant_id', 'currency', 'status']);
            
            // Foreign key constraints
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('charge_id')->references('id')->on('charges')->onDelete('cascade');
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->onDelete('cascade');
            $table->foreign('settlement_id')->references('id')->on('settlements')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};