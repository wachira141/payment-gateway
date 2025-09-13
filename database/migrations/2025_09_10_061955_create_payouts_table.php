<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payout_id', 36)->unique(); // UUID string
            $table->string('merchant_id', 36);
            $table->string('beneficiary_id', 36);
            $table->unsignedBigInteger('amount'); // Amount in smallest currency unit
            $table->string('currency', 3);
            $table->enum('status', ['pending', 'in_transit', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->timestamp('estimated_arrival')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('transaction_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('beneficiary_id')->references('beneficiary_id')->on('beneficiaries')->onDelete('cascade');
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'currency']);
            $table->index(['merchant_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};