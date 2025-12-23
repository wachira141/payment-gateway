<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            // Zero-decimal currencies
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0],
            ['code' => 'KRW', 'name' => 'South Korean Won', 'symbol' => '₩', 'decimals' => 0],
            ['code' => 'VND', 'name' => 'Vietnamese Dong', 'symbol' => '₫', 'decimals' => 0],
            ['code' => 'CLP', 'name' => 'Chilean Peso', 'symbol' => '$', 'decimals' => 0],
            ['code' => 'PYG', 'name' => 'Paraguayan Guarani', 'symbol' => '₲', 'decimals' => 0],
            ['code' => 'UGX', 'name' => 'Ugandan Shilling', 'symbol' => 'USh', 'decimals' => 0],
            ['code' => 'RWF', 'name' => 'Rwandan Franc', 'symbol' => 'FRw', 'decimals' => 0],
            ['code' => 'GNF', 'name' => 'Guinean Franc', 'symbol' => 'FG', 'decimals' => 0],
            ['code' => 'XOF', 'name' => 'West African CFA Franc', 'symbol' => 'CFA', 'decimals' => 0],
            ['code' => 'XAF', 'name' => 'Central African CFA Franc', 'symbol' => 'FCFA', 'decimals' => 0],
            ['code' => 'KMF', 'name' => 'Comorian Franc', 'symbol' => 'CF', 'decimals' => 0],
            ['code' => 'DJF', 'name' => 'Djiboutian Franc', 'symbol' => 'Fdj', 'decimals' => 0],
            ['code' => 'ISK', 'name' => 'Icelandic Króna', 'symbol' => 'kr', 'decimals' => 0],
            ['code' => 'HUF', 'name' => 'Hungarian Forint', 'symbol' => 'Ft', 'decimals' => 0],
            ['code' => 'TWD', 'name' => 'New Taiwan Dollar', 'symbol' => 'NT$', 'decimals' => 0],

            // Three-decimal currencies
            ['code' => 'BHD', 'name' => 'Bahraini Dinar', 'symbol' => '.د.ب', 'decimals' => 3],
            ['code' => 'JOD', 'name' => 'Jordanian Dinar', 'symbol' => 'JD', 'decimals' => 3],
            ['code' => 'KWD', 'name' => 'Kuwaiti Dinar', 'symbol' => 'KD', 'decimals' => 3],
            ['code' => 'OMR', 'name' => 'Omani Rial', 'symbol' => 'ر.ع.', 'decimals' => 3],
            ['code' => 'TND', 'name' => 'Tunisian Dinar', 'symbol' => 'DT', 'decimals' => 3],
            ['code' => 'IQD', 'name' => 'Iraqi Dinar', 'symbol' => 'ع.د', 'decimals' => 3],
            ['code' => 'LYD', 'name' => 'Libyan Dinar', 'symbol' => 'LD', 'decimals' => 3],

            // Two-decimal currencies (common)
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'decimals' => 2],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'decimals' => 2],
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'decimals' => 2],
            ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'decimals' => 2],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'decimals' => 2],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'decimals' => 2],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2],
            ['code' => 'MXN', 'name' => 'Mexican Peso', 'symbol' => '$', 'decimals' => 2],
            ['code' => 'BRL', 'name' => 'Brazilian Real', 'symbol' => 'R$', 'decimals' => 2],
            ['code' => 'ARS', 'name' => 'Argentine Peso', 'symbol' => '$', 'decimals' => 2],
            ['code' => 'COP', 'name' => 'Colombian Peso', 'symbol' => '$', 'decimals' => 2],
            ['code' => 'PEN', 'name' => 'Peruvian Sol', 'symbol' => 'S/', 'decimals' => 2],

            // African currencies (two-decimal)
            ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'decimals' => 2],
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'decimals' => 2],
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦', 'decimals' => 2],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'decimals' => 2],
            ['code' => 'GHS', 'name' => 'Ghanaian Cedi', 'symbol' => 'GH₵', 'decimals' => 2],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£', 'decimals' => 2],
            ['code' => 'MAD', 'name' => 'Moroccan Dirham', 'symbol' => 'DH', 'decimals' => 2],
            ['code' => 'ETB', 'name' => 'Ethiopian Birr', 'symbol' => 'Br', 'decimals' => 2],
            ['code' => 'ZMW', 'name' => 'Zambian Kwacha', 'symbol' => 'ZK', 'decimals' => 2],
            ['code' => 'BWP', 'name' => 'Botswana Pula', 'symbol' => 'P', 'decimals' => 2],
            ['code' => 'MUR', 'name' => 'Mauritian Rupee', 'symbol' => '₨', 'decimals' => 2],
            ['code' => 'SCR', 'name' => 'Seychellois Rupee', 'symbol' => '₨', 'decimals' => 2],

            // Middle East currencies (two-decimal)
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'د.إ', 'decimals' => 2],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => '﷼', 'decimals' => 2],
            ['code' => 'QAR', 'name' => 'Qatari Riyal', 'symbol' => 'QR', 'decimals' => 2],
            ['code' => 'ILS', 'name' => 'Israeli Shekel', 'symbol' => '₪', 'decimals' => 2],
            ['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺', 'decimals' => 2],

            // Asian currencies (two-decimal)
            ['code' => 'PHP', 'name' => 'Philippine Peso', 'symbol' => '₱', 'decimals' => 2],
            ['code' => 'THB', 'name' => 'Thai Baht', 'symbol' => '฿', 'decimals' => 2],
            ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'symbol' => 'RM', 'decimals' => 2],
            ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'decimals' => 2],
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => '₨', 'decimals' => 2],
            ['code' => 'BDT', 'name' => 'Bangladeshi Taka', 'symbol' => '৳', 'decimals' => 2],
            ['code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs', 'decimals' => 2],
            ['code' => 'NPR', 'name' => 'Nepalese Rupee', 'symbol' => '₨', 'decimals' => 2],
            ['code' => 'MMK', 'name' => 'Myanmar Kyat', 'symbol' => 'K', 'decimals' => 2],

            // European currencies (two-decimal)
            ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'kr', 'decimals' => 2],
            ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'kr', 'decimals' => 2],
            ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'kr', 'decimals' => 2],
            ['code' => 'PLN', 'name' => 'Polish Złoty', 'symbol' => 'zł', 'decimals' => 2],
            ['code' => 'CZK', 'name' => 'Czech Koruna', 'symbol' => 'Kč', 'decimals' => 2],
            ['code' => 'RON', 'name' => 'Romanian Leu', 'symbol' => 'lei', 'decimals' => 2],
            ['code' => 'BGN', 'name' => 'Bulgarian Lev', 'symbol' => 'лв', 'decimals' => 2],
            ['code' => 'HRK', 'name' => 'Croatian Kuna', 'symbol' => 'kn', 'decimals' => 2],
            ['code' => 'RSD', 'name' => 'Serbian Dinar', 'symbol' => 'din', 'decimals' => 2],
            ['code' => 'UAH', 'name' => 'Ukrainian Hryvnia', 'symbol' => '₴', 'decimals' => 2],
            ['code' => 'RUB', 'name' => 'Russian Ruble', 'symbol' => '₽', 'decimals' => 2],

            // Other currencies (two-decimal)
            ['code' => 'JMD', 'name' => 'Jamaican Dollar', 'symbol' => 'J$', 'decimals' => 2],
            ['code' => 'TTD', 'name' => 'Trinidad and Tobago Dollar', 'symbol' => 'TT$', 'decimals' => 2],
            ['code' => 'BBD', 'name' => 'Barbadian Dollar', 'symbol' => 'Bds$', 'decimals' => 2],
            ['code' => 'FJD', 'name' => 'Fijian Dollar', 'symbol' => 'FJ$', 'decimals' => 2],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                array_merge($currency, ['is_active' => true])
            );
        }
    }
}