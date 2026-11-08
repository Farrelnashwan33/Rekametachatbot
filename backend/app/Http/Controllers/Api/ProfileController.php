<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'bio' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update([
            'name' => trim($validated['name']),
            'email' => mb_strtolower(trim($validated['email'])),
            'bio' => isset($validated['bio']) ? trim($validated['bio']) : null,
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => $user->fresh('preference'),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8), 'different:current_password'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Kata sandi saat ini tidak sesuai.',
                'errors' => ['current_password' => ['Kata sandi saat ini salah.']],
            ], 422);
        }

        $user->update(['password' => $validated['password']]);

        $currentTokenId = $user->currentAccessToken()?->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return response()->json([
            'message' => 'Kata sandi berhasil diperbarui.',
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'theme' => ['sometimes', 'string', Rule::in(['system', 'light', 'dark'])],
            'language' => ['sometimes', 'string', Rule::in(['id', 'en'])],
            'communication_style' => ['sometimes', 'string', Rule::in(['balanced', 'concise', 'detailed', 'academic', 'casual'])],
            'memory_enabled' => ['sometimes', 'boolean'],
            'memory_auto_save' => ['sometimes', 'boolean'],
            'default_provider' => ['nullable', 'string', 'max:50'],
            'default_model' => ['nullable', 'string', 'max:120'],
            'voice_enabled' => ['sometimes', 'boolean'],
        ]);

        $pref = $user->preference()->firstOrCreate(['user_id' => $user->id]);
        $pref->update($validated);

        return response()->json([
            'message' => 'Preferensi berhasil disimpan.',
            'preference' => $pref->fresh(),
        ]);
    }

    public function deleteUserData(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::in(['conversations', 'memories', 'documents', 'all_data', 'delete_account'])],
            'password' => ['required_if:scope,delete_account', 'nullable', 'string'],
        ]);

        $scope = $validated['scope'];

        if ($scope === 'delete_account') {
            if (! Hash::check((string) ($validated['password'] ?? ''), $user->password)) {
                return response()->json([
                    'message' => 'Konfirmasi kata sandi tidak sesuai.',
                ], 422);
            }
        }

        DB::transaction(function () use ($user, $scope) {
            if (in_array($scope, ['conversations', 'all_data', 'delete_account'], true)) {
                $user->messages()->forceDelete();
                $user->conversations()->forceDelete();
            }

            if (in_array($scope, ['memories', 'all_data', 'delete_account'], true)) {
                $user->memories()->forceDelete();
            }

            if (in_array($scope, ['documents', 'all_data', 'delete_account'], true)) {
                $docs = Document::withTrashed()->where('user_id', $user->id)->get();
                foreach ($docs as $doc) {
                    try {
                        Storage::disk($doc->disk)->delete($doc->storage_path);
                    } catch (\Throwable) {
                        // Ignore missing storage object during cleanup
                    }
                    $doc->chunks()->delete();
                    $doc->forceDelete();
                }
            }

            if (in_array($scope, ['all_data', 'delete_account'], true)) {
                $user->cvProfiles()->forceDelete();
            }

            if ($scope === 'delete_account') {
                $user->tokens()->delete();
                $user->preference()?->delete();
                $user->forceDelete();
            }
        });

        return response()->json([
            'message' => $scope === 'delete_account'
                ? 'Akun beserta seluruh data pribadi Anda telah dihapus secara permanen.'
                : 'Data yang dipilih berhasil dihapus secara permanen.',
        ]);
    }
}

