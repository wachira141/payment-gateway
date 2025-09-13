<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Language;

class LanguagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $languages = [
            // International Languages
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'type' => 'international'],
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español', 'type' => 'international'],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'type' => 'international'],
            ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'type' => 'international'],
            ['code' => 'pt', 'name' => 'Portuguese', 'native_name' => 'Português', 'type' => 'international'],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'type' => 'international'],
            ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano', 'type' => 'international'],
            ['code' => 'ru', 'name' => 'Russian', 'native_name' => 'Русский', 'type' => 'international'],
            ['code' => 'zh', 'name' => 'Chinese (Mandarin)', 'native_name' => '中文', 'type' => 'international'],
            ['code' => 'ja', 'name' => 'Japanese', 'native_name' => '日本語', 'type' => 'international'],
            ['code' => 'ko', 'name' => 'Korean', 'native_name' => '한국어', 'type' => 'international'],
            ['code' => 'hi', 'name' => 'Hindi', 'native_name' => 'हिन्दी', 'type' => 'international'],
            ['code' => 'ur', 'name' => 'Urdu', 'native_name' => 'اردو', 'type' => 'international'],
            ['code' => 'fa', 'name' => 'Persian', 'native_name' => 'فارسی', 'type' => 'international'],
            ['code' => 'tr', 'name' => 'Turkish', 'native_name' => 'Türkçe', 'type' => 'international'],
            ['code' => 'pl', 'name' => 'Polish', 'native_name' => 'Polski', 'type' => 'international'],
            ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'type' => 'international'],
            ['code' => 'sv', 'name' => 'Swedish', 'native_name' => 'Svenska', 'type' => 'international'],
            ['code' => 'da', 'name' => 'Danish', 'native_name' => 'Dansk', 'type' => 'international'],
            ['code' => 'no', 'name' => 'Norwegian', 'native_name' => 'Norsk', 'type' => 'international'],

            // Kenyan Local Languages
            ['code' => 'sw', 'name' => 'Swahili', 'native_name' => 'Kiswahili', 'type' => 'kenyan_local'],
            ['code' => 'ki', 'name' => 'Kikuyu', 'native_name' => 'Gĩkũyũ', 'type' => 'kenyan_local'],
            ['code' => 'luy', 'name' => 'Luhya', 'native_name' => 'Luluhya', 'type' => 'kenyan_local'],
            ['code' => 'luo', 'name' => 'Luo', 'native_name' => 'Dholuo', 'type' => 'kenyan_local'],
            ['code' => 'kam', 'name' => 'Kamba', 'native_name' => 'Kikamba', 'type' => 'kenyan_local'],
            ['code' => 'guz', 'name' => 'Kisii', 'native_name' => 'Ekegusii', 'type' => 'kenyan_local'],
            ['code' => 'mer', 'name' => 'Meru', 'native_name' => 'Kimeru', 'type' => 'kenyan_local'],
            ['code' => 'emb', 'name' => 'Embu', 'native_name' => 'Kĩembu', 'type' => 'kenyan_local'],
            ['code' => 'mjk', 'name' => 'Mijikenda', 'native_name' => 'Kimijikenda', 'type' => 'kenyan_local'],
            ['code' => 'tuv', 'name' => 'Turkana', 'native_name' => 'Ng\'aturkana', 'type' => 'kenyan_local'],
            ['code' => 'mas', 'name' => 'Maasai', 'native_name' => 'Maa', 'type' => 'kenyan_local'],
            ['code' => 'saq', 'name' => 'Samburu', 'native_name' => 'Samburu', 'type' => 'kenyan_local'],
            ['code' => 'so', 'name' => 'Somali', 'native_name' => 'Soomaaliga', 'type' => 'kenyan_local'],
            ['code' => 'om', 'name' => 'Oromo', 'native_name' => 'Afaan Oromoo', 'type' => 'kenyan_local'],
            ['code' => 'kln', 'name' => 'Kalenjin', 'native_name' => 'Kalenjin', 'type' => 'kenyan_local'],
            ['code' => 'tuj', 'name' => 'Tuju', 'native_name' => 'Kituju', 'type' => 'kenyan_local'],
        ];

        foreach ($languages as $language) {
            // add id
            $language['id'] = \Illuminate\Support\Str::uuid()->toString();
            Language::create($language);
        }
    }
}
