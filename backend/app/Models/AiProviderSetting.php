<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AiProviderSetting extends Model
{
    protected $fillable = [
        'provider_key',
        'display_name',
        'driver',
        'base_url',
        'api_key_encrypted',
        'default_model',
        'available_models',
        'is_enabled',
        'is_default',
        'supports_streaming',
        'max_output_tokens',
        'temperature',
        'daily_token_limit_per_user',
        'rate_limit_per_minute',
        'extra_headers',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'available_models' => 'array',
            'extra_headers' => 'array',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'supports_streaming' => 'boolean',
            'max_output_tokens' => 'integer',
            'temperature' => 'float',
            'daily_token_limit_per_user' => 'integer',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    public function setPlainApiKey(?string $plainKey): void
    {
        if ($plainKey === null || trim($plainKey) === '') {
            $this->api_key_encrypted = null;
            return;
        }

        $this->api_key_encrypted = Crypt::encryptString(trim($plainKey));
    }

    public function resolveApiKey(): ?string
    {
        if (! empty($this->api_key_encrypted)) {
            try {
                $decrypted = trim(Crypt::decryptString($this->api_key_encrypted));
                if ($decrypted !== '') {
                    return $decrypted;
                }
            } catch (Throwable) {
                // Fall back to environment configuration if APP_KEY rotated
            }
        }

        $envKey = config("rekametachat.providers.{$this->provider_key}.api_key");

        return is_string($envKey) && trim($envKey) !== '' ? trim($envKey) : null;
    }

    public function hasConfiguredKey(): bool
    {
        return $this->resolveApiKey() !== null;
    }

    public function maskedApiKey(): ?string
    {
        $key = $this->resolveApiKey();
        if ($key === null) {
            return null;
        }

        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($key, 0, 4).'...'.substr($key, -4);
    }

    public function keySource(): string
    {
        if (! empty($this->api_key_encrypted)) {
            return 'database_encrypted';
        }
        $envKey = config("rekametachat.providers.{$this->provider_key}.api_key");
        if (is_string($envKey) && trim($envKey) !== '') {
            return 'environment';
        }

        return 'unconfigured';
    }
}

