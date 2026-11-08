<?php

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Exceptions\AIProviderException;
use App\Models\AiProviderSetting;
use App\Models\UsageLog;
use App\Models\User;

class AIProviderManager
{
    public function __construct(
        private readonly OpenAICompatibleProvider $openAIProvider,
        private readonly AnthropicProvider $anthropicProvider,
    ) {}

    public function resolveProviderSetting(?string $requestedKey = null, ?User $user = null): AiProviderSetting
    {
        $candidateKeys = array_values(array_filter([
            $requestedKey,
            $user?->preference?->default_provider,
        ]));

        foreach ($candidateKeys as $key) {
            $setting = AiProviderSetting::where('provider_key', $key)
                ->where('is_enabled', true)
                ->first();
            if ($setting && $setting->hasConfiguredKey()) {
                return $setting;
            }
        }

        // Check default enabled provider with a valid API key
        $defaultConfigured = AiProviderSetting::where('is_enabled', true)
            ->orderByDesc('is_default')
            ->get()
            ->first(fn (AiProviderSetting $s) => $s->hasConfiguredKey());

        if ($defaultConfigured) {
            return $defaultConfigured;
        }

        // Otherwise return the requested or default setting so caller can report accurate unconfigured state
        $fallback = null;
        if ($requestedKey) {
            $fallback = AiProviderSetting::where('provider_key', $requestedKey)->first();
        }

        $fallback ??= AiProviderSetting::where('is_enabled', true)->orderByDesc('is_default')->first();

        if (! $fallback) {
            throw AIProviderException::unconfigured($requestedKey);
        }

        return $fallback;
    }

    public function driverFor(AiProviderSetting $setting): AIProviderInterface
    {
        return match ($setting->driver) {
            'anthropic' => $this->anthropicProvider,
            default => $this->openAIProvider,
        };
    }

    public function ensureReadyAndWithinQuota(AiProviderSetting $setting, User $user, string $feature, ?int $conversationId = null): void
    {
        if (! $setting->is_enabled || ! $setting->hasConfiguredKey()) {
            $this->logUsage(
                user: $user,
                conversationId: $conversationId,
                feature: $feature,
                providerKey: $setting->provider_key,
                modelName: $setting->default_model,
                status: 'unconfigured',
                httpStatus: 503,
                errorCode: 'AI_PROVIDER_NOT_CONFIGURED',
                errorMessage: "Provider {$setting->provider_key} belum memiliki API key aktif."
            );

            throw AIProviderException::unconfigured($setting->provider_key);
        }

        $dailyLimit = $user->daily_token_limit ?? $setting->daily_token_limit_per_user ?? (int) config('rekametachat.default_daily_token_limit', 250000);
        if ($dailyLimit > 0) {
            $usedToday = (int) UsageLog::where('user_id', $user->id)
                ->whereDate('created_at', now()->toDateString())
                ->whereIn('status', ['success', 'stopped'])
                ->sum('total_tokens');

            if ($usedToday >= $dailyLimit) {
                $this->logUsage(
                    user: $user,
                    conversationId: $conversationId,
                    feature: $feature,
                    providerKey: $setting->provider_key,
                    modelName: $setting->default_model,
                    status: 'rate_limited',
                    httpStatus: 429,
                    errorCode: 'DAILY_TOKEN_QUOTA_EXCEEDED',
                    errorMessage: "Kuota harian {$dailyLimit} token terlampaui."
                );

                throw AIProviderException::quotaExceeded($dailyLimit);
            }
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, model: string, provider_key: string, latency_ms: int}
     */
    public function completeForUser(
        User $user,
        array $messages,
        string $feature = 'chat',
        ?string $providerKey = null,
        ?string $model = null,
        ?int $conversationId = null,
        array $options = []
    ): array {
        $setting = $this->resolveProviderSetting($providerKey, $user);
        $this->ensureReadyAndWithinQuota($setting, $user, $feature, $conversationId);

        $resolvedModel = $this->resolveModelForSetting($setting, $providerKey, $model);
        $options['model'] = $resolvedModel;

        $startedAt = hrtime(true);
        try {
            $result = $this->driverFor($setting)->complete($setting, $messages, $options);
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $this->logUsage(
                user: $user,
                conversationId: $conversationId,
                feature: $feature,
                providerKey: $setting->provider_key,
                modelName: $result['model'],
                promptTokens: $result['prompt_tokens'],
                completionTokens: $result['completion_tokens'],
                totalTokens: $result['total_tokens'],
                latencyMs: $latencyMs,
                status: 'success',
                httpStatus: 200
            );

            return array_merge($result, [
                'provider_key' => $setting->provider_key,
                'latency_ms' => $latencyMs,
            ]);
        } catch (AIProviderException $e) {
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $this->logUsage(
                user: $user,
                conversationId: $conversationId,
                feature: $feature,
                providerKey: $setting->provider_key,
                modelName: $resolvedModel,
                latencyMs: $latencyMs,
                status: 'error',
                httpStatus: $e->httpStatus,
                errorCode: $e->errorCode,
                errorMessage: $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): bool|void  $onDelta
     * @param  array<string, mixed>  $options
     * @return array{content: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, model: string, provider_key: string, latency_ms: int, stopped: bool}
     */
    public function streamForUser(
        User $user,
        array $messages,
        callable $onDelta,
        string $feature = 'chat',
        ?string $providerKey = null,
        ?string $model = null,
        ?int $conversationId = null,
        array $options = []
    ): array {
        $setting = $this->resolveProviderSetting($providerKey, $user);
        $this->ensureReadyAndWithinQuota($setting, $user, $feature, $conversationId);

        $resolvedModel = $this->resolveModelForSetting($setting, $providerKey, $model);
        $options['model'] = $resolvedModel;

        $startedAt = hrtime(true);
        try {
            $result = $this->driverFor($setting)->stream($setting, $messages, $onDelta, $options);
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $this->logUsage(
                user: $user,
                conversationId: $conversationId,
                feature: $feature,
                providerKey: $setting->provider_key,
                modelName: $result['model'],
                promptTokens: $result['prompt_tokens'],
                completionTokens: $result['completion_tokens'],
                totalTokens: $result['total_tokens'],
                latencyMs: $latencyMs,
                status: $result['stopped'] ? 'stopped' : 'success',
                httpStatus: 200
            );

            return array_merge($result, [
                'provider_key' => $setting->provider_key,
                'latency_ms' => $latencyMs,
            ]);
        } catch (AIProviderException $e) {
            $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $this->logUsage(
                user: $user,
                conversationId: $conversationId,
                feature: $feature,
                providerKey: $setting->provider_key,
                modelName: $resolvedModel,
                latencyMs: $latencyMs,
                status: 'error',
                httpStatus: $e->httpStatus,
                errorCode: $e->errorCode,
                errorMessage: $e->getMessage()
            );
            throw $e;
        }
    }

    public function getPublicStatus(): array
    {
        $settings = AiProviderSetting::where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('display_name')
            ->get();

        $configuredProviders = [];
        foreach ($settings as $s) {
            $configuredProviders[] = [
                'provider_key' => $s->provider_key,
                'display_name' => $s->display_name,
                'default_model' => $s->default_model,
                'available_models' => $s->available_models ?? [],
                'is_default' => $s->is_default,
                'is_configured' => $s->hasConfiguredKey(),
                'supports_streaming' => $s->supports_streaming,
            ];
        }

        $anyConfigured = collect($configuredProviders)->contains(fn (array $p) => $p['is_configured'] === true);

        return [
            'ai_ready' => $anyConfigured,
            'providers' => $configuredProviders,
        ];
    }

    private function resolveModelForSetting(AiProviderSetting $setting, ?string $requestedProviderKey, ?string $requestedModel): string
    {
        if (! $requestedModel || trim($requestedModel) === '') {
            return $setting->default_model;
        }

        $trimmedModel = trim($requestedModel);

        // When falling back from an unconfigured provider to a different active provider, use the active provider's default model
        if ($requestedProviderKey !== null && trim($requestedProviderKey) !== '' && $setting->provider_key !== trim($requestedProviderKey)) {
            return $setting->default_model;
        }

        if ($setting->provider_key === 'gemini') {
            if (str_starts_with($trimmedModel, 'gemini-2.5') || (! str_starts_with($trimmedModel, 'gemini-') && ! str_starts_with($trimmedModel, 'gemma-'))) {
                return $setting->default_model;
            }
        }

        if ($setting->provider_key === 'anthropic') {
            if (str_starts_with($trimmedModel, 'claude-3') || ! str_starts_with($trimmedModel, 'claude-')) {
                return $setting->default_model;
            }
        }

        return $trimmedModel;
    }

    public function logUsage(
        ?User $user,
        ?int $conversationId,
        string $feature,
        string $providerKey,
        string $modelName,
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $totalTokens = 0,
        int $latencyMs = 0,
        string $status = 'success',
        ?int $httpStatus = 200,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): UsageLog {
        return UsageLog::create([
            'user_id' => $user?->id,
            'conversation_id' => $conversationId,
            'feature' => $feature,
            'provider_key' => $providerKey,
            'model_name' => $modelName,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'latency_ms' => $latencyMs,
            'status' => $status,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'error_message' => $errorMessage ? AIProviderException::sanitizeMessage($errorMessage) : null,
        ]);
    }
}

