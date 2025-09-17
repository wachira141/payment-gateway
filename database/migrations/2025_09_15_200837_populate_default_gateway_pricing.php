<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        // Run the seeder to populate default pricing configurations
        Artisan::call('db:seed', ['--class' => 'DefaultGatewayPricingSeeder']);
    }

    public function down(): void
    {
        // Remove all default pricing configurations
        \App\Models\DefaultGatewayPricing::truncate();
    }
};