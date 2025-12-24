<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_id', 32)->unique()->comment('Public ID: wtxn_xxx');
            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('merchant_wallets')->onDelete('cascade');
            $table->uuid('merchant_id');
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            
            $table->enum('type', [
                'top_up',           // Funding the wallet
                'disbursement',     // Sending money out
                'transfer_in',      // Received from another wallet
                'transfer_out',     // Sent to another wallet
                'sweep_in',         // Swept from MerchantBalance
                'fee',              // Fee deduction
                'refund',           // Disbursement refund
                'adjustment',       // Manual adjustment
                'hold',             // Funds locked
                'release',          // Funds unlocked
                'reversal'          // Transaction reversal
            ]);
            
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3);
            $table->decimal('balance_before', 15, 4);
            $table->decimal('balance_after', 15, 4);
            $table->enum('status', ['pending', 'completed', 'failed', 'reversed'])->default('pending');
            
            $table->string('reference')->nullable();
            $table->uuidMorphs('source'); // source_type, source_id - links to TopUp, Disbursement, etc.
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['wallet_id', 'type']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
