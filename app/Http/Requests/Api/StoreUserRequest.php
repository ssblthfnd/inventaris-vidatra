<?php

namespace App\Http\Requests\Api;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /api/users` (Tahap 6.4), `can:admin`.
 *
 * Only an admin ever reaches this endpoint (route-level `can:admin` gate), so
 * there is no separate "can a non-admin create an admin" check here — a
 * non-admin cannot call this endpoint at all, for any role value.
 *
 * `password` is never returned anywhere — {@see User}'s `hashed`
 * cast hashes it automatically on assignment (the existing mechanism; this
 * request never calls `Hash::make()` itself, which would double-hash it).
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
            // Tahap 6.6 (M-5): raised from min(8). Deliberately length-only, no
            // forced composition rules (NIST 800-63B / modern guidance treats
            // length as the dominant factor over "must contain a symbol"-style
            // rules, which mostly just push users toward predictable patterns).
            // `uncompromised()` (breach-corpus check) was considered and
            // deliberately NOT added here — it requires a live call to the
            // HaveIBeenPwned API on every password set, which would put a
            // network dependency inside this app's own auth-adjacent flow and
            // risk flaking the test suite / offline local dev (rule: don't
            // disrupt local development). Worth revisiting once the
            // deployment's outbound network reliability is confirmed.
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email tersebut sudah digunakan.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ];
    }
}
