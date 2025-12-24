<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Links WalletTopUp to PaymentTransaction for integrated payment processing.
     * Also adds wallet_id to payment_transactions for direct wallet reference.
     */
    public function up(): void
    {
        // Add payment_transaction_id to wallet_top_ups for linking to PaymentTransaction
        Schema::table('wallet_top_ups', function (Blueprint $table) {
            $table->uuid('payment_transaction_id')->nullable()->after('gateway_reference');
            $table->foreign('payment_transaction_id')
                ->references('id')
                ->on('payment_transactions')
                ->nullOnDelete();

            $table->index('payment_transaction_id');
        });

        // Add wallet_id to payment_transactions for direct wallet reference
        // This enables quick lookup of wallet-related transactions
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->uuid('wallet_id')->nullable()->after('customer_id');
            $table->foreign('wallet_id')
                ->references('id')
                ->on('merchant_wallets')
                ->nullOnDelete();

            $table->index('wallet_id');

            // Add index on payable_type for efficient wallet_top_up queries
            $table->index(['payable_type', 'payable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_top_ups', function (Blueprint $table) {
            $table->dropForeign(['payment_transaction_id']);
            $table->dropColumn('payment_transaction_id');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id']);
            $table->dropForeign(['wallet_id']);
            $table->dropColumn('wallet_id');
        });
    }
};
