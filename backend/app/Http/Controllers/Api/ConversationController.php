<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $search = trim((string) $request->query('q', ''));
        $mode = trim((string) $request->query('mode', ''));
        $archived = filter_var($request->query('archived', 'false'), FILTER_VALIDATE_BOOLEAN);

        $query = Conversation::where('user_id', $user->id)
            ->where('is_archived', $archived)
            ->when($mode !== '', fn ($q) => $q->where('assistant_mode', $mode))
            ->when($search !== '', function ($q) use ($search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);
                $likeOp = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(function ($sub) use ($escaped, $likeOp) {
                    $sub->where('title', $likeOp, "%{$escaped}%")
                        ->orWhereHas('activeMessages', fn ($mq) => $mq->where('content', $likeOp, "%{$escaped}%"));
                });
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at');

        $conversations = $query->limit(100)->get();

        return response()->json([
            'conversations' => $conversations,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'assistant_mode' => ['sometimes', 'string', Rule::in(['general', 'academic', 'cv', 'document', 'creative', 'coding'])],
            'provider_key' => ['nullable', 'string', 'max:50'],
            'model_name' => ['nullable', 'string', 'max:120'],
            'attached_document_ids' => ['nullable', 'array', 'max:10'],
            'attached_document_ids.*' => ['integer'],
        ]);

        $ownedDocIds = [];
        if (! empty($validated['attached_document_ids'])) {
            $ownedDocIds = Document::where('user_id', $user->id)
                ->whereIn('id', $validated['attached_document_ids'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => ! empty($validated['title']) ? trim($validated['title']) : 'Percakapan Baru',
            'assistant_mode' => $validated['assistant_mode'] ?? (! empty($ownedDocIds) ? 'document' : 'general'),
            'provider_key' => $validated['provider_key'] ?? null,
            'model_name' => $validated['model_name'] ?? null,
            'attached_document_ids' => $ownedDocIds,
            'last_message_at' => now(),
        ]);

        return response()->json([
            'conversation' => $conversation,
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = Conversation::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $messages = $conversation->activeMessages()->get();

        $attachedDocs = [];
        if (! empty($conversation->attached_document_ids)) {
            $attachedDocs = Document::where('user_id', $user->id)
                ->whereIn('id', $conversation->attached_document_ids)
                ->get(['id', 'uuid', 'title', 'original_filename', 'extension', 'word_count', 'status']);
        }

        $pendingMemories = UserMemory::where('user_id', $user->id)
            ->where('source_conversation_id', $conversation->id)
            ->where('is_confirmed', false)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
            'attached_documents' => $attachedDocs,
            'pending_memories' => $pendingMemories,
        ]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = Conversation::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:180'],
            'assistant_mode' => ['sometimes', 'string', Rule::in(['general', 'academic', 'cv', 'document', 'creative', 'coding'])],
            'is_pinned' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
            'attached_document_ids' => ['sometimes', 'array', 'max:10'],
            'attached_document_ids.*' => ['integer'],
        ]);

        if (array_key_exists('attached_document_ids', $validated)) {
            $validated['attached_document_ids'] = Document::where('user_id', $user->id)
                ->whereIn('id', $validated['attached_document_ids'] ?? [])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $conversation->update($validated);

        return response()->json([
            'message' => 'Percakapan diperbarui.',
            'conversation' => $conversation->fresh(),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = Conversation::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $conversation->messages()->delete();
        $conversation->delete();

        return response()->json([
            'message' => 'Percakapan berhasil dihapus.',
        ]);
    }
}
