<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SupportedBank;

class SupportedBanksSeeder extends Seeder
{
    public function run(): void
    {
        $banks = [
            // Kenya Banks
            [
                'country_code' => 'KE',
                'bank_code' => 'KCB_KE',
                'bank_name' => 'Kenya Commercial Bank',
                'swift_code' => 'KCBLKENX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'KE',
                'bank_code' => 'EQUITY_KE',
                'bank_name' => 'Equity Bank Kenya',
                'swift_code' => 'EQBLKENX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'KE',
                'bank_code' => 'COOP_KE',
                'bank_name' => 'Co-operative Bank of Kenya',
                'swift_code' => 'COOPKENX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'KE',
                'bank_code' => 'ABSA_KE',
                'bank_name' => 'Absa Bank Kenya',
                'swift_code' => 'BARCKENX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'KE',
                'bank_code' => 'STANCHART_KE',
                'bank_name' => 'Standard Chartered Bank Kenya',
                'swift_code' => 'SCBLKENX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],

            // Uganda Banks
            [
                'country_code' => 'UG',
                'bank_code' => 'STANBIC_UG',
                'bank_name' => 'Stanbic Bank Uganda',
                'swift_code' => 'SBICUGKX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'UG',
                'bank_code' => 'CENTENARY_UG',
                'bank_name' => 'Centenary Bank',
                'swift_code' => 'CENAUGKX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'UG',
                'bank_code' => 'DFCU_UG',
                'bank_name' => 'DFCU Bank',
                'swift_code' => 'DFCUUGKX',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],

            // Nigeria Banks
            [
                'country_code' => 'NG',
                'bank_code' => 'ACCESS_NG',
                'bank_name' => 'Access Bank',
                'swift_code' => 'ABNGNGLA',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'NG',
                'bank_code' => 'GTB_NG',
                'bank_name' => 'Guaranty Trust Bank',
                'swift_code' => 'GTBINGLA',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'NG',
                'bank_code' => 'ZENITH_NG',
                'bank_name' => 'Zenith Bank',
                'swift_code' => 'ZEIBNGLA',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'NG',
                'bank_code' => 'UBA_NG',
                'bank_name' => 'United Bank for Africa',
                'swift_code' => 'UNAFNGLA',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],

            // Ghana Banks
            [
                'country_code' => 'GH',
                'bank_code' => 'GCB_GH',
                'bank_name' => 'GCB Bank Limited',
                'swift_code' => 'GHCBGHAC',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
            [
                'country_code' => 'GH',
                'bank_code' => 'ECOBANK_GH',
                'bank_name' => 'Ecobank Ghana',
                'swift_code' => 'ECOCGHAC',
                'bank_type' => 'commercial',
                'is_active' => true,
            ],
        ];

        foreach ($banks as $bank) {
            SupportedBank::updateOrCreate(
                // UNIQUE KEY
                [
                    'country_code' => $bank['country_code'],
                    'bank_code'    => $bank['bank_code'],
                ],
                // FIELDS TO UPDATE
                [
                    'bank_name'  => $bank['bank_name'],
                    'swift_code' => $bank['swift_code'],
                    'bank_type'  => $bank['bank_type'],
                    'is_active'  => $bank['is_active'],
                ]
            );
        }
    }
}
