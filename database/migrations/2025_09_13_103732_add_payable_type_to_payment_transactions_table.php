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
        Schema::table('payment_transactions', function (Blueprint $table) {
            // Update the existing morphs to be nullable and add the new payment_intent type
            $table->string('payable_type')->nullable()->change();
            $table->string('payable_id')->nullable()->change();
        });

        // Add index for better performance on queries by payable
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->index(['payable_type', 'payable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['payable_type', 'payable_id']);
        });
    }
};