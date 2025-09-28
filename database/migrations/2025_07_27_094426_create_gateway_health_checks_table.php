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
        Schema::create('gateway_health_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('check_id')->unique();
            $table->string('gateway_name');
            $table->string('gateway_endpoint');
            $table->enum('check_type', ['ping', 'transaction_test', 'balance_check', 'webhook_test']);
            $table->enum('status', ['healthy', 'degraded', 'unhealthy', 'timeout']);
            $table->integer('response_time_ms')->nullable();
            $table->text('response_data')->nullable();
            $table->text('error_message')->nullable();
            $table->json('health_metrics')->nullable();
            $table->timestamp('checked_at');
            $table->decimal('success_rate', 5, 2)->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
            
            $table->index(['gateway_name', 'status']);
            $table->index(['check_type', 'checked_at']);
            $table->index(['status', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gateway_health_checks');
    }
};