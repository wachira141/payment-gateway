<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SupportedPayoutMethod;

class SupportedPayoutMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $payoutMethods = [
            // Kenya - KES
            [
                'country_code' => 'KE',
                'currency' => 'KES',
                'method_type' => 'bank_transfer',
                'method_name' => 'Bank Transfer',
                'is_active' => true,
                'min_amount' => 10.00,
                'max_amount' => 1000000.00,
                'processing_time_hours' => 24,
                'configuration' => [
                    'supports_instant' => false,
                    'required_fields' => [
                        'account_number' => [
                            'type' => 'text',
                            'label' => 'Account Number',
                            'placeholder' => 'Enter bank account number',
                            'validation' => 'required|string|min:8|max:20',
                            'help_text' => 'Enter the bank account number'
                        ],
                        'bank_code' => [
                            'type' => 'bank_select',
                            'label' => 'Bank',
                            'placeholder' => 'Select bank',
                            'validation' => 'required|string',
                            'help_text' => 'Select the recipient bank'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Holder Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as per bank records'
                        ]
                    ]
                ]
            ],
            [
                'country_code' => 'KE',
                'currency' => 'KES',
                'method_type' => 'mobile_money',
                'method_name' => 'M-Pesa',
                'is_active' => true,
                'min_amount' => 1.00,
                'max_amount' => 300000.00,
                'processing_time_hours' => 1,
                'configuration' => [
                    'supports_instant' => true,
                    'provider' => 'mpesa',
                    'required_fields' => [
                        'mobile_number' => [
                            'type' => 'phone',
                            'label' => 'M-Pesa Number',
                            'placeholder' => '+254700000000',
                            'validation' => 'required|phone',
                            'help_text' => 'Enter M-Pesa registered mobile number'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as registered on M-Pesa'
                        ]
                    ]
                ]
            ],

            // Uganda - UGX
            [
                'country_code' => 'UG',
                'currency' => 'UGX',
                'method_type' => 'bank_transfer',
                'method_name' => 'Bank Transfer',
                'is_active' => true,
                'min_amount' => 1000.00,
                'max_amount' => 10000000.00,
                'processing_time_hours' => 48,
                'configuration' => [
                    'supports_instant' => false,
                    'required_fields' => [
                        'account_number' => [
                            'type' => 'text',
                            'label' => 'Account Number',
                            'placeholder' => 'Enter bank account number',
                            'validation' => 'required|string|min:8|max:20',
                            'help_text' => 'Enter the bank account number'
                        ],
                        'bank_code' => [
                            'type' => 'bank_select',
                            'label' => 'Bank',
                            'placeholder' => 'Select bank',
                            'validation' => 'required|string',
                            'help_text' => 'Select the recipient bank'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Holder Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as per bank records'
                        ]
                    ]
                ]
            ],
            [
                'country_code' => 'UG',
                'currency' => 'UGX',
                'method_type' => 'mobile_money',
                'method_name' => 'Mobile Money (MTN/Airtel)',
                'is_active' => true,
                'min_amount' => 500.00,
                'max_amount' => 5000000.00,
                'processing_time_hours' => 2,
                'configuration' => [
                    'supports_instant' => true,
                    'provider' => 'mtn_airtel',
                    'required_fields' => [
                        'mobile_number' => [
                            'type' => 'phone',
                            'label' => 'Mobile Number',
                            'placeholder' => '+256700000000',
                            'validation' => 'required|phone',
                            'help_text' => 'Enter MTN or Airtel mobile money number'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as registered on mobile money'
                        ]
                    ]
                ]
            ],

            // Nigeria - NGN
            [
                'country_code' => 'NG',
                'currency' => 'NGN',
                'method_type' => 'bank_transfer',
                'method_name' => 'Bank Transfer',
                'is_active' => true,
                'min_amount' => 100.00,
                'max_amount' => 50000000.00,
                'processing_time_hours' => 12,
                'configuration' => [
                    'supports_instant' => true,
                    'required_fields' => [
                        'account_number' => [
                            'type' => 'text',
                            'label' => 'Account Number',
                            'placeholder' => 'Enter 10-digit account number',
                            'validation' => 'required|string|size:10',
                            'help_text' => 'Enter the 10-digit bank account number'
                        ],
                        'bank_code' => [
                            'type' => 'bank_select',
                            'label' => 'Bank',
                            'placeholder' => 'Select bank',
                            'validation' => 'required|string',
                            'help_text' => 'Select the recipient bank'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Holder Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as per bank records'
                        ]
                    ]
                ]
            ],

            // Ghana - GHS
            [
                'country_code' => 'GH',
                'currency' => 'GHS',
                'method_type' => 'bank_transfer',
                'method_name' => 'Bank Transfer',
                'is_active' => true,
                'min_amount' => 5.00,
                'max_amount' => 100000.00,
                'processing_time_hours' => 24,
                'configuration' => [
                    'supports_instant' => false,
                    'required_fields' => [
                        'account_number' => [
                            'type' => 'text',
                            'label' => 'Account Number',
                            'placeholder' => 'Enter bank account number',
                            'validation' => 'required|string|min:8|max:20',
                            'help_text' => 'Enter the bank account number'
                        ],
                        'bank_code' => [
                            'type' => 'bank_select',
                            'label' => 'Bank',
                            'placeholder' => 'Select bank',
                            'validation' => 'required|string',
                            'help_text' => 'Select the recipient bank'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Holder Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as per bank records'
                        ]
                    ]
                ]
            ],
            [
                'country_code' => 'GH',
                'currency' => 'GHS',
                'method_type' => 'mobile_money',
                'method_name' => 'Mobile Money',
                'is_active' => true,
                'min_amount' => 1.00,
                'max_amount' => 10000.00,
                'processing_time_hours' => 1,
                'configuration' => [
                    'supports_instant' => true,
                    'provider' => 'momo',
                    'required_fields' => [
                        'mobile_number' => [
                            'type' => 'phone',
                            'label' => 'Mobile Number',
                            'placeholder' => '+233200000000',
                            'validation' => 'required|phone',
                            'help_text' => 'Enter mobile money registered number'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as registered on mobile money'
                        ]
                    ]
                ]
            ],

            // International methods (USD)
            [
                'country_code' => 'US',
                'currency' => 'USD',
                'method_type' => 'international_wire',
                'method_name' => 'International Wire Transfer',
                'is_active' => true,
                'min_amount' => 50.00,
                'max_amount' => 1000000.00,
                'processing_time_hours' => 72,
                'configuration' => [
                    'supports_instant' => false,
                    'requires_swift' => true,
                    'required_fields' => [
                        'account_number' => [
                            'type' => 'text',
                            'label' => 'Account Number/IBAN',
                            'placeholder' => 'Enter account number or IBAN',
                            'validation' => 'required|string|min:8',
                            'help_text' => 'Enter the international account number or IBAN'
                        ],
                        'swift_code' => [
                            'type' => 'text',
                            'label' => 'SWIFT Code',
                            'placeholder' => 'Enter SWIFT/BIC code',
                            'validation' => 'required|string|min:8|max:11',
                            'help_text' => 'Enter the bank SWIFT/BIC code'
                        ],
                        'bank_name' => [
                            'type' => 'text',
                            'label' => 'Bank Name',
                            'placeholder' => 'Enter bank name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name of the recipient bank'
                        ],
                        'account_name' => [
                            'type' => 'text',
                            'label' => 'Account Holder Name',
                            'placeholder' => 'Enter account holder name',
                            'validation' => 'required|string|min:2',
                            'help_text' => 'Full name as per bank records'
                        ],
                        'bank_address' => [
                            'type' => 'textarea',
                            'label' => 'Bank Address',
                            'placeholder' => 'Enter bank address',
                            'validation' => 'required|string|min:10',
                            'help_text' => 'Full address of the recipient bank'
                        ]
                    ]
                ]
            ],
        ];

        foreach ($payoutMethods as $method) {
            SupportedPayoutMethod::create($method);
        }
    }
}