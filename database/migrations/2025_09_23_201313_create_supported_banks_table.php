<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supported_banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('country_code', 2)->index();
            $table->string('bank_code', 20)->unique();
            $table->string('bank_name');
            $table->string('swift_code')->nullable();
            $table->string('routing_number')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('bank_type')->default('commercial'); // commercial, microfinance, etc.
            $table->string('logo_url')->nullable();
            $table->string('website_url')->nullable();
            $table->timestamps();

            //foreign key constraint
            $table->foreign('country_code')->references('code')->on('countries')->onDelete('cascade');

            $table->index(['country_code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supported_banks');
    }
};