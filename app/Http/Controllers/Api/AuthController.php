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

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
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
