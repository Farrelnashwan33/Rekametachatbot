<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\AI\AIProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request, AIProviderManager $aiManager): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $created = User::create([
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => $validated['password'],
                'role' => 'user',
                'is_active' => true,
                'last_login_at' => now(),
            ]);

            UserPreference::create([
                'user_id' => $created->id,
                'theme' => 'system',
                'language' => 'id',
                'communication_style' => 'balanced',
                'memory_enabled' => true,
                'memory_auto_save' => false,
                'voice_enabled' => true,
            ]);

            return $created;
        });

        $token = $user->createToken('rekametachat-web')->plainTextToken;

        return response()->json([
            'message' => 'Akun berhasil dibuat.',
            'token' => $token,
            'user' => $user->load('preference'),
            'ai_status' => $aiManager->getPublicStatus(),
        ], 201);
    }

    public function login(Request $request, AIProviderManager $aiManager): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Email atau kata sandi tidak sesuai.',
                'error_code' => 'INVALID_CREDENTIALS',
            ], 422);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun Anda sedang dinonaktifkan oleh administrator.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        $user->update(['last_login_at' => now()]);
        $user->preference()->firstOrCreate(['user_id' => $user->id]);

        $token = $user->createToken('rekametachat-web')->plainTextToken;

        return response()->json([
            'message' => 'Berhasil masuk.',
            'token' => $token,
            'user' => $user->load('preference'),
            'ai_status' => $aiManager->getPublicStatus(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Sesi berhasil diakhiri.',
        ]);
    }

    public function me(Request $request, AIProviderManager $aiManager): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->preference()->firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'user' => $user->load('preference'),
            'ai_status' => $aiManager->getPublicStatus(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::where('email', $email)->first();

        $genericMessage = 'Jika email terdaftar di sistem kami, instruksi pengaturan ulang kata sandi telah dikirimkan.';
        if (! $user) {
            return response()->json(['message' => $genericMessage]);
        }

        $plainToken = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        $frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $resetLink = "{$frontendUrl}/reset-password?email=".urlencode($email).'&token='.urlencode($plainToken);

        Mail::raw(
            "Halo {$user->name},\n\nGunakan tautan berikut untuk mengatur ulang kata sandi akun Rekametachat Anda (berlaku 60 menit):\n{$resetLink}\n\nAbaikan pesan ini jika Anda tidak meminta pengaturan ulang kata sandi.",
            function ($message) use ($user) {
                $message->to($user->email)->subject('Pengaturan Ulang Kata Sandi Rekametachat');
            }
        );

        $payload = ['message' => $genericMessage];

        // Only in local development with log mailer, expose reset parameters so local testing works without external SMTP
        if (app()->environment('local', 'testing') && config('mail.default') === 'log') {
            $payload['local_dev_reset_token'] = $plainToken;
        }

        return response()->json($payload);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record || ! Hash::check($validated['token'], $record->token)) {
            return response()->json([
                'message' => 'Token pengaturan ulang tidak valid atau sudah digunakan.',
                'error_code' => 'INVALID_RESET_TOKEN',
            ], 422);
        }

        if ($record->created_at && now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return response()->json([
                'message' => 'Token pengaturan ulang sudah kedaluwarsa. Silakan ajukan permintaan baru.',
                'error_code' => 'EXPIRED_RESET_TOKEN',
            ], 422);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json([
                'message' => 'Akun tidak ditemukan.',
            ], 404);
        }

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'message' => 'Kata sandi berhasil diperbarui. Silakan masuk dengan kata sandi baru Anda.',
        ]);
    }
}

