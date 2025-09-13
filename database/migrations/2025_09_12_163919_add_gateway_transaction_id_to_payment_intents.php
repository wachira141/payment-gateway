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
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->string('gateway_transaction_id')->nullable()->after('merchant_app_id')->comment('Gateway-specific transaction ID');
            $table->index(['gateway_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropIndex(['gateway_transaction_id']);
            $table->dropColumn('gateway_transaction_id');
        });
    }
};