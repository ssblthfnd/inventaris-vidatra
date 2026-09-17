<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Policies\RoomPolicy;
use App\Providers\AuthServiceProvider;

/**
 * Stage 6.9 R1 — foundation. Centralizes the intended WHAT (named ability)
 * mapping per role so controllers/FormRequests/frontend can convert from
 * scattered `isAdmin()`/`isOperator()` checks to `Gate::allows('assets.edit')`-
 * style ability checks, one call site at a time, without redefining what
 * each ability means each time.
 *
 * As of R4 (`users.manage`, `assets.report`) and R5 (the `assets.*` write
 * abilities), several of these are now wired into real routes/policies —
 * see {@see AuthServiceProvider::boot()} for the Gate registration and
 * routes/api.php for which named ability gates which route. The three
 * legacy Gates (`viewer`/`operator`/`admin`) that some routes still use are
 * untouched by this registry.
 *
 * This intentionally does NOT model location — "may this role ever perform
 * this action" (WHAT) is answered here; "in which location" (WHERE) is
 * {@see LocationScope}'s job, and a real per-request decision needs both:
 * an ability check alone (this class) can never grant unrestricted access
 * on its own, so every write path that checks an `assets.*` ability here
 * MUST also check `LocationScope` (via `AssetPolicy` for a single resource,
 * or directly for a batch/query).
 *
 * Not a general RBAC system: no DB table, no per-user overrides, no
 * dynamically assignable abilities — just one static array is the single
 * source of truth for "which roles are intended to have which named
 * abilities", kept in one file so it doesn't drift across call sites.
 */
final class PermissionRegistry
{
    /**
     * Every named ability this registry knows about. Stage 6.9 §8's list,
     * verbatim — controllers are not expected to use all of these yet.
     *
     * @var list<string>
     */
    public const ABILITIES = [
        'assets.view', 'assets.create', 'assets.edit', 'assets.batchEdit', 'assets.delete',
        'assets.writeOff', 'assets.restore', 'assets.revert', 'assets.moveRoom',
        'assets.import', 'assets.export', 'assets.report',
        'dashboard.view',
        'rooms.view', 'rooms.manage',
        'roomAliases.manage',
        'locations.manage', 'categories.manage', 'subcategories.manage',
        'users.manage',
    ];

    private const FULL_INVENTORY_AND_ROOMS = [
        'assets.view', 'assets.create', 'assets.edit', 'assets.batchEdit', 'assets.delete',
        'assets.writeOff', 'assets.restore', 'assets.revert', 'assets.moveRoom',
        'assets.import', 'assets.export', 'assets.report',
        'dashboard.view', 'rooms.view',
    ];

    /**
     * Stage 6.9 R5 — unit_admin's ACTUAL intended set, now that these
     * abilities are wired into real routes/policies. Deliberately NOT
     * {@see FULL_INVENTORY_AND_ROOMS}: unit_admin gets every scoped
     * inventory write ability, but explicitly none of `assets.export`
     * — that stays super_admin/admin/operator-only per Stage 6.9 R5 §2/§13.
     * (R1 originally reused FULL_INVENTORY_AND_ROOMS + rooms.manage for
     * unit_admin here, harmlessly, since none of these abilities were wired
     * to anything yet; R5 corrects it now that they are.)
     *
     * Stage 6.9 R6 adds `assets.import` — location-scoped (see
     * `ImportManager`/`AssetPromoter`) — while `assets.export` stays
     * excluded; export scope is explicitly a later phase.
     *
     * Stage 6.9 R7 adds `rooms.manage` — location-scoped room master-data
     * management (create/update/deactivate/reactivate a room in the actor's
     * own location, see {@see RoomPolicy}). Deliberately still
     * excludes `roomAliases.manage`: aliases stay a more sensitive
     * import-mapping mechanism, per R7 §"ROOM ALIAS — IMPORTANT" — this is
     * intentional, not an oversight.
     *
     * @var list<string>
     */
    private const UNIT_ADMIN_INVENTORY = [
        'assets.view', 'assets.create', 'assets.edit', 'assets.batchEdit', 'assets.delete',
        'assets.writeOff', 'assets.restore', 'assets.revert', 'assets.moveRoom',
        'assets.import', 'assets.report',
        'dashboard.view', 'rooms.view', 'rooms.manage',
    ];

    /**
     * Intended ability set per role. `'*'` is shorthand for every ability in
     * {@see ABILITIES} (used for `admin`/`super_admin`, which are meant to
     * be functionally equivalent in scope even though they are not yet
     * treated as equivalent for Gate purposes — see UserRole's docblock).
     *
     * @var array<string, list<string>|'*'>
     */
    private const MAP = [
        'super_admin' => '*',
        'admin' => '*',
        'unit_admin' => self::UNIT_ADMIN_INVENTORY,
        'operator' => [...self::FULL_INVENTORY_AND_ROOMS, 'roomAliases.manage'],
        'viewer' => ['assets.view', 'dashboard.view', 'rooms.view'],
    ];

    /**
     * Every ability {@see MAP} intends for this role — empty for an
     * unrecognized/legacy-missing role.
     *
     * Deliberately takes a bare `UserRole` rather than a `User` — this is a
     * pure "does this role have this ability" question with no dependency on
     * `is_active` or any other per-account state (the Gates in
     * AuthServiceProvider layer `is_active` on top themselves), which also
     * makes it usable for a hypothetical/not-yet-persisted role value, e.g.
     * {@see UpdateUserRequest}'s self-protection check
     * against a role value a request is only proposing.
     *
     * @return list<string>
     */
    public static function abilitiesForRole(UserRole $role): array
    {
        $abilities = self::MAP[$role->value] ?? [];

        return $abilities === '*' ? self::ABILITIES : $abilities;
    }

    public static function has(UserRole $role, string $ability): bool
    {
        $abilities = self::MAP[$role->value] ?? [];

        return $abilities === '*' || in_array($ability, $abilities, true);
    }
}
