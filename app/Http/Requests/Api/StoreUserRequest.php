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
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
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
