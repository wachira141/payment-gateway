<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            // Add new dynamic structure columns
            $table->string('payout_method_id', 36)->nullable()->after('merchant_id');
            $table->json('dynamic_fields')->nullable()->after('metadata');
            
            // Add foreign key constraint
            $table->foreign('payout_method_id')->references('id')->on('supported_payout_methods')->onDelete('set null');
            
            // Add index for performance
            $table->index(['merchant_id', 'payout_method_id']);
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropForeign(['payout_method_id']);
            $table->dropIndex(['merchant_id', 'payout_method_id']);
            $table->dropColumn(['payout_method_id', 'dynamic_fields']);
        });
    }
};