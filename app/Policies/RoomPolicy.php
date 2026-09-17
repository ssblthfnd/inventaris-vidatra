<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;
use App\Support\LocationScope;

/**
 * Stage 6.9 R7 — single-resource authorization for one `Room`, matching the
 * same thin Policy pattern {@see AssetPolicy} established in R4/R5: this
 * answers ONLY the WHERE question (is this room's location in the actor's
 * scope), never WHAT (does this role have `rooms.manage` at all — that is
 * the route-level `can:rooms.manage` gate, App\Support\PermissionRegistry).
 *
 * A global role (super_admin/admin/operator/viewer, whichever pass the
 * `rooms.manage` gate) may act on any room; `unit_admin` may act only within
 * their own assigned location.
 *
 * `location_code` is immutable on `Room` (see `UpdateRoomRequest` — always
 * `prohibited`), so unlike `AssetPolicy::update()` there is no "target
 * location" to check separately: a room can never be transferred between
 * locations by anyone, so checking the room's current location alone is
 * sufficient for both create-time and update-time authorization.
 */
class RoomPolicy
{
    /**
     * No existing `Room` instance yet — called as
     * `$user->can('create', [Room::class, $locationCode])` (Laravel's
     * convention for a "no instance yet" ability), matching
     * `AssetPolicy::create()`.
     */
    public function create(User $user, string $locationCode): bool
    {
        return LocationScope::for($user)->allows($locationCode);
    }

    /** Covers update, deactivate, and reactivate — all the same `PATCH/PUT /api/rooms/{room}` action. */
    public function update(User $user, Room $room): bool
    {
        return LocationScope::for($user)->allows($room->location_code);
    }
}
