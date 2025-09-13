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
            $table->decimal('amount_capturable', 10, 2)->default(0)->after('amount');
            $table->decimal('amount_received', 10, 2)->default(0)->after('amount_capturable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function reverse(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropColumn(['amount_capturable', 'amount_received']);
        });
    }
};