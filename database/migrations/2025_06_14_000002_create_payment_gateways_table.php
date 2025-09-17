
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
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->comment('Gateway name (Stripe, M-Pesa, Telebirr)');
            $table->string('code')->unique()->comment('Unique gateway code');
            $table->string('type')->comment('Gateway type (card, mobile_money, bank)');
            $table->json('supported_countries')->comment('Array of supported country codes');
            $table->json('supported_currencies')->comment('Array of supported currency codes');
            $table->string('icon')->nullable()->comment('Gateway icon/logo');
            $table->text('description')->nullable()->comment('Gateway description');
            $table->boolean('is_active')->default(true)->comment('Whether gateway is active');
            $table->boolean('supports_recurring')->default(false)->comment('Supports recurring payments');
            $table->boolean('supports_refunds')->default(false)->comment('Supports refunds');
            $table->json('configuration')->nullable()->comment('Gateway-specific configuration');
            $table->integer('priority')->default(0)->comment('Gateway priority for selection');
            $table->timestamps();
            
            $table->index(['is_active']);
            $table->index(['type']);
            $table->index(['priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
