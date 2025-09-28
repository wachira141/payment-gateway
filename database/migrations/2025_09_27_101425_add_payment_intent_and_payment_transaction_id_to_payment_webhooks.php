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
            // Add new foreign key columns
            $table->string('payment_intent_id', 36)->nullable()->after('gateway_event_id');
            $table->string('payment_transaction_id', 36)->after('payment_intent_id');
            
            // Add indexes for better performance
            $table->index('payment_intent_id');
            
            // Add foreign key constraints
            $table->foreign('payment_intent_id')
                  ->references('id')
                  ->on('payment_intents')
                  ->onDelete('set null');
                  
            $table->foreign('payment_transaction_id')
                  ->references('transaction_id')
                  ->on('payment_transactions')
                  ->onDelete('cascade');
                  
            $table->foreign('replay_of_webhook_id')
                  ->references('id')
                  ->on('payment_webhooks')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            // Drop foreign key constraints first
            $table->dropForeign(['payment_intent_id']);
            $table->dropForeign(['payment_transaction_id']);
            $table->dropForeign(['replay_of_webhook_id']);
            
            // Drop indexes
            $table->dropIndex(['payment_intent_id']);
            $table->dropIndex(['payment_transaction_id']);
            $table->dropIndex(['event_type', 'status']);
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['replay_of_webhook_id']);
            
            // Drop columns
            $table->dropColumn(['payment_intent_id', 'payment_transaction_id']);
        });
    }
};