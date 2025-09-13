<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_id');
            $table->string('external_id')->nullable()->comment('Merchant\'s customer ID');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // foreign keys
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
           //indexes
            $table->index(['merchant_id', 'email']);
            $table->index(['merchant_id', 'phone']);
            $table->index(['merchant_id', 'external_id']);
            $table->unique(['merchant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
} ;