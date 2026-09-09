<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Authorization foundation for the inventory API (Tahap 5.0).
 *
 * Role model (data/reference/schema_design.md §9):
 *   viewer   → read-only
 *   operator → read + write assets / mutations / imports / room aliases
 *   admin    → everything, incl. user & structural master-data management
 *
 * These Gates are the single source of truth used by controllers
 * (`$this->authorize()` / `Gate::allows()`) and by the `can:` route
 * middleware in Tahap 5.1+. An INACTIVE user always fails every Gate.
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
    }
}
