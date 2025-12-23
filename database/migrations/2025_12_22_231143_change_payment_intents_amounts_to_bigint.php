<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration converts amount columns from DECIMAL to BIGINT
     * to store amounts in minor units (cents) like Stripe does.
     * 
     * IMPORTANT: Existing data is converted from major to minor units
     * (multiplied by 100) during this migration.
     */
    public function up(): void
    {
        // First, convert existing data from major units to minor units
        // This multiplies all amounts by 100 (e.g., 25.00 becomes 2500)
        DB::statement('UPDATE payment_intents SET 
            amount = ROUND(amount * 100),
            amount_capturable = ROUND(amount_capturable * 100),
            amount_received = ROUND(amount_received * 100)
        ');

        // Then change column types to BIGINT
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->bigInteger('amount')->change();
            $table->bigInteger('amount_capturable')->default(0)->change();
            $table->bigInteger('amount_received')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First change back to DECIMAL
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->decimal('amount', 15, 4)->change();
            $table->decimal('amount_capturable', 10, 2)->default(0)->change();
            $table->decimal('amount_received', 10, 2)->default(0)->change();
        });

        // Then convert data back from minor to major units
        DB::statement('UPDATE payment_intents SET 
            amount = amount / 100,
            amount_capturable = amount_capturable / 100,
            amount_received = amount_received / 100
        ');
    }
};
