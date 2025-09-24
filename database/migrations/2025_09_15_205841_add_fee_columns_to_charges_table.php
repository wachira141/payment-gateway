<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->decimal('gateway_processing_fee', 15, 4)->nullable()->after('fee_amount');
            $table->decimal('platform_application_fee', 15, 4)->nullable()->after('gateway_processing_fee');
            $table->string('gateway_code', 50)->nullable()->after('platform_application_fee');
            
            $table->index(['gateway_code', 'payment_method_type']);
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropIndex(['gateway_code', 'payment_method_type']);
            $table->dropColumn([
                'gateway_processing_fee',
                'platform_application_fee', 
                'gateway_code',
                'payment_method_type'
            ]);
        });
    }
};