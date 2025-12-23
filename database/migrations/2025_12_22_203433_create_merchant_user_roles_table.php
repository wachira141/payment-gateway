<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_user_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_user_id');
            $table->uuid('role_id');
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('assigned_at')->useCurrent();
            
            $table->foreign('merchant_user_id')->references('id')->on('merchant_users')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('assigned_by')->references('id')->on('merchant_users')->onDelete('set null');
            $table->unique(['merchant_user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_user_roles');
    }
};
