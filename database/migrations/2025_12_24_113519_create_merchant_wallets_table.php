<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('wallet_id', 32)->unique()->comment('Public ID: wal_xxx');
            $table->uuid('merchant_id');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->string('currency', 3);
            $table->enum('type', ['operating', 'payout', 'reserve'])->default('operating');
            $table->enum('status', ['active', 'frozen', 'suspended', 'closed'])->default('active');
            $table->string('name')->nullable()->comment('User-friendly wallet name');
            
            // Balance tracking (separate from MerchantBalance)
            $table->decimal('available_balance', 15, 4)->default(0);
            $table->decimal('locked_balance', 15, 4)->default(0)->comment('Held for pending disbursements');
            $table->decimal('total_topped_up', 15, 4)->default(0);
            $table->decimal('total_spent', 15, 4)->default(0);
            
            // Limits
            $table->decimal('daily_withdrawal_limit', 15, 4)->nullable();
            $table->decimal('daily_withdrawal_used', 15, 4)->default(0);
            $table->decimal('monthly_withdrawal_limit', 15, 4)->nullable();
            $table->decimal('monthly_withdrawal_used', 15, 4)->default(0);
            $table->decimal('minimum_balance', 15, 4)->default(0);
            
            // Auto-sweep configuration
            $table->boolean('auto_sweep_enabled')->default(false);
            $table->json('auto_sweep_config')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            
            $table->unique(['merchant_id', 'currency', 'type']);
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_wallets');
    }
};
