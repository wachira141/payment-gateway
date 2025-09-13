<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // Drop old foreign key if it exists
            $table->dropForeign(['merchant_app_id']);
            
            // Rename column and update type to match merchant_apps.id
            $table->renameColumn('merchant_app_id', 'app_id');
        });

        // Update the column type and add foreign key
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('app_id')->change();
            $table->foreign('app_id')->references('id')->on('merchant_apps')->onDelete('cascade');
            
            // Add soft deletes
            $table->softDeletes();
            
            // Add additional indexes
            $table->index(['app_id', 'is_active']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropForeign(['app_id']);
            $table->dropIndex(['app_id', 'is_active']);
            $table->dropIndex(['created_at']);
            
            $table->renameColumn('app_id', 'merchant_app_id');
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_app_id')->change();
            $table->foreign('merchant_app_id')->references('id')->on('merchant_apps')->onDelete('cascade');
        });
    }
};