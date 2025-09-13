<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('beneficiary_id', 36)->unique(); // UUID string
            $table->string('merchant_id', 36);
            $table->enum('type', ['bank_account', 'mobile_money'])->default('bank_account');
            $table->string('name');
            $table->string('account_number');
            $table->string('bank_code')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('currency', 3);
            $table->string('country', 2);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->json('metadata')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->json('verification_details')->nullable();
            $table->text('verification_failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'is_default']);
            $table->index(['merchant_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};