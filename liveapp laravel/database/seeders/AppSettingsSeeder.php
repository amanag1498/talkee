<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Services\AppSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class AppSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AppSettingsService::APP_DEFINITIONS as $key => $definition) {
            $default = $definition['default'] ?? null;
            if (is_bool($default)) {
                $default = $default ? '1' : '0';
            } elseif ($default !== null) {
                $default = (string) $default;
            }

            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $default]
            );
        }

        Cache::forget('app_settings:all:v1');
        Cache::forget('app_config:public:v2');
    }
}
