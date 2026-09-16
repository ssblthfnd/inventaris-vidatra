<?php

namespace App\Http\Requests\Api;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\UserLocationValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * `POST /api/users` (Tahap 6.4), `can:users.manage` (Stage 6.9 R2 — was
 * `can:admin`; today only `admin`/`super_admin` satisfy that ability, so
 * behavior for the two legacy roles is unchanged).
 *
 * Only a `users.manage`-holder ever reaches this endpoint (route-level gate),
 * so there is no separate "can this actor create a user with this role" check
 * here — anyone who can't already fails the route middleware, for any role
 * value.
 *
 * `password` is never returned anywhere — {@see User}'s `hashed`
 * cast hashes it automatically on assignment (the existing mechanism; this
 * request never calls `Hash::make()` itself, which would double-hash it).
 */
class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            // Stage 6.9 R2 — basic type sanity only; the actual (role,
            // location_code) combination rule runs in withValidator() below,
            // NOT as a closure here — Laravel skips closure-based rules
            // entirely for a field that's absent from the payload (unlike
            // `required`/`string`, a bare closure isn't "implicit"), so
            // `role=unit_admin` with no `location_code` key at all would
            // silently pass if the business rule lived here instead.
            'location_code' => ['nullable', 'string', 'size:2'],
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

    /**
     * Stage 6.9 R2 — {@see UserLocationValidator} against the role value in
     * THIS SAME request, run unconditionally (unlike a per-field closure
     * rule, an `after()` hook always runs regardless of whether
     * `location_code` was present in the payload at all). Skipped only if
     * `role`/`location_code` already failed their own field rules, to avoid
     * piling a confusing second error on top of an already-invalid value.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['role', 'location_code'])) {
                return;
            }

            $role = UserRole::from($this->input('role'));
            $locationCode = $this->input('location_code');

            $message = UserLocationValidator::validate($role, $locationCode);
            if ($message !== null) {
                $validator->errors()->add('location_code', $message);
            }
        });
    }
}
