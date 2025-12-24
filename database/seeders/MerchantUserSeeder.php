<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class MerchantUserSeeder extends Seeder
{
    public function run(): void
    {
        // Map display_name → users
        $merchantUsers = [
            'Tech Solutions' => [
                [
                    'name' => 'John Doe',
                    'email' => 'john.doe@techsolutions.com',
                    'role' => 'owner',
                    'status' => 'active',
                    'permissions' => ['manage_apps', 'view_payments', 'manage_payouts'],
                    'phone' => '+1-555-123-4567',
                    'metadata' => [
                        'position' => 'CEO',
                        'department' => 'Management',
                    ],
                    'last_login_at' => Carbon::now()->subDays(2),
                ],
            ],

            'Global Retail' => [
                [
                    'name' => 'Sarah Green',
                    'email' => 'sarah.green@greenearthorganics.ca',
                    'role' => 'admin',
                    'status' => 'active',
                    'permissions' => ['manage_apps', 'view_payments'],
                    'phone' => '+1-416-555-7890',
                    'metadata' => [
                        'position' => 'Operations Manager',
                        'department' => 'Logistics',
                    ],
                    'last_login_at' => Carbon::now()->subWeek(),
                ],
            ],

            'Fresh Foods' => [
                [
                    'name' => 'Michael Brown',
                    'email' => 'michael.brown@globalpartners.co.uk',
                    'role' => 'finance',
                    'status' => 'pending',
                    'permissions' => ['view_payments'],
                    'phone' => '+44-20-5555-6789',
                    'metadata' => [
                        'position' => 'Consultant',
                        'specialization' => 'Market Entry',
                    ],
                    'last_login_at' => null,
                ],
            ],

            'Fashion Trends' => [
                [
                    'name' => 'Emily White',
                    'email' => 'emily.white@hopeforchildren.org.au',
                    'role' => 'admin',
                    'status' => 'inactive',
                    'permissions' => ['manage_payouts'],
                    'phone' => '+61-2-5555-1234',
                    'metadata' => [
                        'position' => 'Program Director',
                        'focus_area' => 'Education',
                    ],
                    'last_login_at' => Carbon::now()->subMonth(),
                ],
            ],
        ];

        foreach ($merchantUsers as $merchantName => $users) {
            $merchant = DB::table('merchants')
                ->where('display_name', $merchantName)
                ->first();

            if (!$merchant) {
                $this->command->warn("Merchant not found: {$merchantName}");
                continue;
            }

            foreach ($users as $user) {
                DB::table('merchant_users')->updateOrInsert(
                    // UNIQUE KEY
                    ['email' => $user['email']],

                    // VALUES TO UPDATE / INSERT
                    [
                        'merchant_id'   => $merchant->id,
                        'name'          => $user['name'],
                        'password'      => Hash::make('password123'),
                        'role'          => $user['role'],
                        'status'        => $user['status'],
                        'permissions'   => json_encode($user['permissions']),
                        'phone'         => $user['phone'],
                        'metadata'      => json_encode($user['metadata']),
                        'last_login_at' => $user['last_login_at'],
                        'updated_at'    => now(),
                        'created_at'    => now(),
                    ]
                );
            }
        }
    }
}
