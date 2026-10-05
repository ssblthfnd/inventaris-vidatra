<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\ImportBatch;
use App\Models\Room;
use App\Models\User;
use App\Policies\AssetPolicy;
use App\Policies\ImportBatchPolicy;
use App\Policies\RoomPolicy;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Authorization foundation for the inventory API (Tahap 5.0; extended by
 * Stage 6.9 R1).
 *
 * Role model (data/reference/schema_design.md §9):
 *   viewer   → read-only
 *   operator → read + write assets / mutations / imports / room aliases
 *   admin    → everything, incl. user & structural master-data management
 *
 * Stage 6.9 adds `super_admin`/`unit_admin` (App\Enums\UserRole) but does
 * NOT touch these three legacy Gates or what satisfies them: `admin` alone
 * still satisfies `admin`, `admin`/`operator` still satisfy `operator`, so a
 * `unit_admin` (or `super_admin`) account passes NONE of them.
 *
 * Stage 6.9 R9.3 — authorization is now WHO (role) / WHERE
 * (App\Support\LocationScope) / WHAT (a named ability from
 * App\Support\PermissionRegistry, registered in the loop below). No route
 * or controller uses the legacy `operator`/`admin` Gates anymore (they only
 * ever matched literal `admin`/`operator` and therefore silently blocked
 * `super_admin`); `tests/Feature/Api/Stage693AuthorizationConsolidationTest`
 * fails if a route reintroduces them. They stay defined, unchanged, only so
 * their own long-standing unit tests keep describing exactly what they
 * mean — new code must use a named ability instead. `viewer` ("any active
 * user") is still the read group's route gate.
 *
 * An INACTIVE user always fails every Gate, including every named ability
 * below.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Any authenticated + active user may read.
        Gate::define('viewer', fn (User $user) => $user->is_active === true);

        // LEGACY (unused by any route since Stage 6.9 R9.3 — see class docblock).
        Gate::define('operator', fn (User $user) => $user->is_active === true && $user->canWriteInventory());

        // LEGACY (unused by any route since Stage 6.9 R9.3 — see class docblock).
        Gate::define('admin', fn (User $user) => $user->is_active === true && $user->isAdmin());

        // Stage 6.9 R1 — named abilities (App\Support\PermissionRegistry). As of
        // R9.3 these gate every non-read route in routes/api.php.
        foreach (PermissionRegistry::ABILITIES as $ability) {
            Gate::define($ability, fn (User $user) => $user->is_active === true && PermissionRegistry::has($user->role, $ability));
        }

        // Stage 6.9 R4 — single-resource asset authorization (App\Policies\AssetPolicy).
        // Not auto-discovered: this AuthServiceProvider extends the plain
        // Illuminate ServiceProvider, not Laravel's Foundation Auth base class,
        // so policy resolution is registered explicitly here.
        Gate::policy(Asset::class, AssetPolicy::class);

        // Stage 6.9 R6 — single-resource import-batch authorization
        // (App\Policies\ImportBatchPolicy; location scope since R9.4-07 D2).
        Gate::policy(ImportBatch::class, ImportBatchPolicy::class);

        // Stage 6.9 R7 — single-resource room authorization (App\Policies\RoomPolicy).
        Gate::policy(Room::class, RoomPolicy::class);
    }
}
