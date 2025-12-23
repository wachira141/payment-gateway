<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_user_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_user_id');
            $table->uuid('permission_id');
            $table->uuid('granted_by')->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            
            $table->foreign('merchant_user_id')->references('id')->on('merchant_users')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $table->foreign('granted_by')->references('id')->on('merchant_users')->onDelete('set null');
            $table->unique(['merchant_user_id', 'permission_id']);
            
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_user_permissions');
    }
};
