<?php

namespace App\Http\Requests\Api;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PermissionRegistry;
use App\Support\UserLocationValidator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT|PATCH /api/users/{user}` (Tahap 6.4), `can:users.manage` (Stage 6.9
 * R2 — was `can:admin`; today only `admin`/`super_admin` satisfy that
 * ability, so behavior for the two legacy roles is unchanged).
 *
 * Edits `name` / `email` / `role` / `is_active` together — no partial-patch
 * "sometimes" semantics like {@see UpdateAssetRequest} (that exists there for
 * the batch-edit endpoint; there is no batch user edit). Password is
 * deliberately NOT accepted here at all — resetting it is a separate,
 * explicit action ({@see ResetUserPasswordRequest}) so editing e.g. just the
 * name can never accidentally change a password.
 *
 * `location_code` (Stage 6.9 R2) IS `sometimes` — unlike every other field
 * here, an older/unaware client may simply never send it. Its structural
 * validity (must match the FINAL role, not just whatever was literally in
 * this request) is therefore checked in {@see withValidator()}, which merges
 * an omitted `location_code` with the target's current value before
 * evaluating the pair — never merges an EXPLICIT null, only a fully absent
 * key (see that method's docblock).
 *
 * Self-protection (Tahap 6.4 security requirement, generalized in Stage 6.9
 * R2, further adjusted at the end of R2): the two closures below fail with a
 * targeted field error — never a generic 500/409 — whenever the
 * authenticated actor's OWN account is the one being edited and the request
 * would deactivate their own account, or change their own role in a way
 * that's disallowed for their CURRENT role specifically:
 *   - legacy `admin` self-editing: role must stay exactly `admin` — no
 *     self-migration to anything else, `super_admin` included (see
 *     {@see notSelfRoleDowngrade()}'s own docblock for why this can't be a
 *     generic "no users.manage holder may change their own role" rule).
 *   - every other `users.manage` holder (today: `super_admin`) self-editing:
 *     the new role must still hold `users.manage` — unchanged from R2.
 * Checked here (not in the controller/service) so it is validated the same
 * way every other field-level business rule in this app is (see
 * {@see AssetIndexRequest}'s closure-rule convention).
 */
class UpdateUserRequest extends FormRequest
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
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role' => ['required', Rule::enum(UserRole::class), $this->notSelfRoleDowngrade($target)],
            'location_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'is_active' => ['required', 'boolean', $this->notSelfDeactivation($target)],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email tersebut sudah digunakan.',
        ];
    }

    /**
     * Stage 6.9 R2 — evaluates the FINAL (role, location_code) pair, not each
     * field independently: an omitted `location_code` is merged with the
     * target's current value first (matching PATCH's own "unspecified means
     * unchanged" semantics), then checked against the FINAL `role` (which is
     * always present — `role` stays `required` above, unchanged from before
     * R2). Skipped entirely if `role`/`location_code` already failed their
     * own field rules, to avoid piling a confusing second error on top of an
     * already-invalid value.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['role', 'location_code'])) {
                return;
            }

            /** @var User $target */
            $target = $this->route('user');
            $role = UserRole::from($this->input('role'));
            $locationCode = $this->has('location_code') ? $this->input('location_code') : $target->location_code;

            if ($locationCode !== null && ! is_string($locationCode)) {
                return; // malformed type — the 'string' field rule already reports it
            }

            $message = UserLocationValidator::validate($role, $locationCode);
            if ($message !== null) {
                $validator->errors()->add('location_code', $message);
            }
        });
    }

    /**
     * Stage 6.9 R2 (final adjustment) — legacy `admin` is a TRANSITIONAL
     * role, not a durable identity (`super_admin` is its intended eventual
     * replacement — see UserRole's docblock), so an `admin` editing
     * THEMSELVES may not change their own role AT ALL, `admin -> super_admin`
     * included, even though `super_admin` also holds `users.manage` and
     * would otherwise pass the generalized check below. This distinction is
     * scoped to the CURRENT actor being legacy `admin` specifically — it
     * must never become "no users.manage holder can change their own role",
     * which would incorrectly also lock out `super_admin`'s own (still
     * intentionally permitted) self-edits handled in the `else` branch.
     */
    private function notSelfRoleDowngrade(User $target): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($target): void {
            $actor = $this->user();
            if ($actor === null || $actor->id !== $target->id) {
                return;
            }

            $newRole = UserRole::tryFrom((string) $value);
            if ($newRole === null) {
                return; // role's own Rule::enum() already reports this
            }

            if ($actor->role === UserRole::Admin) {
                if ($newRole !== UserRole::Admin) {
                    $fail('Sebagai Admin (legacy), Anda tidak dapat mengubah peran akun Anda sendiri.');
                }

                return;
            }

            if (! PermissionRegistry::has($newRole, 'users.manage')) {
                $fail('Anda tidak dapat menurunkan peran akun Anda sendiri sehingga kehilangan akses pengelolaan pengguna.');
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
