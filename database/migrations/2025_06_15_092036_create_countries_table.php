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
        Schema::create('countries', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('Unique identifier for the country');
            $table->string('code', 2)->unique()->comment('ISO 3166-1 alpha-2 country code');
            $table->string('name')->comment('Country name');
            $table->string('iso3', 3)->nullable()->comment('ISO 3166-1 alpha-3 country code');
            $table->string('numeric_code', 3)->nullable()->comment('ISO 3166-1 numeric country code');
            $table->string('phone_code')->nullable()->comment('International dialing code');
            $table->string('currency_code', 3)->nullable()->comment('ISO 4217 currency code');
            $table->string('currency_name')->nullable()->comment('Currency name');
            $table->string('currency_symbol', 10)->nullable()->comment('Currency symbol');
            $table->string('region')->nullable()->comment('Geographic region');
            $table->string('subregion')->nullable()->comment('Geographic subregion');
            $table->decimal('latitude', 10, 8)->nullable()->comment('Country latitude');
            $table->decimal('longitude', 11, 8)->nullable()->comment('Country longitude');
            $table->boolean('is_active')->default(true)->comment('Whether the country is active');
            $table->timestamps();
            
            $table->index(['code']);
            $table->index(['name']);
            $table->index(['region']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
