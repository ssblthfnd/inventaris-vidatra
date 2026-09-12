<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /api/users/{user}/reset-password` (Tahap 6.4), `can:admin`.
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
        return true; // route middleware: auth:sanctum + auth.active + can:admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ];
    }
}
