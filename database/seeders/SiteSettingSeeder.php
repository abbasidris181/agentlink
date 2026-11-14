<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SiteSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['site_name' => config('app.name'), 'support_email' => '', 'support_phone' => '', 'whatsapp_number' => config('services.whatsapp.number', ''), 'whatsapp_url' => '', 'announcement' => ''] as $key => $value) {
            SiteSetting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
