<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $pref = $user->preference()->firstOrCreate(['user_id' => $user->id]);

        $memories = UserMemory::where('user_id', $user->id)
            ->orderBy('is_confirmed')
            ->orderByDesc('importance')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'memory_enabled' => $pref->memory_enabled,
            'memory_auto_save' => $pref->memory_auto_save,
            'memories' => $memories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'category' => ['required', 'string', Rule::in(['preference', 'profile', 'goal', 'project', 'academic', 'skill'])],
            'memory_key' => ['required', 'string', 'min:2', 'max:120'],
            'memory_value' => ['required', 'string', 'min:2', 'max:600'],
            'importance' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        $memory = UserMemory::create([
            'user_id' => $user->id,
            'category' => $validated['category'],
            'memory_key' => trim($validated['memory_key']),
            'memory_value' => trim($validated['memory_value']),
            'importance' => $validated['importance'] ?? 3,
            'is_confirmed' => true,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Memori personal berhasil ditambahkan.',
            'memory' => $memory,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memory = UserMemory::where('user_id', $user->id)->findOrFail($id);

        $validated = $request->validate([
            'category' => ['sometimes', 'string', Rule::in(['preference', 'profile', 'goal', 'project', 'academic', 'skill'])],
            'memory_key' => ['sometimes', 'string', 'min:2', 'max:120'],
            'memory_value' => ['sometimes', 'string', 'min:2', 'max:600'],
            'importance' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'is_confirmed' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $memory->update($validated);

        return response()->json([
            'message' => 'Memori personal berhasil diperbarui.',
            'memory' => $memory->fresh(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memory = UserMemory::where('user_id', $user->id)->findOrFail($id);
        $memory->forceDelete();

        return response()->json([
            'message' => 'Memori personal telah dihapus.',
        ]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        UserMemory::withTrashed()->where('user_id', $user->id)->forceDelete();

        return response()->json([
            'message' => 'Seluruh memori personal Anda telah dihapus.',
        ]);
    }
}

