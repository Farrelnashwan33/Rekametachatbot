<?php

namespace Database\Seeders;

use App\Models\AiProviderSetting;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = env('ADMIN_SEED_EMAIL', 'admin@rekametachat.local');
        $adminPassword = env('ADMIN_SEED_PASSWORD', 'AdminReka2026!');

        $admin = User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Administrator Rekametachat',
                'password' => Hash::make($adminPassword),
                'role' => 'admin',
                'bio' => 'Akun administrator sistem Rekametachat.',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        UserPreference::firstOrCreate(
            ['user_id' => $admin->id],
            [
                'theme' => 'system',
                'language' => 'id',
                'communication_style' => 'balanced',
                'memory_enabled' => true,
                'memory_auto_save' => false,
                'voice_enabled' => true,
            ]
        );

        $defaultProvider = config('rekametachat.default_provider', 'openai');
        $providers = config('rekametachat.providers', []);
        $defaultDailyLimit = (int) config('rekametachat.default_daily_token_limit', 250000);
        $defaultRateLimit = (int) config('rekametachat.chat_rate_limit_per_minute', 30);

        foreach ($providers as $key => $cfg) {
            AiProviderSetting::updateOrCreate(
                ['provider_key' => $key],
                [
                    'display_name' => $cfg['display_name'],
                    'driver' => $cfg['driver'],
                    'base_url' => $cfg['base_url'],
                    'default_model' => $cfg['default_model'],
                    'available_models' => $cfg['models'],
                    'is_enabled' => true,
                    'is_default' => $key === $defaultProvider,
                    'supports_streaming' => true,
                    'max_output_tokens' => in_array($key, ['gemini', 'anthropic'], true) ? 8192 : 4096,
                    'temperature' => 0.70,
                    'daily_token_limit_per_user' => $defaultDailyLimit,
                    'rate_limit_per_minute' => $defaultRateLimit,
                ]
            );
        }
    }
}
