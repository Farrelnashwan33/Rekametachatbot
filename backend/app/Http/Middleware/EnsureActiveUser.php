<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            return response()->json([
                'message' => 'Akun Anda sedang dinonaktifkan. Hubungi administrator untuk informasi lebih lanjut.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        return $next($request);
    }
}

