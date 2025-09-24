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
            $table->string('merchant_app_id', 36)->nullable()->after('payment_gateway_id');
            
            // Add foreign key constraint
            $table->foreign('merchant_app_id')->references('id')->on('merchant_apps')->onDelete('set null');
            
            // Add index for better query performance
            $table->index(['merchant_app_id', 'event_type']);
            $table->index(['merchant_app_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_webhooks', function (Blueprint $table) {
            $table->dropForeign(['merchant_app_id']);
            $table->dropIndex(['merchant_app_id', 'event_type']);
            $table->dropIndex(['merchant_app_id', 'status']);
            $table->dropColumn('merchant_app_id');
        });
    }
};