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
            $table->uuid('merchant_id');

            $table->decimal('amount', 15, 4);
            $table->string('currency', 3);

            $table->enum('method', [
                'bank_transfer',
                'mobile_money',
                'card',
                'balance_sweep'
            ]);

            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'expired',
                'cancelled'
            ])->default('pending');

            // Gateway/provider details
            $table->string('gateway_type')->nullable();
            $table->string('gateway_reference')->nullable();

            // Link to payment transaction
            $table->uuid('payment_transaction_id')->nullable();

            $table->json('gateway_response')->nullable();

            // Bank transfer specific
            $table->string('bank_reference')->nullable();
            $table->text('payment_instructions')->nullable();

            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('wallet_id')
                ->references('id')
                ->on('merchant_wallets')
                ->cascadeOnDelete();

            $table->foreign('merchant_id')
                ->references('id')
                ->on('merchants')
                ->cascadeOnDelete();

            $table->foreign('payment_transaction_id')
                ->references('id')
                ->on('payment_transactions')
                ->nullOnDelete();

            // Indexes
            $table->index(['merchant_id', 'status']);
            $table->index(['wallet_id', 'created_at']);
            $table->index('gateway_reference');
            $table->index('payment_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_top_ups');
    }
};
