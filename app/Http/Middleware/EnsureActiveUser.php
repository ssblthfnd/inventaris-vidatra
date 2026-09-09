<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects an authenticated user whose account has been deactivated
 * (`users.is_active = false`) after their session was established.
 *
 * Runs AFTER `auth:sanctum`. Alias: `auth.active` (see bootstrap/app.php).
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->is_active !== true) {
            abort(403, 'Akun Anda telah dinonaktifkan.');
        }

        return $next($request);
    }
}
