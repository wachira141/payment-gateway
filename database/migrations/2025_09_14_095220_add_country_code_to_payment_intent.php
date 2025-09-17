<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->string('country_code', 2)
                  ->nullable()
                  ->after('currency')
                  ->comment('ISO 3166-1 alpha-2 country code');

            $table->foreign('country_code')
                  ->references('code')
                  ->on('countries')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropForeign(['country_code']);
            $table->dropColumn('country_code');
        });
    }
};
