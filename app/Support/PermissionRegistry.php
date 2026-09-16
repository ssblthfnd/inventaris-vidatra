<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use App\Providers\AuthServiceProvider;

/**
 * Stage 6.9 R1 — foundation/skeleton only. Centralizes the intended WHAT
 * (named ability) mapping per role so a later phase can convert controllers/
 * FormRequests/frontend from scattered `isAdmin()`/`isOperator()` checks to
 * `Gate::allows('assets.edit')`-style ability checks, one call site at a
 * time, without redefining what each ability means each time.
 *
 * NOT wired into any route, middleware, controller, or FormRequest yet — see
 * {@see AuthServiceProvider::boot()}, which registers each of
 * these as a Laravel Gate but nothing in the app calls them. The three
 * legacy Gates (`viewer`/`operator`/`admin`) that routes actually use today
 * are untouched.
 *
 * This intentionally does NOT model location — "may this role ever perform
 * this action" (WHAT) is answered here; "in which location" (WHERE) is
 * {@see LocationScope}'s job, and a real per-request decision needs both.
 * `unit_admin` is mapped here to the FULL inventory ability set (matching
 * the approved design's intent once location enforcement exists) precisely
 * because this layer alone can never grant unrestricted access — nothing in
 * the current codebase calls these abilities, and once something does, it
 * must always be introduced together with the matching LocationScope check.
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
        'unit_admin' => [...self::FULL_INVENTORY_AND_ROOMS, 'rooms.manage'],
        'operator' => [...self::FULL_INVENTORY_AND_ROOMS, 'roomAliases.manage'],
        'viewer' => ['assets.view', 'dashboard.view', 'rooms.view'],
    ];

    /** Every ability {@see MAP} intends for this user's role — empty for an unrecognized/legacy-missing role. @return list<string> */
    public static function abilitiesFor(User $user): array
    {
        $abilities = self::MAP[$user->role?->value] ?? [];

        return $abilities === '*' ? self::ABILITIES : $abilities;
    }

    public static function has(User $user, string $ability): bool
    {
        $abilities = self::MAP[$user->role?->value] ?? [];

        return $abilities === '*' || in_array($ability, $abilities, true);
    }
}
