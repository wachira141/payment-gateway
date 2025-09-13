<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('customer_id');
            $table->enum('type', ['card', 'mobile_money', 'bank_account']);
            $table->string('token')->comment('Tokenized reference for security');
            $table->json('metadata')->nullable()->comment('Method-specific details (masked)');
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            //foreign keys
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');

            
            $table->index(['customer_id', 'type']);
            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_methods');
    }
};