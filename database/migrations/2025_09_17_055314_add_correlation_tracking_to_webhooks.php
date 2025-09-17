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
        // Add correlation tracking to payment_webhooks
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->string('correlation_id')->nullable()->after('status')->comment('Correlation ID for end-to-end tracking');
            $table->string('replay_of_webhook_id')->nullable()->after('correlation_id')->comment('If this is a replay, reference to original webhook');
            
            $table->index(['correlation_id']);
            $table->index(['replay_of_webhook_id']);
        });
        
        // Add correlation tracking to webhook_deliveries  
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('correlation_id')->nullable()->after('status')->comment('Correlation ID linking to incoming webhook');
            $table->string('source_webhook_id')->nullable()->after('correlation_id')->comment('Source payment webhook that triggered this delivery');
            
            $table->index(['correlation_id']);
            $table->index(['source_webhook_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['replay_of_webhook_id']);
            $table->dropColumn(['correlation_id', 'replay_of_webhook_id']);
        });
        
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['source_webhook_id']);
            $table->dropColumn(['correlation_id', 'source_webhook_id']);
        });
    }
};