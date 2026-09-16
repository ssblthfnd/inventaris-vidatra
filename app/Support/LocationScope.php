<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Http\Controllers\Api\RoomController;
use App\Models\Location;
use App\Models\User;
use App\Policies\AssetPolicy;
use App\Services\Dashboard\DashboardService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Stage 6.9 R1 (foundation), wired into real read paths as of R4. Answers
 * exactly one question: which `locations.code` values may a given user act
 * on?
 *
 *   admin / super_admin / operator / viewer -> global (every code).
 *   unit_admin                              -> exactly the one code they're
 *                                              assigned to (never 01, never
 *                                              NULL, never inactive — see
 *                                              {@see for()}).
 *
 * R4 call sites: {@see AssetPolicy} (single-resource),
 * {@see FiltersAssets} (asset list/export/
 * report query scope, via {@see resolveFilterCodes()}),
 * {@see DashboardService} (aggregate scope), and
 * {@see RoomController} (room read scope). Every
 * other Stage 6.9 phase (master-data write / import / batch / revert scope)
 * still has this as an already-tested place to ask "is this location in
 * scope?" instead of growing its own `$user->location_code !== $thing->location_code`
 * check.
 *
 * Deliberately NOT a general-purpose scoping framework: it knows nothing
 * about assets, rooms, or any other model, only about location codes as
 * plain strings, and it makes no assumption that a future domain (e.g.
 * procurement) will reuse this exact shape — a global/single-code split is
 * correct for unit_admin today but is not assumed to fit every future scope.
 */
final class LocationScope
{
    private function __construct(
        private readonly bool $global,
        private readonly ?string $locationCode,
    ) {}

    /**
     * Resolve a user's scope. For `unit_admin`, this is where the security
     * invariant lives: an account whose `location_code` is NULL, `01`, or
     * anything outside {02,03,04} is a corrupt/impossible state, and it MUST
     * NOT silently resolve to "global" just because something went wrong.
     * Laravel's standard authorization-denial exception is thrown instead —
     * the same exception `Gate::authorize()`/`$this->authorize()` already
     * throw everywhere else in this app (yields a 403 wherever this ends up
     * inside a request), so a broken unit_admin account fails the same way
     * as a legitimate out-of-scope request rather than as a distinct 500.
     * Both cases mean "this actor may not proceed" — differentiating
     * "corrupt data" from "legitimate denial" is deferred until this class
     * is actually called from request handling in a later phase.
     */
    public static function for(User $user): self
    {
        if ($user->role !== UserRole::UnitAdmin) {
            return new self(global: true, locationCode: null);
        }

        $code = $user->location_code;

        if ($code === null || ! in_array($code, UserRole::UNIT_ADMIN_LOCATION_CODES, true)) {
            throw new AuthorizationException(
                "unit_admin user #{$user->id} has an invalid location_code ('".
                ($code ?? 'NULL')."') — must be exactly one of 02/03/04. Refusing ".
                'to resolve a scope for this account rather than risk treating it as global.'
            );
        }

        // Stage 6.9 R4 — a unit_admin's OWN assigned location must itself still
        // exist and be active. A location deactivated after assignment (or,
        // structurally impossible today but checked anyway, deleted — the FK
        // is RESTRICT so that alone can't happen) must never silently keep
        // granting scoped access, let alone widen into global access.
        $isActive = Location::query()->where('code', $code)->where('is_active', true)->exists();
        if (! $isActive) {
            throw new AuthorizationException(
                "unit_admin user #{$user->id}'s assigned location '{$code}' is inactive or no longer exists."
            );
        }

        return new self(global: false, locationCode: $code);
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    public function allows(string $locationCode): bool
    {
        return $this->global || $this->locationCode === $locationCode;
    }

    /**
     * @param  list<string>  $locationCodes
     * @return list<string>
     */
    public function filterCodes(array $locationCodes): array
    {
        if ($this->global) {
            return $locationCodes;
        }

        return array_values(array_filter(
            $locationCodes,
            fn (string $code): bool => $code === $this->locationCode
        ));
    }

    /**
     * Stage 6.9 R4 — the codes a `WHERE location_code IN (...)` filter should
     * actually use, given whatever the caller explicitly requested (e.g. a
     * `?location_code[]=...` query filter; an empty array means "nothing
     * explicitly requested").
     *
     * Returns `null` to mean "apply no location constraint at all" — only
     * possible for a global scope with nothing explicitly requested (the
     * existing global-role behavior, unchanged). Every other case returns a
     * concrete (possibly empty) list the caller applies via `whereIn()`
     * unconditionally:
     *   - global + explicit request       -> the request, verbatim.
     *   - unit_admin + no explicit request -> exactly its own one location
     *     (scope is never optional for unit_admin, unlike a global role).
     *   - unit_admin + explicit request    -> the request intersected with
     *     its own location — an out-of-scope request (e.g. `?location_code=03`
     *     for a `02` actor) naturally becomes `[]`, and `whereIn(col, [])`
     *     matches zero rows, which is exactly "the filter can never widen
     *     scope" without the caller needing a separate empty-list special case.
     *
     * @param  list<string>  $requestedCodes
     * @return list<string>|null
     */
    public function resolveFilterCodes(array $requestedCodes): ?array
    {
        if ($this->global) {
            return $requestedCodes === [] ? null : $requestedCodes;
        }

        return $requestedCodes === [] ? [$this->locationCode] : $this->filterCodes($requestedCodes);
    }

    /**
     * @param  string|list<string>  $locationCodes
     *
     * @throws AuthorizationException if any supplied code is outside scope.
     *                                Never throws for a global scope.
     */
    public function assertAllowed(string|array $locationCodes): void
    {
        if ($this->global) {
            return;
        }

        foreach ((array) $locationCodes as $code) {
            if (! $this->allows($code)) {
                throw new AuthorizationException("Location '{$code}' is outside this user's scope.");
            }
        }
    }
}
