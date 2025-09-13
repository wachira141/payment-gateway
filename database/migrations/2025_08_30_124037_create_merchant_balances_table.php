<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');

            // Foreign key constraint
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->string('currency', 3);
            $table->decimal('available_amount', 15, 4)->default(0)->comment('Available for payouts');
            $table->decimal('pending_amount', 15, 4)->default(0)->comment('Pending settlement');
            $table->decimal('reserved_amount', 15, 4)->default(0)->comment('Reserved for disputes/chargebacks');
            $table->decimal('total_volume', 15, 4)->default(0)->comment('Lifetime processed volume');
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamps();
            
            $table->unique(['merchant_id', 'currency']);
            $table->index(['merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_balances');
    }
};