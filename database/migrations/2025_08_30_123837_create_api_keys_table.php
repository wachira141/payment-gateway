<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_app_id');
            $table->string('key_id')->unique()->comment('Public key identifier');
            $table->string('key_hash')->comment('Hashed API key for verification');
            $table->string('name');
            $table->json('scopes')->comment('API scopes for this key');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('rate_limits')->nullable()->comment('Custom rate limits for this key');
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('merchant_app_id')->references('id')->on('merchant_apps')->onDelete('cascade');
            $table->index(['merchant_app_id', 'is_active']);
            $table->index(['key_hash']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};