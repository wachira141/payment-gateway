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
        Schema::create('app_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('app_id', 36);
            $table->string('url', 1000);
            $table->string('url_hash', 64); // SHA-256 hash
            $table->json('events')->nullable(); // Array of event types to subscribe to
            $table->string('secret', 100)->nullable(); // Webhook signing secret
            $table->boolean('is_active')->default(true);
            $table->json('headers')->nullable(); // Additional headers to send
            $table->integer('timeout_seconds')->default(30);
            $table->integer('retry_attempts')->default(3);
            $table->string('description')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('merchant_apps')->onDelete('cascade');

            
            $table->index(['app_id', 'is_active']);
            $table->unique(['app_id', 'url_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_webhooks');
    }
};