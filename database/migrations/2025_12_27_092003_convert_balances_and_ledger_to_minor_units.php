<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Convert existing ledger_entries data from major to minor units (multiply by 100)
        DB::statement('UPDATE ledger_entries SET amount = ROUND(amount * 100)');

        // Step 2: Convert existing merchant_balances data from major to minor units (multiply by 100)
        DB::statement('UPDATE merchant_balances SET 
            available_amount = ROUND(available_amount * 100),
            pending_amount = ROUND(pending_amount * 100),
            reserved_amount = ROUND(reserved_amount * 100),
            total_volume = ROUND(total_volume * 100)
        ');

        // Step 3: Change ledger_entries.amount column type from DECIMAL to BIGINT
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->bigInteger('amount')->default(0)->comment('Amount in minor units (cents)')->change();
        });

        // Step 4: Change merchant_balances columns from DECIMAL to BIGINT
        Schema::table('merchant_balances', function (Blueprint $table) {
            $table->bigInteger('available_amount')->default(0)->comment('Available for payouts (minor units)')->change();
            $table->bigInteger('pending_amount')->default(0)->comment('Pending settlement (minor units)')->change();
            $table->bigInteger('reserved_amount')->default(0)->comment('Reserved for disputes/chargebacks (minor units)')->change();
            $table->bigInteger('total_volume')->default(0)->comment('Lifetime processed volume (minor units)')->change();
        });
    }

    public function down(): void
    {
        // Step 1: Change columns back to DECIMAL
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->decimal('amount', 15, 4)->default(0)->change();
        });

        Schema::table('merchant_balances', function (Blueprint $table) {
            $table->decimal('available_amount', 15, 4)->default(0)->comment('Available for payouts')->change();
            $table->decimal('pending_amount', 15, 4)->default(0)->comment('Pending settlement')->change();
            $table->decimal('reserved_amount', 15, 4)->default(0)->comment('Reserved for disputes/chargebacks')->change();
            $table->decimal('total_volume', 15, 4)->default(0)->comment('Lifetime processed volume')->change();
        });

        // Step 2: Convert data back from minor to major units (divide by 100)
        DB::statement('UPDATE ledger_entries SET amount = amount / 100');

        DB::statement('UPDATE merchant_balances SET 
            available_amount = available_amount / 100,
            pending_amount = pending_amount / 100,
            reserved_amount = reserved_amount / 100,
            total_volume = total_volume / 100
        ');
    }
};