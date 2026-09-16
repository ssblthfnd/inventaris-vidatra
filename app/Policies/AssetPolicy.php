<?php

namespace App\Policies;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Models\Asset;
use App\Models\Room;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use App\Support\LocationScope;

/**
 * Stage 6.9 R4 (read) / R5 (write) — the single-resource authorization layer
 * for one `Asset`, per the approved split: list/batch/query-level scope
 * stays in {@see LocationScope} / {@see FiltersAssets} / the write services
 * directly; a decision about ONE already-resolved asset belongs here.
 *
 * Every method here answers ONLY the WHERE question (is this location in
 * this actor's scope) — never the WHAT question (does this role have this
 * ability at all). WHAT is the route-level `can:assets.*` gate
 * (App\Support\PermissionRegistry, wired in routes/api.php); a request only
 * reaches these methods after already passing that gate. This split matches
 * why every method body here is a thin call into {@see LocationScope}
 * rather than re-deriving role logic.
 */
class AssetPolicy
{
    /**
     * A global role sees every asset; a `unit_admin` sees only assets in
     * their own assigned location. Registered via `Gate::policy()` in
     * {@see AuthServiceProvider} — not auto-discovered, since
     * this app's AuthServiceProvider doesn't extend Laravel's own base class.
     */
    public function view(User $user, Asset $asset): bool
    {
        return LocationScope::for($user)->allows($asset->location_code);
    }

    /**
     * Stage 6.9 R5 — no existing `Asset` instance to check yet (nothing has
     * been created), so this takes the raw `location_code` the request wants
     * to create in. Called as `$user->can('create', [Asset::class, $locationCode])`
     * (Laravel's convention for a "no instance yet" ability) from
     * `AssetController::store()`/`storeBatch()`, BEFORE `AssetWriteService`
     * runs — a create has no existing row to lock, so there's no benefit to
     * checking inside the service instead of the controller.
     */
    public function create(User $user, string $locationCode): bool
    {
        return LocationScope::for($user)->allows($locationCode);
    }

    /**
     * Stage 6.9 R5 — checks the asset's CURRENT location, and, when a
     * request also supplies a (possibly changed) target `location_code`,
     * that too. For a global role both checks are always true (unchanged
     * behavior — a global role may still freely reassign an asset's
     * location_code exactly as before R5). For `unit_admin`, since their
     * scope is exactly one location, requiring BOTH the current and the
     * target location to be "allowed" collapses to "the target must equal
     * the asset's current location" — which is exactly the invariant Stage
     * 6.9 R5 requires: a unit_admin may edit their own asset, but can never
     * use the same field to transfer it to another unit. No separate
     * "is this a location change" branch is needed; this one check covers
     * both "can edit at all" and "can't transfer" uniformly.
     */
    public function update(User $user, Asset $asset, ?string $targetLocationCode = null): bool
    {
        $scope = LocationScope::for($user);

        if (! $scope->allows($asset->location_code)) {
            return false;
        }

        return $targetLocationCode === null || $scope->allows($targetLocationCode);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return LocationScope::for($user)->allows($asset->location_code);
    }

    public function restore(User $user, Asset $asset): bool
    {
        return LocationScope::for($user)->allows($asset->location_code);
    }

    /** Shared by both the write-off and unwrite-off actions — same underlying `assets.writeOff` ability, same location check either direction. */
    public function writeOff(User $user, Asset $asset): bool
    {
        return LocationScope::for($user)->allows($asset->location_code);
    }

    /**
     * Stage 6.9 R5 — explicit, independent second check for a room move:
     * both the asset's OWN location and the TARGET room's location must be
     * in scope. In practice this is already implied by `update()` above
     * (the room is required elsewhere to belong to the same `location_code`
     * being validated), but kept as its own named ability — matching the
     * approved design's explicit "two independent checks" requirement for
     * move-room — so the invariant is checked directly rather than only
     * relying on it falling out of a different method's logic.
     */
    public function moveRoom(User $user, Asset $asset, Room $targetRoom): bool
    {
        $scope = LocationScope::for($user);

        return $scope->allows($asset->location_code) && $scope->allows($targetRoom->location_code);
    }
}
