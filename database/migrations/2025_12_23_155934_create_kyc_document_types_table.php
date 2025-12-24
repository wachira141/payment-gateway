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
        Schema::create('kyc_document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('country_code', 3);
            $table->string('document_key'); // "national_id", "business_reg", "tax_id"
            $table->string('display_name'); // "National ID (Huduma Namba)"
            $table->string('local_name')->nullable(); // "Kipande"
            $table->text('description')->nullable();
            $table->json('accepted_formats')->nullable(); // ["PDF", "JPG", "PNG"]
            $table->json('validation_rules')->nullable(); // {"min_length": 8, "pattern": "..."}
            $table->string('example_value')->nullable(); // "12345678"
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('requires_front_back')->default(false); // For IDs that need both sides
            $table->boolean('requires_verification_api')->default(false);
            $table->string('verification_provider')->nullable(); // "smile_id", "youverify"
            $table->integer('display_order')->default(0);
            $table->enum('category', ['identity', 'business', 'financial'])->default('identity'); // identity, business, financial
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_code', 'document_key']);
            $table->index('country_code');
            $table->index('document_key');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_document_types');
    }
};
