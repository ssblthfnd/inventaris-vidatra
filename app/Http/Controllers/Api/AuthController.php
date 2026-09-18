<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum SPA (cookie / session) authentication — foundation only (Tahap 5.0).
 *
 * No bearer tokens. The React SPA calls `GET /sanctum/csrf-cookie` first, then
 * `POST /api/login`; the resulting first-party session cookie authenticates all
 * subsequent `/api/*` requests via `auth:sanctum` (guard `web`).
 */
class AuthController extends Controller
{
    /**
     * A precomputed, constant bcrypt hash of an unguessable, never-typed value —
     * NOT a real password or a DB value, and never regenerated per-request. Used
     * only so {@see Hash::check()} still runs its full cost when the email lookup
     * fails, so a nonexistent-email request takes about as long as a real-email
     * request with a wrong password (Tahap 6.9 R8.2, P3-1 — closes the timing
     * side-channel that previously let response latency alone reveal whether an
     * email exists, since `Hash::check()` was only reached on a successful lookup).
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$KrbT2JuT9mHngpjmJzcDJuojeSQDmGauBQYl90cCPfXfYj2lo2o9W';

    /**
     * POST /api/login
     *
     * 422 — missing/invalid input or wrong credentials (Laravel validation shape).
     * 403 — credentials valid but the account is deactivated.
     * 200 — session established; returns the current user.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        // Always call Hash::check(), even when no user was found — against the
        // real user's hash when one exists, otherwise against the constant dummy
        // hash above, so both branches pay the same bcrypt cost (P3-1).
        $hashToCheck = $user->password ?? self::DUMMY_PASSWORD_HASH;
        $passwordMatches = Hash::check($credentials['password'], $hashToCheck);

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')],
            ]);
        }

        if ($user->is_active !== true) {
            abort(403, 'Akun Anda telah dinonaktifkan.');
        }

        Auth::guard('web')->login($user, remember: false);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(200);
    }

    /**
     * POST /api/logout — ends the authenticated session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * GET /api/me — the currently authenticated (and active) user.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
