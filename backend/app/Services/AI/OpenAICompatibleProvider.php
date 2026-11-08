<?php

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Exceptions\AIProviderException;
use App\Models\AiProviderSetting;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

class OpenAICompatibleProvider implements AIProviderInterface
{
    public function complete(AiProviderSetting $setting, array $messages, array $options = []): array
    {
        $apiKey = $setting->resolveApiKey();
        if (! $apiKey) {
            throw AIProviderException::unconfigured($setting->provider_key);
        }

        $model = (string) ($options['model'] ?? $setting->default_model);
        $url = rtrim($setting->base_url, '/').'/chat/completions';

        $payload = $this->buildCompletionPayload($setting, $model, $messages, $options, false);

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders(array_merge([
                    'Accept' => 'application/json',
                ], $setting->extra_headers ?? []))
                ->timeout(90)
                ->post($url, $payload);
        } catch (Throwable $e) {
            throw new AIProviderException(
                'Gagal terhubung ke layanan AI: '.$e->getMessage(),
                'AI_UPSTREAM_CONNECTION_ERROR',
                502,
                $setting->provider_key
            );
        }

        if ($response->failed() && $setting->provider_key === 'gemini' && in_array($response->status(), [404, 503], true)) {
            $fallbackModel = $model !== 'gemini-3.5-flash' ? 'gemini-3.5-flash' : 'gemini-3.5-flash-lite';
            if ($model !== $fallbackModel) {
                $payload['model'] = $fallbackModel;
                $model = $fallbackModel;
                try {
                    $response = Http::withToken($apiKey)
                        ->withHeaders(array_merge([
                            'Accept' => 'application/json',
                        ], $setting->extra_headers ?? []))
                        ->timeout(90)
                        ->post($url, $payload);
                } catch (Throwable) {
                    // Keep original or throw below
                }
            }
        }

        if ($response->failed()) {
            $this->throwFormattedUpstreamError($setting, $response);
        }

        $data = $response->json();
        $content = (string) data_get($data, 'choices.0.message.content', '');
        $promptTokens = (int) data_get($data, 'usage.prompt_tokens', $this->estimateTokens(json_encode($messages) ?: ''));
        $completionTokens = (int) data_get($data, 'usage.completion_tokens', $this->estimateTokens($content));

        return [
            'content' => $content,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => (int) data_get($data, 'usage.total_tokens', $promptTokens + $completionTokens),
            'model' => (string) data_get($data, 'model', $model),
        ];
    }

    public function stream(AiProviderSetting $setting, array $messages, callable $onDelta, array $options = []): array
    {
        $apiKey = $setting->resolveApiKey();
        if (! $apiKey) {
            throw AIProviderException::unconfigured($setting->provider_key);
        }

        $model = (string) ($options['model'] ?? $setting->default_model);
        $url = rtrim($setting->base_url, '/').'/chat/completions';

        $payload = $this->buildCompletionPayload($setting, $model, $messages, $options, true);

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders(array_merge([
                    'Accept' => 'text/event-stream',
                ], $setting->extra_headers ?? []))
                ->withOptions(['stream' => true])
                ->timeout(120)
                ->post($url, $payload);
        } catch (Throwable $e) {
            throw new AIProviderException(
                'Gagal membuka koneksi streaming ke layanan AI: '.$e->getMessage(),
                'AI_STREAM_CONNECTION_ERROR',
                502,
                $setting->provider_key
            );
        }

        if ($response->failed() && $setting->provider_key === 'gemini' && in_array($response->status(), [404, 503], true)) {
            $fallbackModel = $model !== 'gemini-3.5-flash' ? 'gemini-3.5-flash' : 'gemini-3.5-flash-lite';
            if ($model !== $fallbackModel) {
                $payload['model'] = $fallbackModel;
                $model = $fallbackModel;
                try {
                    $response = Http::withToken($apiKey)
                        ->withHeaders(array_merge([
                            'Accept' => 'text/event-stream',
                        ], $setting->extra_headers ?? []))
                        ->withOptions(['stream' => true])
                        ->timeout(120)
                        ->post($url, $payload);
                } catch (Throwable) {
                    // Keep original or throw below
                }
            }
        }

        if ($response->failed()) {
            $this->throwFormattedUpstreamError($setting, $response);
        }

        $psrStream = $response->toPsrResponse()->getBody();
        $fullContent = '';
        $promptTokens = 0;
        $completionTokens = 0;
        $stopped = false;
        $resolvedModel = $model;

        $buffer = '';
        while (! $psrStream->eof()) {
            if (connection_aborted()) {
                $stopped = true;
                $psrStream->close();
                break;
            }

            $chunk = $psrStream->read(1024);
            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === '[DONE]') {
                    break 2;
                }

                $decoded = json_decode($jsonStr, true);
                if (! is_array($decoded)) {
                    continue;
                }

                if (! empty($decoded['model'])) {
                    $resolvedModel = (string) $decoded['model'];
                }

                if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                    $promptTokens = (int) ($decoded['usage']['prompt_tokens'] ?? $promptTokens);
                    $completionTokens = (int) ($decoded['usage']['completion_tokens'] ?? $completionTokens);
                }

                $delta = data_get($decoded, 'choices.0.delta.content');
                if (is_string($delta) && $delta !== '') {
                    $fullContent .= $delta;
                    $shouldContinue = $onDelta($delta);
                    if ($shouldContinue === false) {
                        $stopped = true;
                        $psrStream->close();
                        break 2;
                    }
                }
            }
        }

        if ($promptTokens === 0) {
            $promptTokens = $this->estimateTokens(json_encode($messages) ?: '');
        }
        if ($completionTokens === 0) {
            $completionTokens = $this->estimateTokens($fullContent);
        }

        return [
            'content' => $fullContent,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'model' => $resolvedModel,
            'stopped' => $stopped,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildCompletionPayload(
        AiProviderSetting $setting,
        string $model,
        array $messages,
        array $options,
        bool $stream
    ): array {
        $maxTokens = (int) ($options['max_tokens'] ?? $setting->max_output_tokens ?? 4096);
        $isReasoningOpenAI = $setting->provider_key === 'openai'
            && (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o3') || str_starts_with($model, 'o4'));

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ];

        if ($isReasoningOpenAI) {
            $payload['max_completion_tokens'] = $maxTokens;
        } else {
            $payload['temperature'] = (float) ($options['temperature'] ?? $setting->temperature ?? 0.7);
            $payload['max_tokens'] = $maxTokens;
        }

        return $payload;
    }

    private function throwFormattedUpstreamError(AiProviderSetting $setting, \Illuminate\Http\Client\Response $response): never
    {
        $errCode = (string) ($response->json('error.code') ?? $response->json('error.type') ?? '');
        if ($setting->provider_key === 'openai' && in_array($errCode, ['credit_balance_exhausted', 'insufficient_quota'], true)) {
            throw new AIProviderException(
                'Saldo kredit API OpenAI Anda habis (insufficient_quota). Silakan isi kredit di platform.openai.com atau pilih provider Google Gemini pada menu atas.',
                'AI_UPSTREAM_QUOTA_EXHAUSTED',
                429,
                $setting->provider_key
            );
        }

        $errBody = $response->json('error.message')
            ?? $response->json('0.error.message')
            ?? $response->body()
            ?: 'HTTP '.$response->status();

        throw new AIProviderException(
            "Respons error dari {$setting->display_name}: {$errBody}",
            'AI_UPSTREAM_HTTP_ERROR',
            $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502,
            $setting->provider_key
        );
    }

    private function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }
}

