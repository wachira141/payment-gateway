<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->uuid('actor_id');
            $table->uuid('target_user_id');
            $table->string('action'); // role_assigned, role_removed, permission_granted, permission_revoked
            $table->string('entity_type'); // role, permission
            $table->uuid('entity_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->index(['merchant_id', 'created_at']);
            $table->index('actor_id');
            $table->index('target_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_audit_logs');
    }
};
