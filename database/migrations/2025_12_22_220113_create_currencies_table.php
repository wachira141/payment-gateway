<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 3)->unique()->comment('ISO 4217 currency code');
            $table->string('name')->comment('Currency name');
            $table->string('symbol', 10)->comment('Currency symbol');
            $table->unsignedTinyInteger('decimals')->default(2)->comment('Number of decimal places (0, 2, or 3)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['code']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
