<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('charge_id')->unique()->comment('Public charge ID');
            $table->string('payment_intent_id')->comment('Associated payment intent ID');
            // foreign key payment intent

            // $table->foreignId('payment_intent_id')->constrained()->onDelete('cascade');
            $table->string('merchant_id', 36);
            // Foreign key constraint
            $table->foreign('merchant_id')->references('merchant_id')->on('merchants')->onDelete('cascade');
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3);
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed', 'cancelled', 'disputed'])->default('pending');
            $table->string('payment_method_type')->comment('card, mobile_money, bank_transfer, ussd, etc.');
            $table->json('payment_method_details')->comment('Method-specific details');
            $table->string('connector_name')->comment('Which connector processed this charge');
            $table->string('connector_charge_id')->nullable()->comment('Connector-specific charge ID');
            $table->json('connector_response')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();
            $table->decimal('fee_amount', 10, 4)->default(0);
            $table->string('receipt_number')->nullable();
            $table->json('risk_score')->nullable();
            $table->boolean('captured')->default(false);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->onDelete('cascade');

            
            $table->index(['merchant_id', 'status']);
            $table->index(['payment_intent_id']);
            $table->index(['connector_name', 'status']);
            $table->index(['payment_method_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};