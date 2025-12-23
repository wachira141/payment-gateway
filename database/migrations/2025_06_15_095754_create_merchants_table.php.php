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
        Schema::create('merchants', function (Blueprint $table) {
            $table->uuid('id')->primary(); // string UUID primary key
            $table->string('merchant_id', 36)->unique();
            $table->string('legal_name');
            $table->string('display_name');
            $table->text('business_type');
            $table->string('country_code', 3);
            $table->string('default_currency', 3);
            $table->string('status')->default('pending');
            $table->string('compliance_status')->default('pending');
            $table->string('website')->nullable();
            $table->text('business_description')->nullable();
            $table->json('business_address')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('registration_number')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for better query performance
            $table->index('merchant_id');
            $table->index('status');
            $table->index('compliance_status');
            $table->index('country_code');
            $table->index('default_currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};