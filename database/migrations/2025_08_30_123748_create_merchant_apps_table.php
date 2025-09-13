<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_apps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('merchant_id', 36);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('client_id')->unique()->comment('OAuth client identifier');
            $table->string('client_secret')->comment('OAuth client secret');
            $table->string('webhook_url')->nullable();
            $table->json('redirect_urls')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('website_url')->nullable();
            $table->boolean('is_live')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('webhook_events')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('secret_regenerated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');

            
            $table->index(['merchant_id', 'is_live']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_apps');
    }
};