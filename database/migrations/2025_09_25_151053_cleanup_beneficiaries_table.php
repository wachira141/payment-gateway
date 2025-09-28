<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            // Drop old static columns that are no longer used
            // These have been replaced by dynamic_fields
            $table->dropColumn([
                'account_number',
                'bank_code', 
                'bank_name',
                'mobile_number',
                'type'
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            // Restore the old columns if rollback is needed
            $table->enum('type', ['bank_account', 'mobile_money'])->default('bank_account');
            $table->string('account_number');
            $table->string('bank_code')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('mobile_number')->nullable();
        });
    }
};