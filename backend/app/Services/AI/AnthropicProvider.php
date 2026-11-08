<?php

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Exceptions\AIProviderException;
use App\Models\AiProviderSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

class AnthropicProvider implements AIProviderInterface
{
    public function complete(AiProviderSetting $setting, array $messages, array $options = []): array
    {
        $apiKey = $setting->resolveApiKey();
        if (! $apiKey) {
            throw AIProviderException::unconfigured($setting->provider_key);
        }

        $model = (string) ($options['model'] ?? $setting->default_model);
        $url = rtrim($setting->base_url, '/').'/messages';
        [$systemPrompt, $chatMessages] = $this->separateSystemMessages($messages);

        $payload = $this->buildMessagesPayload($setting, $model, $systemPrompt, $chatMessages, $options, false);

        try {
            $response = Http::withHeaders(array_merge([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Accept' => 'application/json',
            ], $setting->extra_headers ?? []))
                ->timeout(90)
                ->post($url, $payload);
        } catch (Throwable $e) {
            throw new AIProviderException(
                'Gagal terhubung ke Anthropic API: '.$e->getMessage(),
                'AI_UPSTREAM_CONNECTION_ERROR',
                502,
                $setting->provider_key
            );
        }

        if ($response->failed()) {
            $this->throwFormattedUpstreamError($setting, $response);
        }

        $data = $response->json();
        $blocks = data_get($data, 'content', []);
        $content = '';
        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $content .= (string) ($block['text'] ?? '');
                }
            }
        }

        $promptTokens = (int) data_get($data, 'usage.input_tokens', max(1, (int) ceil(mb_strlen(json_encode($messages) ?: '') / 4)));
        $completionTokens = (int) data_get($data, 'usage.output_tokens', max(1, (int) ceil(mb_strlen($content) / 4)));

        return [
            'content' => $content,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
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
        $url = rtrim($setting->base_url, '/').'/messages';
        [$systemPrompt, $chatMessages] = $this->separateSystemMessages($messages);

        $payload = $this->buildMessagesPayload($setting, $model, $systemPrompt, $chatMessages, $options, true);

        try {
            $response = Http::withHeaders(array_merge([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Accept' => 'text/event-stream',
            ], $setting->extra_headers ?? []))
                ->withOptions(['stream' => true])
                ->timeout(120)
                ->post($url, $payload);
        } catch (Throwable $e) {
            throw new AIProviderException(
                'Gagal membuka streaming ke Anthropic API: '.$e->getMessage(),
                'AI_STREAM_CONNECTION_ERROR',
                502,
                $setting->provider_key
            );
        }

        if ($response->failed()) {
            $this->throwFormattedUpstreamError($setting, $response);
        }

        $psrStream = $response->toPsrResponse()->getBody();
        $fullContent = '';
        $promptTokens = 0;
        $completionTokens = 0;
        $stopped = false;
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

                if (! str_starts_with($line, 'data:')) {
                    continue;
                }

                $decoded = json_decode(trim(substr($line, 5)), true);
                if (! is_array($decoded)) {
                    continue;
                }

                $type = $decoded['type'] ?? '';
                if ($type === 'message_start') {
                    $promptTokens = (int) data_get($decoded, 'message.usage.input_tokens', $promptTokens);
                } elseif ($type === 'content_block_delta') {
                    $delta = data_get($decoded, 'delta.text', '');
                    if (is_string($delta) && $delta !== '') {
                        $fullContent .= $delta;
                        if ($onDelta($delta) === false) {
                            $stopped = true;
                            $psrStream->close();
                            break 2;
                        }
                    }
                } elseif ($type === 'message_delta') {
                    $completionTokens = (int) data_get($decoded, 'usage.output_tokens', $completionTokens);
                } elseif ($type === 'message_stop') {
                    break 2;
                }
            }
        }

        if ($promptTokens === 0) {
            $promptTokens = max(1, (int) ceil(mb_strlen(json_encode($messages) ?: '') / 4));
        }
        if ($completionTokens === 0) {
            $completionTokens = max(1, (int) ceil(mb_strlen($fullContent) / 4));
        }

        return [
            'content' => $fullContent,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'model' => $model,
            'stopped' => $stopped,
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{0: string, 1: array<int, array{role: string, content: string}>}
     */
    private function separateSystemMessages(array $messages): array
    {
        $systemParts = [];
        $chat = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemParts[] = (string) ($msg['content'] ?? '');
            } else {
                $chat[] = [
                    'role' => ($msg['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
                    'content' => (string) ($msg['content'] ?? ''),
                ];
            }
        }

        if (empty($chat)) {
            $chat[] = ['role' => 'user', 'content' => 'Halo'];
        }

        return [implode("\n\n", $systemParts), $chat];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $chatMessages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildMessagesPayload(
        AiProviderSetting $setting,
        string $model,
        string $systemPrompt,
        array $chatMessages,
        array $options,
        bool $stream
    ): array {
        $maxTokens = (int) ($options['max_tokens'] ?? $setting->max_output_tokens ?? 8192);
        $hasForcedAdaptiveThinking = str_starts_with($model, 'claude-sonnet-5')
            || str_starts_with($model, 'claude-opus-5')
            || str_starts_with($model, 'claude-fable-5');

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $chatMessages,
        ];

        if (! $hasForcedAdaptiveThinking) {
            $payload['temperature'] = (float) ($options['temperature'] ?? $setting->temperature ?? 0.7);
        }

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    private function throwFormattedUpstreamError(AiProviderSetting $setting, \Illuminate\Http\Client\Response $response): never
    {
        $errBody = (string) ($response->json('error.message') ?? $response->body() ?: 'HTTP '.$response->status());

        if (stripos($errBody, 'credit balance is too low') !== false) {
            throw new AIProviderException(
                'Saldo kredit API Anthropic Claude Anda belum mencukupi (credit balance too low). Silakan isi kredit di console.anthropic.com (Plans & Billing) atau pilih provider Google Gemini pada menu atas.',
                'AI_UPSTREAM_QUOTA_EXHAUSTED',
                429,
                $setting->provider_key
            );
        }

        throw new AIProviderException(
            "Respons error dari {$setting->display_name}: {$errBody}",
            'AI_UPSTREAM_HTTP_ERROR',
            $response->status() >= 400 && $response->status() < 600 ? $response->status() : 502,
            $setting->provider_key
        );
    }
}

