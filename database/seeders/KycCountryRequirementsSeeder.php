<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\KycCountryRequirement;
use Illuminate\Support\Str;

class KycCountryRequirementsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $requirements = [
            // Kenya
            [
                'country_code' => 'KE',
                'tier_level' => 1,
                'tier_name' => 'Starter',
                'required_documents' => ['national_id', 'selfie'],
                'optional_documents' => [],
                'required_fields' => ['phone', 'email', 'full_name', 'date_of_birth'],
                'daily_limit' => 100.00,
                'monthly_limit' => 2000.00,
                'single_transaction_limit' => 50.00,
                'limit_currency' => 'USD',
                'description' => 'For individual micro-SMEs. Quick verification with basic ID.',
                'tier_benefits' => ['Accept mobile money payments', 'Basic dashboard access', 'Email support'],
            ],
            [
                'country_code' => 'KE',
                'tier_level' => 2,
                'tier_name' => 'Growth',
                'required_documents' => ['national_id', 'selfie', 'business_registration', 'kra_pin'],
                'optional_documents' => ['bank_statement'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth'],
                'daily_limit' => 2000.00,
                'monthly_limit' => 50000.00,
                'single_transaction_limit' => 1000.00,
                'limit_currency' => 'USD',
                'description' => 'For registered SMEs with valid business documents.',
                'tier_benefits' => ['Higher transaction limits', 'API access', 'Priority support', 'Bulk payouts'],
            ],
            [
                'country_code' => 'KE',
                'tier_level' => 3,
                'tier_name' => 'Enterprise',
                'required_documents' => ['national_id', 'selfie', 'business_registration', 'kra_pin', 'bank_statement', 'ubo_declaration'],
                'optional_documents' => ['aml_policy'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth', 'beneficial_owners'],
                'daily_limit' => 50000.00,
                'monthly_limit' => 500000.00,
                'single_transaction_limit' => 25000.00,
                'limit_currency' => 'USD',
                'description' => 'For large corporations with full compliance documentation.',
                'tier_benefits' => ['Unlimited transactions', 'Dedicated account manager', 'Custom integrations', 'Multi-currency wallets', 'FX hedging'],
            ],

            // Uganda
            [
                'country_code' => 'UG',
                'tier_level' => 1,
                'tier_name' => 'Starter',
                'required_documents' => ['national_id', 'selfie'],
                'optional_documents' => [],
                'required_fields' => ['phone', 'email', 'full_name', 'date_of_birth'],
                'daily_limit' => 100.00,
                'monthly_limit' => 2000.00,
                'single_transaction_limit' => 50.00,
                'limit_currency' => 'USD',
                'description' => 'For individual micro-SMEs in Uganda.',
                'tier_benefits' => ['Accept MTN Mobile Money', 'Basic dashboard access', 'Email support'],
            ],
            [
                'country_code' => 'UG',
                'tier_level' => 2,
                'tier_name' => 'Growth',
                'required_documents' => ['national_id', 'selfie', 'business_registration', 'tin_certificate'],
                'optional_documents' => ['bank_statement'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth'],
                'daily_limit' => 2000.00,
                'monthly_limit' => 50000.00,
                'single_transaction_limit' => 1000.00,
                'limit_currency' => 'USD',
                'description' => 'For registered SMEs in Uganda.',
                'tier_benefits' => ['Higher transaction limits', 'API access', 'Priority support', 'Bulk payouts'],
            ],
            [
                'country_code' => 'UG',
                'tier_level' => 3,
                'tier_name' => 'Enterprise',
                'required_documents' => ['national_id', 'selfie', 'business_registration', 'tin_certificate', 'bank_statement', 'ubo_declaration'],
                'optional_documents' => ['aml_policy'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth', 'beneficial_owners'],
                'daily_limit' => 50000.00,
                'monthly_limit' => 500000.00,
                'single_transaction_limit' => 25000.00,
                'limit_currency' => 'USD',
                'description' => 'For large corporations in Uganda.',
                'tier_benefits' => ['Unlimited transactions', 'Dedicated account manager', 'Custom integrations'],
            ],

            // Rwanda
            [
                'country_code' => 'RW',
                'tier_level' => 1,
                'tier_name' => 'Starter',
                'required_documents' => ['national_id', 'selfie'],
                'optional_documents' => [],
                'required_fields' => ['phone', 'email', 'full_name', 'date_of_birth'],
                'daily_limit' => 100.00,
                'monthly_limit' => 2000.00,
                'single_transaction_limit' => 50.00,
                'limit_currency' => 'USD',
                'description' => 'For individual micro-SMEs in Rwanda.',
                'tier_benefits' => ['Accept MTN Mobile Money', 'Basic dashboard access', 'Email support'],
            ],
            [
                'country_code' => 'RW',
                'tier_level' => 2,
                'tier_name' => 'Growth',
                'required_documents' => ['national_id', 'selfie', 'business_registration', 'rra_tin'],
                'optional_documents' => ['bank_statement'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth'],
                'daily_limit' => 2000.00,
                'monthly_limit' => 50000.00,
                'single_transaction_limit' => 1000.00,
                'limit_currency' => 'USD',
                'description' => 'For registered SMEs in Rwanda.',
                'tier_benefits' => ['Higher transaction limits', 'API access', 'Priority support', 'Bulk payouts'],
            ],
            [
                'country_code' => 'RW',
                'tier_level' => 3,
                'tier_name' => 'Enterprise',
                'required_documents' => ['national_id', 'selfie', 'business_registration', 'rra_tin', 'bank_statement', 'ubo_declaration'],
                'optional_documents' => ['aml_policy'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth', 'beneficial_owners'],
                'daily_limit' => 50000.00,
                'monthly_limit' => 500000.00,
                'single_transaction_limit' => 25000.00,
                'limit_currency' => 'USD',
                'description' => 'For large corporations in Rwanda.',
                'tier_benefits' => ['Unlimited transactions', 'Dedicated account manager', 'Custom integrations'],
            ],

            // Zambia
            [
                'country_code' => 'ZM',
                'tier_level' => 1,
                'tier_name' => 'Starter',
                'required_documents' => ['nrc', 'selfie'],
                'optional_documents' => [],
                'required_fields' => ['phone', 'email', 'full_name', 'date_of_birth'],
                'daily_limit' => 100.00,
                'monthly_limit' => 2000.00,
                'single_transaction_limit' => 50.00,
                'limit_currency' => 'USD',
                'description' => 'For individual micro-SMEs in Zambia.',
                'tier_benefits' => ['Accept MTN Mobile Money', 'Accept Airtel Money', 'Basic dashboard access'],
            ],
            [
                'country_code' => 'ZM',
                'tier_level' => 2,
                'tier_name' => 'Growth',
                'required_documents' => ['nrc', 'selfie', 'pacra_certificate', 'tpin_certificate'],
                'optional_documents' => ['bank_statement'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth'],
                'daily_limit' => 2000.00,
                'monthly_limit' => 50000.00,
                'single_transaction_limit' => 1000.00,
                'limit_currency' => 'USD',
                'description' => 'For registered SMEs in Zambia.',
                'tier_benefits' => ['Higher transaction limits', 'API access', 'Priority support', 'Bulk payouts'],
            ],
            [
                'country_code' => 'ZM',
                'tier_level' => 3,
                'tier_name' => 'Enterprise',
                'required_documents' => ['nrc', 'selfie', 'pacra_certificate', 'tpin_certificate', 'bank_statement', 'ubo_declaration'],
                'optional_documents' => ['aml_policy'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth', 'beneficial_owners'],
                'daily_limit' => 50000.00,
                'monthly_limit' => 500000.00,
                'single_transaction_limit' => 25000.00,
                'limit_currency' => 'USD',
                'description' => 'For large corporations in Zambia.',
                'tier_benefits' => ['Unlimited transactions', 'Dedicated account manager', 'Custom integrations'],
            ],

            // Nigeria (bonus - common market)
            [
                'country_code' => 'NG',
                'tier_level' => 1,
                'tier_name' => 'Starter',
                'required_documents' => ['nin', 'selfie'],
                'optional_documents' => ['bvn'],
                'required_fields' => ['phone', 'email', 'full_name', 'date_of_birth'],
                'daily_limit' => 100.00,
                'monthly_limit' => 2000.00,
                'single_transaction_limit' => 50.00,
                'limit_currency' => 'USD',
                'description' => 'For individual micro-SMEs in Nigeria.',
                'tier_benefits' => ['Accept bank transfers', 'Basic dashboard access', 'Email support'],
            ],
            [
                'country_code' => 'NG',
                'tier_level' => 2,
                'tier_name' => 'Growth',
                'required_documents' => ['nin', 'selfie', 'cac_certificate', 'tin_certificate'],
                'optional_documents' => ['bank_statement', 'bvn'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth'],
                'daily_limit' => 2000.00,
                'monthly_limit' => 50000.00,
                'single_transaction_limit' => 1000.00,
                'limit_currency' => 'USD',
                'description' => 'For registered SMEs in Nigeria.',
                'tier_benefits' => ['Higher transaction limits', 'API access', 'Priority support', 'Bulk payouts'],
            ],
            [
                'country_code' => 'NG',
                'tier_level' => 3,
                'tier_name' => 'Enterprise',
                'required_documents' => ['nin', 'selfie', 'cac_certificate', 'tin_certificate', 'bank_statement', 'ubo_declaration'],
                'optional_documents' => ['aml_policy', 'bvn'],
                'required_fields' => ['phone', 'email', 'full_name', 'business_name', 'business_address', 'date_of_birth', 'beneficial_owners'],
                'daily_limit' => 50000.00,
                'monthly_limit' => 500000.00,
                'single_transaction_limit' => 25000.00,
                'limit_currency' => 'USD',
                'description' => 'For large corporations in Nigeria.',
                'tier_benefits' => ['Unlimited transactions', 'Dedicated account manager', 'Custom integrations'],
            ],
        ];

        foreach ($requirements as $requirement) {
            KycCountryRequirement::updateOrCreate(
                [
                    'country_code' => $requirement['country_code'],
                    'tier_level' => $requirement['tier_level'],
                ],
                $requirement
            );
        }
    }
}
