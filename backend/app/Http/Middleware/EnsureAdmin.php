<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Akses ditolak. Halaman ini hanya dapat diakses oleh Administrator.',
                'error_code' => 'FORBIDDEN_ADMIN_ONLY',
            ], 403);
        }

        return $next($request);
    }
}

