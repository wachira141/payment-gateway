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
        Schema::create('kyc_country_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('country_code', 3);
            $table->integer('tier_level'); // 1=Starter, 2=Growth, 3=Enterprise
            $table->string('tier_name'); // "Starter", "Growth", "Enterprise"
            $table->json('required_documents'); // ["national_id", "selfie"]
            $table->json('optional_documents')->nullable(); // ["business_reg"]
            $table->json('required_fields'); // ["phone", "email", "date_of_birth"]
            $table->decimal('daily_limit', 15, 2);
            $table->decimal('monthly_limit', 15, 2);
            $table->decimal('single_transaction_limit', 15, 2);
            $table->string('limit_currency', 3)->default('USD');
            $table->text('description')->nullable();
            $table->json('tier_benefits')->nullable(); // Benefits for this tier
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_code', 'tier_level']);
            $table->index('country_code');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_country_requirements');
    }
};
