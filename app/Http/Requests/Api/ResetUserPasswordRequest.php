<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /api/users/{user}/reset-password` (Tahap 6.4), `can:users.manage`
 * (Stage 6.9 R2 — was `can:admin`; today only `admin`/`super_admin` satisfy
 * that ability).
 *
 * A deliberate, separate action (not a side effect of `PUT /api/users/{user}`)
 * so an admin editing e.g. just the name can never accidentally change a
 * password. No email-based reset flow exists in this application (no mailer
 * configured for it, no `password_reset_tokens` usage anywhere in the app
 * layer) — this is the minimum mechanism for an admin to directly establish a
 * new password, matching the stage's explicit instruction not to build one.
 *
 * No self-protection rule here: an admin resetting their OWN password is a
 * normal, safe action (they choose and immediately know the new password),
 * unlike deactivating/demoting themselves which would lock them out blind.
 */
class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: auth:sanctum + auth.active + can:users.manage
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Tahap 6.6 (M-5) — same policy as StoreUserRequest; see its own
            // comment for why min(12) length-only, no uncompromised().
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ];
    }
}
