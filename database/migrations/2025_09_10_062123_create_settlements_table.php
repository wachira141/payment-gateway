<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('settlement_id', 36)->unique(); // UUID string
            $table->string('merchant_id', 36);
            $table->string('currency', 3);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->unsignedBigInteger('gross_amount'); // Amount in smallest currency unit
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->unsignedBigInteger('net_amount'); // Amount in smallest currency unit
            $table->unsignedInteger('transaction_count')->default(0);
            $table->date('settlement_date');
            $table->json('transactions')->nullable(); // Array of transaction IDs
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('bank_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'currency']);
            $table->index(['merchant_id', 'settlement_date']);
            $table->index('settlement_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};