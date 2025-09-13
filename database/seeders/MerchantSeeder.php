<?php

namespace Database\Seeders;

use App\Models\Merchant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MerchantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $merchants = [
            [
                'merchant_id' => 'merch_' . Str::random(16),
                'legal_name' => 'Tech Solutions Inc.',
                'display_name' => 'Tech Solutions',
                'business_type' => 'technology',
                'country_code' => 'USA',
                'default_currency' => 'USD',
                'status' => 'active',
                'compliance_status' => 'approved',
                'website' => 'https://techsolutions.example.com',
                'business_description' => 'Providing innovative technology solutions for businesses worldwide.',
                'business_address' => [
                    'street' => '123 Tech Avenue',
                    'city' => 'San Francisco',
                    'state' => 'CA',
                    'postal_code' => '94105',
                    'country' => 'United States'
                ],
                'tax_id' => 'TAX-123456789',
                'registration_number' => 'REG-987654321',
                'metadata' => [
                    'industry' => 'Technology',
                    'founded_year' => 2018,
                    'employee_count' => 50
                ],
                'approved_at' => now()->subMonths(6),
            ],
            [
                'merchant_id' => 'merch_' . Str::random(16),
                'legal_name' => 'Global Retailers Ltd.',
                'display_name' => 'Global Retail',
                'business_type' => 'retail',
                'country_code' => 'GBR',
                'default_currency' => 'GBP',
                'status' => 'active',
                'compliance_status' => 'approved',
                'website' => 'https://globalretail.example.com',
                'business_description' => 'International retail chain offering quality products.',
                'business_address' => [
                    'street' => '456 Oxford Street',
                    'city' => 'London',
                    'state' => 'England',
                    'postal_code' => 'W1D 1BS',
                    'country' => 'United Kingdom'
                ],
                'tax_id' => 'GB-TAX-987654',
                'registration_number' => 'UK-COMP-123456',
                'metadata' => [
                    'industry' => 'Retail',
                    'founded_year' => 2015,
                    'store_count' => 25
                ],
                'approved_at' => now()->subMonths(3),
            ],
            [
                'merchant_id' => 'merch_' . Str::random(16),
                'legal_name' => 'Fresh Foods GmbH',
                'display_name' => 'Fresh Foods',
                'business_type' => 'food',
                'country_code' => 'DEU',
                'default_currency' => 'EUR',
                'status' => 'pending',
                'compliance_status' => 'under_review',
                'website' => 'https://freshfoods.example.com',
                'business_description' => 'Organic and fresh food delivery service.',
                'business_address' => [
                    'street' => '789 Berliner Strasse',
                    'city' => 'Berlin',
                    'state' => 'Berlin',
                    'postal_code' => '10115',
                    'country' => 'Germany'
                ],
                'tax_id' => 'DE-123456789',
                'registration_number' => 'HRB-12345',
                'metadata' => [
                    'industry' => 'Food & Beverage',
                    'founded_year' => 2020,
                    'delivery_areas' => ['Berlin', 'Hamburg', 'Munich']
                ],
            ],
            [
                'merchant_id' => 'merch_' . Str::random(16),
                'legal_name' => 'Fashion Trends SAS',
                'display_name' => 'Fashion Trends',
                'business_type' => 'fashion',
                'country_code' => 'FRA',
                'default_currency' => 'EUR',
                'status' => 'suspended',
                'compliance_status' => 'rejected',
                'website' => 'https://fashiontrends.example.com',
                'business_description' => 'Latest fashion trends and clothing.',
                'business_address' => [
                    'street' => '321 Rue de la Mode',
                    'city' => 'Paris',
                    'state' => 'Île-de-France',
                    'postal_code' => '75008',
                    'country' => 'France'
                ],
                'tax_id' => 'FR-987654321',
                'registration_number' => 'RCS-PARIS-987654',
                'metadata' => [
                    'industry' => 'Fashion',
                    'founded_year' => 2019,
                    'product_categories' => ['Clothing', 'Accessories', 'Shoes']
                ],
                'suspended_at' => now()->subWeek(),
            ]
        ];

        $id = 0;
        foreach ($merchants as $merchantData) {
            $id++;
            $merchantData['id'] = 'merch_'. $id; // Simple incremental UUID for demo purposes;
            Merchant::createWithDefaults($merchantData);
        }
    }
}