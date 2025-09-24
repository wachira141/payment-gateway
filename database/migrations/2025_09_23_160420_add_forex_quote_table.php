<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fx_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_id', 36);
            $table->string('from_currency', 10);
            $table->string('to_currency', 10);
            $table->decimal('from_amount', 20, 8);
            $table->decimal('to_amount', 20, 8);
            $table->decimal('net_to_amount', 20, 8)->nullable();
            $table->decimal('exchange_rate', 20, 8);
            $table->decimal('fee_amount', 20, 8)->nullable();
            $table->string('fee_currency', 10)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('rate_source')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['from_currency', 'to_currency']);
            $table->index('status');

            $table->foreign('merchant_id')
                  ->references('id')
                  ->on('merchants')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_quotes');
    }
};
