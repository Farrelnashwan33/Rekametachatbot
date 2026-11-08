<?php

namespace App\Exceptions;

use RuntimeException;

class AIProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'AI_PROVIDER_ERROR',
        public readonly int $httpStatus = 502,
        public readonly ?string $providerKey = null,
    ) {
        parent::__construct(self::sanitizeMessage($message), $httpStatus);
    }

    public static function unconfigured(?string $providerKey = null): self
    {
        $name = $providerKey ? strtoupper($providerKey) : 'Default AI';

        return new self(
            "Provider AI ({$name}) belum dikonfigurasi dengan API key aktif. Silakan atur API key melalui variabel environment (.env) di server atau melalui menu Admin Dashboard > AI Providers.",
            'AI_PROVIDER_NOT_CONFIGURED',
            503,
            $providerKey
        );
    }

    public static function quotaExceeded(int $limit): self
    {
        return new self(
            "Batas penggunaan token harian Anda ({$limit} token) telah tercapai. Silakan coba kembali besok atau hubungi administrator.",
            'DAILY_TOKEN_QUOTA_EXCEEDED',
            429
        );
    }

    public static function sanitizeMessage(string $raw): string
    {
        $cleaned = preg_replace('/(sk-[a-zA-Z0-9\-_]{10,}|Bearer\s+[a-zA-Z0-9\-._~+\/]+=*|AIza[0-9A-Za-z\-_]{20,})/i', '[REDACTED_KEY]', $raw) ?? $raw;

        return mb_substr(trim($cleaned), 0, 600);
    }
}

