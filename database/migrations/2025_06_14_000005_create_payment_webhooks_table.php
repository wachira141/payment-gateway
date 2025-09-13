
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
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('payment_gateway_id', 36);
            $table->string('webhook_id')->unique()->comment('Unique webhook identifier');
            $table->string('event_type')->comment('Webhook event type');
            $table->string('gateway_event_id')->nullable()->comment('Gateway-specific event ID');
            $table->json('payload')->comment('Webhook payload data');
            $table->enum('status', ['pending', 'processed', 'failed', 'ignored'])->default('pending');
            $table->string('processing_error')->nullable()->comment('Error message if processing failed');
            $table->integer('retry_count')->default(0)->comment('Number of processing retry attempts');
            $table->timestamp('processed_at')->nullable()->comment('When webhook was processed');
            $table->timestamps();
            
            $table->foreign('payment_gateway_id')->references('id')->on('merchants')->onDelete('restrict');

            $table->index(['event_type']);
            $table->index(['status']);
            $table->index(['gateway_event_id']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
