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
            $table->string('gateway_payment_intent_id')->nullable()->after('gateway_transaction_id')->comment('Gateway-specific payment intent ID');
            $table->json('gateway_data')->nullable()->after('gateway_payment_intent_id')->comment('Gateway-specific response data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropIndex(['gateway_transaction_id']);
            $table->dropIndex(['gateway_payment_intent_id']);
            $table->dropColumn(['gateway_transaction_id', 'gateway_payment_intent_id', 'gateway_data']);
        });
    }
};