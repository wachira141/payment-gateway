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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type')->comment('Type of event being delivered');
            $table->string('app_webhook_id', 36)->comment('Reference to the app webhook configuration');
            $table->json('payload')->comment('Webhook payload data');
            $table->integer('http_status_code')->nullable()->comment('HTTP response status code');
            $table->text('response_body')->nullable()->comment('Response body from webhook endpoint');
            $table->integer('delivery_attempts')->default(0)->comment('Number of delivery attempts');
            $table->enum('status', ['pending', 'delivered', 'failed', 'retrying'])->default('pending');
            $table->string('error_message')->nullable()->comment('Error message if delivery failed');
            $table->timestamp('next_retry_at')->nullable()->comment('When to retry delivery');
            $table->timestamp('delivered_at')->nullable()->comment('When delivery was successful');
            $table->timestamps();

            // Foreign Keys
            $table->foreign('app_webhook_id')->references('id')->on('app_webhooks')->onDelete('cascade');

            
            $table->index(['status']);
            $table->index(['event_type']);
            $table->index(['next_retry_at']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};