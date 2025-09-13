<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('intent_id')->unique()->comment('Public payment intent ID');
            $table->string('merchant_app_id', 36);
            $table->string('merchant_id', 36);
            // Foreign key constraint
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3);
            $table->enum('capture_method', ['automatic', 'manual'])->default('automatic');
            $table->enum('status', ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'succeeded', 'cancelled'])->default('requires_payment_method');
            $table->string('client_secret')->nullable();
            $table->string('client_reference_id')->nullable()->comment('Merchant reference');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->json('payment_method_types')->comment('Allowed payment methods');
            $table->string('receipt_email')->nullable();
            $table->json('shipping')->nullable();
            $table->json('billing_details')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            
            $table->foreign('merchant_app_id')->references('id')->on('merchant_apps')->onDelete('cascade');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->index(['merchant_id', 'status']);
            $table->index(['client_reference_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};