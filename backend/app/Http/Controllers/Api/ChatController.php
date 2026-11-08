<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AIProviderException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AIProviderManager;
use App\Services\AI\ContextManagerService;
use App\Services\AI\MemoryExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function status(AIProviderManager $aiManager): JsonResponse
    {
        return response()->json($aiManager->getPublicStatus());
    }

    public function sendMessage(
        Request $request,
        string $conversationUuid,
        AIProviderManager $aiManager,
        ContextManagerService $contextManager,
        MemoryExtractorService $memoryExtractor,
    ): JsonResponse|StreamedResponse {
        /** @var User $user */
        $user = $request->user();

        $conversation = Conversation::where('uuid', $conversationUuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:24000'],
            'stream' => ['sometimes', 'boolean'],
            'provider_key' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:120'],
            'document_ids' => ['nullable', 'array', 'max:10'],
            'document_ids.*' => ['integer'],
        ]);

        $content = trim($validated['content']);
        $extraDocIds = [];
        if (! empty($validated['document_ids'])) {
            $extraDocIds = Document::where('user_id', $user->id)
                ->whereIn('id', $validated['document_ids'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $mergedDocs = array_values(array_unique(array_merge($conversation->attached_document_ids ?? [], $extraDocIds)));
            $conversation->update(['attached_document_ids' => $mergedDocs]);
        }

        $isFirstMessage = $conversation->activeMessages()->count() === 0;
        $lastActive = $conversation->activeMessages()->reorder('id', 'desc')->first();

        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'parent_message_id' => $lastActive?->id,
            'role' => 'user',
            'content' => $content,
        ]);

        if ($isFirstMessage && ($conversation->title === 'Percakapan Baru' || trim($conversation->title) === '')) {
            $cleanTitle = preg_replace('/\s+/u', ' ', $content) ?: 'Percakapan';
            $conversation->title = mb_substr($cleanTitle, 0, 72);
        }
        $conversation->last_message_at = now();
        $conversation->save();

        $memorySuggestions = $memoryExtractor->extractCandidatesFromMessage($user, $conversation, $content);

        return $this->dispatchGeneration(
            user: $user,
            conversation: $conversation,
            userMessage: $userMessage,
            stream: (bool) ($validated['stream'] ?? false),
            providerKey: $validated['provider_key'] ?? $conversation->provider_key,
            model: $validated['model'] ?? $conversation->model_name,
            extraDocIds: $extraDocIds,
            memorySuggestions: $memorySuggestions->all(),
            aiManager: $aiManager,
            contextManager: $contextManager,
        );
    }

    public function editMessage(
        Request $request,
        string $messageUuid,
        AIProviderManager $aiManager,
        ContextManagerService $contextManager,
        MemoryExtractorService $memoryExtractor,
    ): JsonResponse|StreamedResponse {
        /** @var User $user */
        $user = $request->user();

        $original = Message::where('uuid', $messageUuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($original->role !== 'user') {
            return response()->json([
                'message' => 'Hanya pesan pengguna yang dapat diedit.',
            ], 422);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:24000'],
            'stream' => ['sometimes', 'boolean'],
            'provider_key' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        $conversation = Conversation::where('id', $original->conversation_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Mark original message and any active messages from that point onward as superseded
        Message::where('conversation_id', $conversation->id)
            ->where('id', '>=', $original->id)
            ->where('is_superseded', false)
            ->update(['is_superseded' => true]);

        $editedUserMessage = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'parent_message_id' => $original->parent_message_id,
            'role' => 'user',
            'content' => trim($validated['content']),
            'is_edited' => true,
        ]);

        $conversation->update(['last_message_at' => now()]);
        $memorySuggestions = $memoryExtractor->extractCandidatesFromMessage($user, $conversation, $editedUserMessage->content);

        return $this->dispatchGeneration(
            user: $user,
            conversation: $conversation,
            userMessage: $editedUserMessage,
            stream: (bool) ($validated['stream'] ?? false),
            providerKey: $validated['provider_key'] ?? $conversation->provider_key,
            model: $validated['model'] ?? $conversation->model_name,
            extraDocIds: [],
            memorySuggestions: $memorySuggestions->all(),
            aiManager: $aiManager,
            contextManager: $contextManager,
        );
    }

    public function regenerateMessage(
        Request $request,
        string $messageUuid,
        AIProviderManager $aiManager,
        ContextManagerService $contextManager,
    ): JsonResponse|StreamedResponse {
        /** @var User $user */
        $user = $request->user();

        $target = Message::where('uuid', $messageUuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $conversation = Conversation::where('id', $target->conversation_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'stream' => ['sometimes', 'boolean'],
            'provider_key' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        if ($target->role === 'assistant') {
            $userPromptMessage = Message::where('conversation_id', $conversation->id)
                ->where('id', '<', $target->id)
                ->where('role', 'user')
                ->where('is_superseded', false)
                ->orderByDesc('id')
                ->firstOrFail();

            Message::where('conversation_id', $conversation->id)
                ->where('id', '>=', $target->id)
                ->where('is_superseded', false)
                ->update(['is_superseded' => true]);
        } else {
            $userPromptMessage = $target;
            Message::where('conversation_id', $conversation->id)
                ->where('id', '>', $target->id)
                ->where('is_superseded', false)
                ->update(['is_superseded' => true]);
        }

        return $this->dispatchGeneration(
            user: $user,
            conversation: $conversation,
            userMessage: $userPromptMessage,
            stream: (bool) ($validated['stream'] ?? false),
            providerKey: $validated['provider_key'] ?? $conversation->provider_key,
            model: $validated['model'] ?? $conversation->model_name,
            extraDocIds: [],
            memorySuggestions: [],
            aiManager: $aiManager,
            contextManager: $contextManager,
        );
    }

    public function updateMessageMeta(Request $request, string $messageUuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $message = Message::where('uuid', $messageUuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'feedback' => ['nullable', 'string', Rule::in(['up', 'down'])],
            'is_bookmarked' => ['sometimes', 'boolean'],
        ]);

        $message->update($validated);

        return response()->json([
            'message' => 'Status pesan diperbarui.',
            'data' => $message->fresh(),
        ]);
    }

    /**
     * @param  array<int, int>  $extraDocIds
     * @param  array<int, mixed>  $memorySuggestions
     */
    private function dispatchGeneration(
        User $user,
        Conversation $conversation,
        Message $userMessage,
        bool $stream,
        ?string $providerKey,
        ?string $model,
        array $extraDocIds,
        array $memorySuggestions,
        AIProviderManager $aiManager,
        ContextManagerService $contextManager,
    ): JsonResponse|StreamedResponse {
        $feature = match ($conversation->assistant_mode) {
            'academic' => 'academic',
            'document' => 'document_qa',
            'creative' => 'creative',
            'cv' => 'cv_review',
            default => 'chat',
        };

        try {
            $setting = $aiManager->resolveProviderSetting($providerKey, $user);
            $aiManager->ensureReadyAndWithinQuota($setting, $user, $feature, $conversation->id);
        } catch (AIProviderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'conversation' => $conversation->fresh(),
                'user_message' => $userMessage,
                'memory_suggestions' => $memorySuggestions,
            ], $e->httpStatus);
        }

        $payload = $contextManager->buildPromptPayload($user, $conversation, $userMessage->content, $extraDocIds);

        if (! $stream) {
            try {
                $completion = $aiManager->completeForUser(
                    user: $user,
                    messages: $payload['messages'],
                    feature: $feature,
                    providerKey: $providerKey,
                    model: $model,
                    conversationId: $conversation->id,
                );

                $assistantMessage = Message::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'parent_message_id' => $userMessage->id,
                    'role' => 'assistant',
                    'content' => $completion['content'],
                    'provider_key' => $completion['provider_key'],
                    'model_name' => $completion['model'],
                    'prompt_tokens' => $completion['prompt_tokens'],
                    'completion_tokens' => $completion['completion_tokens'],
                    'total_tokens' => $completion['total_tokens'],
                    'latency_ms' => $completion['latency_ms'],
                    'citations' => $payload['citations'],
                ]);

                $conversation->update([
                    'provider_key' => $completion['provider_key'],
                    'model_name' => $completion['model'],
                    'last_message_at' => now(),
                ]);

                return response()->json([
                    'conversation' => $conversation->fresh(),
                    'user_message' => $userMessage,
                    'assistant_message' => $assistantMessage,
                    'memory_suggestions' => $memorySuggestions,
                ]);
            } catch (AIProviderException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => $e->errorCode,
                    'conversation' => $conversation->fresh(),
                    'user_message' => $userMessage,
                ], $e->httpStatus);
            }
        }

        return response()->stream(function () use (
            $user,
            $conversation,
            $userMessage,
            $providerKey,
            $model,
            $feature,
            $payload,
            $memorySuggestions,
            $aiManager
        ) {
            ignore_user_abort(true);

            $emit = function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            $emit('meta', [
                'conversation' => $conversation->fresh(),
                'user_message' => $userMessage,
                'memory_suggestions' => $memorySuggestions,
            ]);

            $accumulated = '';
            try {
                $result = $aiManager->streamForUser(
                    user: $user,
                    messages: $payload['messages'],
                    onDelta: function (string $delta) use (&$accumulated, $emit): bool {
                        if (connection_aborted()) {
                            return false;
                        }
                        $accumulated .= $delta;
                        $emit('delta', ['content' => $delta]);

                        return true;
                    },
                    feature: $feature,
                    providerKey: $providerKey,
                    model: $model,
                    conversationId: $conversation->id,
                );

                $finalText = $result['content'] !== '' ? $result['content'] : $accumulated;
                if ($finalText !== '') {
                    $assistantMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'user_id' => $user->id,
                        'parent_message_id' => $userMessage->id,
                        'role' => 'assistant',
                        'content' => $finalText,
                        'provider_key' => $result['provider_key'],
                        'model_name' => $result['model'],
                        'prompt_tokens' => $result['prompt_tokens'],
                        'completion_tokens' => $result['completion_tokens'],
                        'total_tokens' => $result['total_tokens'],
                        'latency_ms' => $result['latency_ms'],
                        'is_stopped' => $result['stopped'],
                        'citations' => $payload['citations'],
                    ]);

                    $conversation->update([
                        'provider_key' => $result['provider_key'],
                        'model_name' => $result['model'],
                        'last_message_at' => now(),
                    ]);

                    if (! connection_aborted()) {
                        $emit('done', [
                            'assistant_message' => $assistantMessage,
                            'conversation' => $conversation->fresh(),
                        ]);
                    }
                }
            } catch (AIProviderException $e) {
                if (! connection_aborted()) {
                    $emit('error', [
                        'message' => $e->getMessage(),
                        'error_code' => $e->errorCode,
                    ]);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

