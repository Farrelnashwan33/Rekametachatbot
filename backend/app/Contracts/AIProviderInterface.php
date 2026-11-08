<?php

namespace App\Contracts;

use App\Models\AiProviderSetting;

interface AIProviderInterface
{
    /**
     * Execute a synchronous chat completion request.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{content: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, model: string}
     */
    public function complete(AiProviderSetting $setting, array $messages, array $options = []): array;

    /**
     * Execute a streaming chat completion request and invoke $onDelta for each text chunk.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): bool|void  $onDelta  Return false to abort stream early
     * @param  array<string, mixed>  $options
     * @return array{content: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, model: string, stopped: bool}
     */
    public function stream(AiProviderSetting $setting, array $messages, callable $onDelta, array $options = []): array;
}

