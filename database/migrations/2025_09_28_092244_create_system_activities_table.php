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
        Schema::create('system_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_id', 36);
            $table->string('type')->index();
            $table->string('message');
            $table->string('status')->default('info');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['merchant_id', 'type', 'created_at']);
            
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_activities');
    }
};