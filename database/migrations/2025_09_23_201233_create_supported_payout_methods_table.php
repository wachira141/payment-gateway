<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supported_payout_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('country_code', 2)->index();
            $table->string('currency', 3)->index();
            $table->string('method_type'); // bank_transfer, mobile_money, international_wire
            $table->string('method_name'); // Display name
            $table->boolean('is_active')->default(true)->index();
            $table->decimal('min_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->integer('processing_time_hours')->default(24);
            $table->json('configuration')->nullable(); // Method-specific settings
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('country_code')->references('code')->on('countries')->onDelete('cascade');

            $table->index(['country_code', 'currency', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supported_payout_methods');
    }
};