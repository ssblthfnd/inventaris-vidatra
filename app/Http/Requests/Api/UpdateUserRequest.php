<?php

namespace App\Http\Requests\Api;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT|PATCH /api/users/{user}` (Tahap 6.4), `can:admin`.
 *
 * Edits `name` / `email` / `role` / `is_active` together — no partial-patch
 * "sometimes" semantics like {@see UpdateAssetRequest} (that exists there for
 * the batch-edit endpoint; there is no batch user edit). Password is
 * deliberately NOT accepted here at all — resetting it is a separate,
 * explicit action ({@see ResetUserPasswordRequest}) so editing e.g. just the
 * name can never accidentally change a password.
 *
 * Self-protection (Tahap 6.4 security requirement): the two closures below
 * fail with a targeted field error — never a generic 500/409 — whenever the
 * authenticated admin's OWN account is the one being edited and the request
 * would remove their own admin role or deactivate their own account. Checked
 * here (not in the controller/service) so it is validated the same way every
 * other field-level business rule in this app is (see {@see AssetIndexRequest}'s
 * closure-rule convention).
 */
class UpdateUserRequest extends FormRequest
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
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role' => ['required', Rule::enum(UserRole::class), $this->notSelfRoleDowngrade($target)],
            'is_active' => ['required', 'boolean', $this->notSelfDeactivation($target)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email tersebut sudah digunakan.',
        ];
    }

    private function notSelfRoleDowngrade(User $target): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($target): void {
            $actor = $this->user();
            if ($actor !== null && $actor->id === $target->id && $value !== UserRole::Admin->value) {
                $fail('Anda tidak dapat menurunkan peran akun Anda sendiri dari Admin.');
            }
        };
    }

    private function notSelfDeactivation(User $target): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($target): void {
            $actor = $this->user();
            if ($actor !== null && $actor->id === $target->id && filter_var($value, FILTER_VALIDATE_BOOLEAN) === false) {
                $fail('Anda tidak dapat menonaktifkan akun Anda sendiri.');
            }
        };
    }
}
