<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_top_ups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('top_up_id', 32)->unique()->comment('Public ID: wtu_xxx');
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('merchant_wallets')->onDelete('cascade');
            $table->uuid('merchant_id');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3);
            $table->enum('method', ['bank_transfer', 'mobile_money', 'card', 'balance_sweep']);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'expired', 'cancelled'])->default('pending');
            
            // Gateway/provider details
            $table->string('gateway_type')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_response')->nullable();
            
            // For bank transfers
            $table->string('bank_reference')->nullable();
            $table->text('payment_instructions')->nullable();
            
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['merchant_id', 'status']);
            $table->index(['wallet_id', 'created_at']);
            $table->index(['gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_top_ups');
    }
};
