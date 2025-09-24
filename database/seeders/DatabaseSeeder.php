<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        $this->call([
            MerchantSeeder::class,
            MerchantUserSeeder::class,
            CountrySeeder::class,
            PaymentGatewaySeeder::class,
            LanguagesSeeder::class,
            DefaultGatewayPricingSeeder::class,
            SupportedBanksSeeder::class,
            SupportedPayoutMethodsSeeder::class,
        ]);
    }
}
