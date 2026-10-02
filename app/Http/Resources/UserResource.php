<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\LocationScope;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 *
 * R7.1 — gained `location_code` (additive; the exact-shape contract test in
 * `AuthFoundationTest::test_me_returns_the_authenticated_user` updated
 * alongside it). The frontend has no other way to learn "what is MY own
 * unit" for a `unit_admin` actor — every other consumer of this resource
 * (`POST /api/login`, `GET /api/me`) is the self-session bootstrap payload,
 * and a `unit_admin`'s own scoped UI (nav, location-locked filters, the
 * Rooms page) needs this value client-side. Always `null` for every
 * non-`unit_admin` role, mirroring the column itself.
 *
 * Stage 6.9 R9.3 — gained two more additive, self-session-only fields so the
 * SPA stops re-declaring its own role→capability matrix:
 *
 *   - `abilities`: exactly {@see PermissionRegistry::abilitiesForRole()} for
 *     this user's role (WHAT) — the same source the `can:<ability>` route
 *     Gates read, so a UI flag can never claim an ability the backend
 *     doesn't grant, or vice versa. Empty for an inactive account (every
 *     Gate denies an inactive user).
 *   - `is_global_scope`: whether {@see LocationScope} resolves this user to
 *     an unrestricted scope (WHERE). Needed by the few endpoints that are
 *     unscoped lists and therefore admit only a global actor (import
 *     history, room-alias list, flat room browser). Fails closed: a corrupt
 *     unit_admin account (which `LocationScope::for()` refuses to resolve)
 *     reports `false`, never `true`.
 *
 * UI-only information either way — every endpoint still re-checks both.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'location_code' => $user->location_code,
            'is_active' => $user->is_active,
            'abilities' => $user->is_active === true && $user->role !== null
                ? PermissionRegistry::abilitiesForRole($user->role)
                : [],
            'is_global_scope' => $this->isGlobalScope($user),
        ];
    }

    private function isGlobalScope(User $user): bool
    {
        try {
            return LocationScope::for($user)->isGlobal();
        } catch (AuthorizationException) {
            return false;
        }
    }
}
