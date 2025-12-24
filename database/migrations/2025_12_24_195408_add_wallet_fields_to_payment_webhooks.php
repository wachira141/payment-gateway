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
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->uuid('wallet_top_up_id')->nullable()->after('payment_transaction_id');
            $table->uuid('merchant_id')->nullable()->after('merchant_app_id');
            
            $table->foreign('wallet_top_up_id')->references('id')->on('wallet_top_ups')->nullOnDelete();
            $table->foreign('merchant_id')->references('id')->on('merchants')->nullOnDelete();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->dropColumn('wallet_top_up_id');
            $table->dropColumn('merchant_id');
            
            $table->dropForeign('wallet_top_up_id');
            $table->dropForeign('merchant_id');

        });
    }
};
