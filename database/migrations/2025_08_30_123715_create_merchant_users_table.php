<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_users', function (Blueprint $table) {
            $table->uuid('id')->primary(); // string UUID primary key
            $table->string('merchant_id');
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['owner', 'admin', 'developer', 'finance', 'support'])->default('admin');
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->json('permissions')->nullable()->comment('Granular permissions for the user');
            $table->timestamp('last_login_at')->nullable();
            $table->string('phone')->nullable();
            $table->json('metadata')->nullable();
            $table->rememberToken();
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            
            $table->unique(['merchant_id', 'email']);
            $table->index(['merchant_id', 'role']);
            $table->index(['email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_users');
    }
};