<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProviderSetting;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * Privacy-by-design Admin Overview: aggregates counts, token metrics, and sanitized API error logs.
     * Never exposes private user conversation messages, titles, memories, or document contents.
     */
    public function overview(): JsonResponse
    {
        $today = now()->toDateString();
        $sevenDaysAgo = now()->subDays(7);
        $fourteenDaysAgo = now()->subDays(13)->startOfDay();

        $totalUsers = User::count();
        $activeUsers7d = User::where('last_login_at', '>=', $sevenDaysAgo)->count();
        $totalConversations = Conversation::count();
        $totalMessages = Message::where('is_superseded', false)->count();
        $totalDocuments = Document::count();

        $tokensToday = (int) UsageLog::whereDate('created_at', $today)
            ->whereIn('status', ['success', 'stopped'])
            ->sum('total_tokens');

        $tokens7d = (int) UsageLog::where('created_at', '>=', $sevenDaysAgo)
            ->whereIn('status', ['success', 'stopped'])
            ->sum('total_tokens');

        $tokensAllTime = (int) UsageLog::whereIn('status', ['success', 'stopped'])
            ->sum('total_tokens');

        $requestsToday = UsageLog::whereDate('created_at', $today)->count();
        $errorsToday = UsageLog::whereDate('created_at', $today)
            ->whereIn('status', ['error', 'unconfigured', 'rate_limited'])
            ->count();

        $dailySeries = UsageLog::selectRaw('DATE(created_at) as day, SUM(total_tokens) as tokens, COUNT(*) as requests')
            ->where('created_at', '>=', $fourteenDaysAgo)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get();

        $byProvider = UsageLog::selectRaw('provider_key, model_name, COUNT(*) as requests, SUM(total_tokens) as total_tokens, AVG(latency_ms) as avg_latency_ms')
            ->groupBy('provider_key', 'model_name')
            ->orderByDesc('total_tokens')
            ->limit(15)
            ->get();

        $byFeature = UsageLog::selectRaw('feature, COUNT(*) as requests, SUM(total_tokens) as total_tokens')
            ->groupBy('feature')
            ->orderByDesc('requests')
            ->get();

        $recentErrors = UsageLog::whereIn('status', ['error', 'unconfigured', 'rate_limited'])
            ->orderByDesc('created_at')
            ->limit(25)
            ->get([
                'id',
                'feature',
                'provider_key',
                'model_name',
                'status',
                'http_status',
                'error_code',
                'error_message',
                'latency_ms',
                'created_at',
            ]);

        return response()->json([
            'metrics' => [
                'total_users' => $totalUsers,
                'active_users_7d' => $activeUsers7d,
                'total_conversations' => $totalConversations,
                'total_messages' => $totalMessages,
                'total_documents' => $totalDocuments,
                'tokens_today' => $tokensToday,
                'tokens_7d' => $tokens7d,
                'tokens_all_time' => $tokensAllTime,
                'requests_today' => $requestsToday,
                'errors_today' => $errorsToday,
            ],
            'daily_series' => $dailySeries,
            'by_provider' => $byProvider,
            'by_feature' => $byFeature,
            'recent_errors' => $recentErrors,
            'privacy_policy_enforced' => true,
        ]);
    }

    public function listProviders(): JsonResponse
    {
        $providers = AiProviderSetting::orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (AiProviderSetting $s) => $this->serializeProviderForAdmin($s));

        return response()->json(['providers' => $providers]);
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_key' => ['required', 'string', 'min:2', 'max:50', 'alpha_dash', 'unique:ai_provider_settings,provider_key'],
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'driver' => ['required', 'string', Rule::in(['openai_compatible', 'anthropic'])],
            'base_url' => ['required', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:400'],
            'default_model' => ['required', 'string', 'max:120'],
            'available_models' => ['nullable', 'array'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'supports_streaming' => ['sometimes', 'boolean'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:128', 'max:64000'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'daily_token_limit_per_user' => ['sometimes', 'integer', 'min:0', 'max:50000000'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:600'],
        ]);

        $provider = new AiProviderSetting([
            'provider_key' => strtolower(trim($validated['provider_key'])),
            'display_name' => trim($validated['display_name']),
            'driver' => $validated['driver'],
            'base_url' => rtrim(trim($validated['base_url']), '/'),
            'default_model' => trim($validated['default_model']),
            'available_models' => $validated['available_models'] ?? [
                [
                    'id' => trim($validated['default_model']),
                    'label' => trim($validated['default_model']),
                    'context_window' => 128000,
                    'supports_streaming' => $validated['supports_streaming'] ?? true,
                ],
            ],
            'is_enabled' => $validated['is_enabled'] ?? true,
            'is_default' => $validated['is_default'] ?? false,
            'supports_streaming' => $validated['supports_streaming'] ?? true,
            'max_output_tokens' => $validated['max_output_tokens'] ?? 4096,
            'temperature' => $validated['temperature'] ?? 0.70,
            'daily_token_limit_per_user' => $validated['daily_token_limit_per_user'] ?? 250000,
            'rate_limit_per_minute' => $validated['rate_limit_per_minute'] ?? 30,
        ]);

        if (! empty($validated['api_key'])) {
            $provider->setPlainApiKey($validated['api_key']);
        }

        DB::transaction(function () use ($provider) {
            if ($provider->is_default) {
                AiProviderSetting::query()->update(['is_default' => false]);
            }
            $provider->save();
        });

        return response()->json([
            'message' => 'Provider AI baru berhasil ditambahkan.',
            'provider' => $this->serializeProviderForAdmin($provider->fresh()),
        ], 201);
    }

    public function updateProvider(Request $request, int $id): JsonResponse
    {
        $provider = AiProviderSetting::findOrFail($id);

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'driver' => ['sometimes', 'string', Rule::in(['openai_compatible', 'anthropic'])],
            'base_url' => ['sometimes', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:400'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'default_model' => ['sometimes', 'string', 'max:120'],
            'available_models' => ['sometimes', 'array'],
            'available_models.*.id' => ['required_with:available_models', 'string', 'max:120'],
            'available_models.*.label' => ['required_with:available_models', 'string', 'max:140'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'supports_streaming' => ['sometimes', 'boolean'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:128', 'max:64000'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'daily_token_limit_per_user' => ['sometimes', 'integer', 'min:0', 'max:50000000'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:600'],
        ]);

        DB::transaction(function () use ($provider, $validated) {
            if (! empty($validated['is_default'])) {
                AiProviderSetting::where('id', '!=', $provider->id)->update(['is_default' => false]);
            }

            if (! empty($validated['clear_api_key'])) {
                $provider->setPlainApiKey(null);
            } elseif (array_key_exists('api_key', $validated) && $validated['api_key'] !== null && trim($validated['api_key']) !== '') {
                $provider->setPlainApiKey($validated['api_key']);
            }

            unset($validated['api_key'], $validated['clear_api_key']);
            if (isset($validated['base_url'])) {
                $validated['base_url'] = rtrim(trim($validated['base_url']), '/');
            }

            $provider->fill($validated);
            $provider->save();
        });

        return response()->json([
            'message' => "Konfigurasi {$provider->display_name} berhasil disimpan.",
            'provider' => $this->serializeProviderForAdmin($provider->fresh()),
        ]);
    }

    public function listUsers(): JsonResponse
    {
        $today = now()->toDateString();

        $users = User::select(['id', 'name', 'email', 'role', 'is_active', 'daily_token_limit', 'last_login_at', 'created_at'])
            ->withCount(['conversations', 'documents'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(function (User $u) use ($today) {
                $tokensToday = (int) UsageLog::where('user_id', $u->id)
                    ->whereDate('created_at', $today)
                    ->whereIn('status', ['success', 'stopped'])
                    ->sum('total_tokens');

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'is_active' => $u->is_active,
                    'daily_token_limit' => $u->daily_token_limit,
                    'conversations_count' => $u->conversations_count,
                    'documents_count' => $u->documents_count,
                    'tokens_today' => $tokensToday,
                    'last_login_at' => $u->last_login_at,
                    'created_at' => $u->created_at,
                ];
            });

        return response()->json(['users' => $users]);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $target = User::findOrFail($id);

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(['user', 'admin'])],
            'is_active' => ['sometimes', 'boolean'],
            'daily_token_limit' => ['nullable', 'integer', 'min:0', 'max:50000000'],
        ]);

        if ($target->id === $admin->id) {
            if (isset($validated['is_active']) && $validated['is_active'] === false) {
                return response()->json(['message' => 'Anda tidak dapat menonaktifkan akun administrator Anda sendiri.'], 422);
            }
            if (isset($validated['role']) && $validated['role'] !== 'admin') {
                return response()->json(['message' => 'Anda tidak dapat menurunkan peran administrator akun Anda sendiri.'], 422);
            }
        }

        $target->update($validated);
        if (isset($validated['is_active']) && $validated['is_active'] === false) {
            $target->tokens()->delete();
        }

        return response()->json([
            'message' => "Pengaturan pengguna {$target->name} berhasil diperbarui.",
            'user' => $target->only(['id', 'name', 'email', 'role', 'is_active', 'daily_token_limit']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProviderForAdmin(AiProviderSetting $s): array
    {
        return [
            'id' => $s->id,
            'provider_key' => $s->provider_key,
            'display_name' => $s->display_name,
            'driver' => $s->driver,
            'base_url' => $s->base_url,
            'default_model' => $s->default_model,
            'available_models' => $s->available_models ?? [],
            'is_enabled' => $s->is_enabled,
            'is_default' => $s->is_default,
            'supports_streaming' => $s->supports_streaming,
            'max_output_tokens' => $s->max_output_tokens,
            'temperature' => $s->temperature,
            'daily_token_limit_per_user' => $s->daily_token_limit_per_user,
            'rate_limit_per_minute' => $s->rate_limit_per_minute,
            'has_api_key' => $s->hasConfiguredKey(),
            'masked_api_key' => $s->maskedApiKey(),
            'key_source' => $s->keySource(),
            'updated_at' => $s->updated_at,
        ];
    }
}

