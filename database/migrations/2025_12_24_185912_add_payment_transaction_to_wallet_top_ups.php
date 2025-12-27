<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Also adds wallet_id to payment_transactions for direct wallet reference.
     */
    public function up(): void
    {
        // Add wallet_id to payment_transactions for direct wallet reference
        // This enables quick lookup of wallet-related transactions
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->uuid('wallet_id')->nullable()->after('customer_id');
            $table->foreign('wallet_id')
                ->references('id')
                ->on('merchant_wallets')
                ->nullOnDelete();

            $table->index('wallet_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id']);
            $table->dropForeign(['wallet_id']);
            $table->dropColumn('wallet_id');
        });
    }
};
