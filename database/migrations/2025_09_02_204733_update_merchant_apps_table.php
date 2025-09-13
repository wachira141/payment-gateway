<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_apps', function (Blueprint $table) {
            // Add soft deletes
            $table->softDeletes();
            
            // Add additional indexes for performance
            $table->index(['merchant_id', 'is_active']);
            $table->index(['is_live', 'is_active']);
            $table->index(['created_at']);
            $table->index(['deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_apps', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['merchant_id', 'is_active']);
            $table->dropIndex(['is_live', 'is_active']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['deleted_at']);
        });
    }
};