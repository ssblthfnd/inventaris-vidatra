<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\User;
use App\Policies\AssetPolicy;
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
 * These three Gates are the single source of truth actually used by
 * controllers (`$this->authorize()` / `Gate::allows()`) and by the `can:`
 * route middleware — unchanged by Stage 6.9 R1.
 *
 * Stage 6.9 adds `super_admin`/`unit_admin` (App\Enums\UserRole) but does
 * NOT touch these three Gates or what satisfies them: `admin` alone still
 * satisfies `admin`, `admin`/`operator` still satisfy `operator`, so a
 * `unit_admin` (or `super_admin`) account passes NONE of them yet. That is
 * intentional for this phase — see App\Support\LocationScope's docblock and
 * Stage 6.9's R1 critical security rule: a unit_admin account must never be
 * granted access through these existing Gates before location-scope
 * enforcement (a later phase) actually exists.
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

        // Operators and admins may create/modify inventory data.
        Gate::define('operator', fn (User $user) => $user->is_active === true && $user->canWriteInventory());

        // Admins only: user management and structural master-data changes.
        Gate::define('admin', fn (User $user) => $user->is_active === true && $user->isAdmin());

        // Stage 6.9 R1 — named-ability skeleton (App\Support\PermissionRegistry).
        // Defined so phases can adopt them one call site at a time. As of R2,
        // `users.manage` is the first ability actually wired into a route
        // (`can:users.manage` on the user-management group in routes/api.php)
        // — every other ability here is still unused by any route/controller.
        foreach (PermissionRegistry::ABILITIES as $ability) {
            Gate::define($ability, fn (User $user) => $user->is_active === true && PermissionRegistry::has($user->role, $ability));
        }

        // Stage 6.9 R4 — single-resource asset authorization (App\Policies\AssetPolicy).
        // Not auto-discovered: this AuthServiceProvider extends the plain
        // Illuminate ServiceProvider, not Laravel's Foundation Auth base class,
        // so policy resolution is registered explicitly here.
        Gate::policy(Asset::class, AssetPolicy::class);
    }
}
