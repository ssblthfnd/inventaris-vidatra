<?php

namespace App\Policies;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Models\Asset;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use App\Support\LocationScope;

/**
 * Stage 6.9 R4 — the first (and, for this phase, only) Laravel Policy in the
 * app. Single-resource authorization for one `Asset`, per R4's recommended
 * split: list/batch/query-level scope stays in {@see LocationScope} /
 * {@see FiltersAssets} directly; a
 * decision about ONE already-resolved asset belongs here.
 *
 * `view` is the only ability defined — R4 is read-only. Write abilities
 * (`update`, `delete`, `writeOff`, `restore`, ...) are deliberately NOT
 * defined here yet; a later phase (R5) adds them without needing to touch
 * this method, and until they exist, `$user->can('update', $asset)` etc.
 * would simply be undefined (not silently true) if anything tried to call
 * them today — nothing does.
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
}
