<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Illuminate\Support\Str;

class MerchantUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        //['owner', 'admin', 'developer', 'finance', 'support'])
        $merchantUsers = [
            [
                'id' => Str::random(16),
                'merchant_id' => "merch_1", // assuming M001 is ID=1 in merchants table
                'name' => 'John Doe',
                'email' => 'john.doe@techsolutions.com',
                'password' => Hash::make('password123'),
                'role' => 'owner',
                'status' => 'active',
                'permissions' => json_encode(['manage_apps', 'view_payments', 'manage_payouts']),
                'last_login_at' => Carbon::now()->subDays(2),
                'phone' => '+1-555-123-4567',
                'metadata' => json_encode([
                    'position' => 'CEO',
                    'department' => 'Management'
                ]),
                'created_at' => Carbon::now()->subMonths(6),
                'updated_at' => Carbon::now()->subMonths(1),
            ],
            [
                'id' => Str::random(16),
                'merchant_id' => "merch_2", // assuming M002 is ID=2
                'name' => 'Sarah Green',
                'email' => 'sarah.green@greenearthorganics.ca',
                'password' => Hash::make('securepass'),
                'role' => 'admin',
                'status' => 'active',
                'permissions' => json_encode(['manage_apps', 'view_payments']),
                'last_login_at' => Carbon::now()->subWeek(),
                'phone' => '+1-416-555-7890',
                'metadata' => json_encode([
                    'position' => 'Operations Manager',
                    'department' => 'Logistics'
                ]),
                'created_at' => Carbon::now()->subMonths(4),
                'updated_at' => Carbon::now()->subWeeks(2),
            ],
            [
                'id' => Str::random(16),
                'merchant_id' => "merch_3", // assuming M003 is ID=3
                'name' => 'Michael Brown',
                'email' => 'michael.brown@globalpartners.co.uk',
                'password' => Hash::make('test1234'),
                'role' => 'finance',
                'status' => 'pending',
                'permissions' => json_encode(['view_payments']),
                'last_login_at' => null,
                'phone' => '+44-20-5555-6789',
                'metadata' => json_encode([
                    'position' => 'Consultant',
                    'specialization' => 'Market Entry'
                ]),
                'created_at' => Carbon::now()->subWeeks(2),
                'updated_at' => Carbon::now()->subDays(3),
            ],
            [
                'id' => Str::random(16),
                'merchant_id' => "merch_4", // assuming M004 is ID=4
                'name' => 'Emily White',
                'email' => 'emily.white@hopeforchildren.org.au',
                'password' => Hash::make('children2024'),
                'role' => 'admin',
                'status' => 'inactive',
                'permissions' => json_encode(['manage_payouts']),
                'last_login_at' => Carbon::now()->subMonth(),
                'phone' => '+61-2-5555-1234',
                'metadata' => json_encode([
                    'position' => 'Program Director',
                    'focus_area' => 'Education'
                ]),
                'created_at' => Carbon::now()->subMonths(2),
                'updated_at' => Carbon::now()->subWeek(),
            ]
        ];

        DB::table('merchant_users')->insert($merchantUsers);
    }
}
