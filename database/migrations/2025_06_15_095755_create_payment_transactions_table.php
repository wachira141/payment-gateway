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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_id')->unique()->comment('Internal transaction ID');
            
            // Changed foreign keys
            $table->uuid('merchant_id');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            
            $table->uuid('payment_gateway_id');
            $table->foreign('payment_gateway_id')->references('id')->on('payment_gateways')->onDelete('restrict');
            
            $table->string('gateway_transaction_id')->nullable()->comment('Gateway-specific transaction ID');
            $table->string('gateway_payment_intent_id')->nullable()->comment('Payment intent ID (Stripe)');
            $table->uuid('payable_id')->comment('Related model ID');
            $table->string('payable_type')->comment('Related model type (goal_request, meal_plan_request, etc.)');
            $table->decimal('amount', 10, 2)->comment('Transaction amount');
            $table->string('currency', 3)->comment('Transaction currency');
            $table->enum('type', ['payment', 'refund', 'subscription', 'subscription_renewal'])->comment('Transaction type');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled', 'refunded', 'partially_refunded'])->default('pending');
            $table->text('description')->nullable()->comment('Transaction description');
            $table->json('metadata')->nullable()->comment('Additional transaction data');
            $table->json('gateway_response')->nullable()->comment('Gateway response data');
            $table->string('failure_reason')->nullable()->comment('Reason for failure');
            $table->integer('retry_count')->default(0)->comment('Number of retry attempts');
            $table->timestamp('next_retry_at')->nullable()->comment('Next retry timestamp');
            $table->timestamp('completed_at')->nullable()->comment('Transaction completion timestamp');
            $table->timestamp('failed_at')->nullable()->comment('Transaction failure timestamp');
            $table->timestamps();
            
            $table->index(['merchant_id']);
            $table->index(['status']);
            $table->index(['type']);
            $table->index(['gateway_transaction_id']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};