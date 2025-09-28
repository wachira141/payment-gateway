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
        Schema::table('gateway_health_checks', function (Blueprint $table) {
            // Add new columns if they don't exist
            if (!Schema::hasColumn('gateway_health_checks', 'gateway_id')) {
                $table->string('gateway_id', 36)->nullable()->after('check_id');
                $table->foreign('gateway_id')->references('id')->on('payment_gateways')->onDelete('cascade');
            }
            
            if (!Schema::hasColumn('gateway_health_checks', 'is_healthy')) {
                $table->boolean('is_healthy')->default(true)->after('status');
            }
            
            if (!Schema::hasColumn('gateway_health_checks', 'response_time')) {
                $table->float('response_time')->default(0)->after('is_healthy');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gateway_health_checks', function (Blueprint $table) {
            $table->dropForeign(['gateway_id']);
            $table->dropColumn(['gateway_id', 'is_healthy', 'response_time']);
        });
    }
};