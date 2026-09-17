<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AssetExportController;
use App\Http\Controllers\Api\AssetLabelController;
use App\Http\Controllers\Api\AssetMutationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ImportTemplateController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MutationRevertController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoomAliasController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SubcategoryController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  (prefix: /api)
|--------------------------------------------------------------------------
|
| Auth: Sanctum SPA cookie/session (guard `web`). Authorization: role Gates via
| the `can:` middleware. See docs/api_convention.md.
|
| Tahap 5.3 — read-only inventory + master-data endpoints  (can:viewer).
| Tahap 5.4 — asset write API & lifecycle                   (can:operator).
| Tahap 5.5 — read-only mutation history                    (can:viewer).
| Tahap 5.6 — read-only dashboard aggregation               (can:viewer).
|
*/

// --- guest ---
// throttle:login (Tahap 6.6, H-1) — see AppServiceProvider::configureLoginRateLimiter()
// for the dual email+IP / IP-only limit design.
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('api.login');

// --- authenticated + active ---
Route::middleware(['auth:sanctum', 'auth.active'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.me');

    // --- read API: any active user (viewer / operator / admin) ---
    Route::middleware('can:viewer')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('api.dashboard');

        Route::get('assets', [AssetController::class, 'index'])->name('api.assets.index');
        // ->withTrashed(): operator/admin can read a soft-deleted asset (to restore it);
        // AssetController::show() still 404s it for a viewer. (Tahap 5.8.5)
        // ->whereNumber('asset') (Tahap 6.2): asset ids are always numeric — this is
        // what lets the literal `assets/export` route below win over this wildcard
        // instead of Eloquent trying (and failing) to bind an Asset with id "export".
        Route::get('assets/{asset}', [AssetController::class, 'show'])->name('api.assets.show')->withTrashed()->whereNumber('asset');
        // ->withTrashed() (Tahap 5.8.9): history is meaningful for a deleted asset too —
        // unlike show()'s deliberate viewer restriction above, EVERY active role
        // (including viewer) can read a trashed asset's mutation history. Read-only;
        // grants no lifecycle/mutation permission.
        Route::get('assets/{asset}/mutations', [AssetMutationController::class, 'index'])
            ->name('api.assets.mutations.index')
            ->withTrashed()
            ->whereNumber('asset');

        Route::get('locations', [LocationController::class, 'index'])->name('api.locations.index');
        Route::get('locations/{location}', [LocationController::class, 'show'])->name('api.locations.show');

        Route::get('categories', [CategoryController::class, 'index'])->name('api.categories.index');
        Route::get('categories/{category}', [CategoryController::class, 'show'])->name('api.categories.show');

        Route::get('categories/{category}/subcategories', [SubcategoryController::class, 'index'])
            ->name('api.categories.subcategories.index');
        Route::get('subcategories/{subcategory}', [SubcategoryController::class, 'show'])
            ->name('api.subcategories.show');

        Route::get('locations/{location}/rooms', [RoomController::class, 'index'])
            ->name('api.locations.rooms.index');
        Route::get('rooms/{room}', [RoomController::class, 'show'])->name('api.rooms.show');

        // Room aliases (Tahap 6.8.2) — read is viewer-open like every other
        // master-data list; write is `can:operator` below (see
        // RoomAliasController's docblock for why aliases differ from the
        // rest of Tahap 6.8's admin-only master data).
        Route::get('room-aliases', [RoomAliasController::class, 'index'])->name('api.room-aliases.index');
    });

    // --- reporting (Tahap 6.3; Stage 6.9 R4) — `can:assets.report`, NOT
    // `can:operator`: unlike every other endpoint in the `can:operator` group
    // below, this one deliberately also admits `unit_admin` (the named
    // ability already included it in App\Support\PermissionRegistry's map
    // since R1) while still excluding `viewer` exactly as before — moving
    // this single route off `can:operator` does NOT touch canWriteInventory()
    // or grant unit_admin anything else in that group. Same filter
    // vocabulary as `GET /api/assets` / `GET /api/assets/export`
    // (AssetIndexRequest via FiltersAssets, which is where the actual
    // location scoping happens); see ReportController.
    Route::middleware('can:assets.report')->group(function () {
        Route::get('reports/inventory', [ReportController::class, 'index'])->name('api.reports.inventory');
    });

    // --- asset write API (Tahap 5.4/5.8; Stage 6.9 R5) — each action regated
    // from `can:operator` to its own named `can:assets.*` ability so
    // `unit_admin` can reach them too (App\Support\PermissionRegistry already
    // grants unit_admin these abilities). For admin/super_admin/operator this
    // is a no-op (same allow/deny outcome as `can:operator` before); actual
    // per-asset/per-batch LOCATION authorization (WHERE, not WHAT) happens
    // inside AssetController (via AssetPolicy) / AssetWriteService for every
    // action below — the route gate alone never grants cross-unit access. ---
    Route::middleware('can:assets.create')->group(function () {
        Route::post('assets', [AssetController::class, 'store'])->name('api.assets.store');
        Route::post('assets/batch', [AssetController::class, 'storeBatch'])->name('api.assets.store-batch');
    });

    Route::middleware('can:assets.edit')->group(function () {
        // ->whereNumber('asset') (Tahap 6.6, M-6): applied consistently to every
        // assets/{asset} route now, not just the GET show route — previously only
        // that one had it, so a non-numeric/negative id on these write routes fell
        // through to a DIFFERENT route (none of which excluded it), producing a
        // confusing 405 "Method Not Allowed" instead of a clean 404 (the audit's
        // live probe: `PUT /api/assets/-1` -> 405). Eloquent binding still 404s a
        // genuinely-missing numeric id exactly as before — this only rejects
        // non-numeric input earlier and more consistently.
        Route::match(['put', 'patch'], 'assets/{asset}', [AssetController::class, 'update'])->name('api.assets.update')->whereNumber('asset');
    });

    Route::middleware('can:assets.batchEdit')->group(function () {
        Route::patch('assets/batch', [AssetController::class, 'batchUpdate'])->name('api.assets.update-batch');
    });

    Route::middleware('can:assets.delete')->group(function () {
        Route::delete('assets/batch', [AssetController::class, 'batchDestroy'])->name('api.assets.destroy-batch');
        Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->name('api.assets.destroy')->whereNumber('asset');
    });

    Route::middleware('can:assets.restore')->group(function () {
        // restore resolves a soft-deleted asset — the only route that does
        Route::post('assets/{asset}/restore', [AssetController::class, 'restore'])
            ->withTrashed()
            ->whereNumber('asset')
            ->name('api.assets.restore');
    });

    Route::middleware('can:assets.writeOff')->group(function () {
        Route::post('assets/{asset}/write-off', [AssetController::class, 'writeOff'])->name('api.assets.write-off')->whereNumber('asset');
        Route::post('assets/{asset}/unwrite-off', [AssetController::class, 'unwriteOff'])->name('api.assets.unwrite-off')->whereNumber('asset');
    });

    Route::middleware('can:assets.revert')->group(function () {
        // Mutation revert/undo (Tahap 6.5). `{mutation}` binds a `MutationLog` row
        // directly (that table is append-only, never soft-deleted, so no
        // `->withTrashed()` is needed here) — the mutation being reverted may be the
        // very SOFT_DELETE that trashed its asset (reverting it means restoring);
        // MutationRevertService itself loads the asset `withTrashed()` and does the
        // real per-field conflict check.
        Route::post('mutations/{mutation}/revert', [MutationRevertController::class, 'revert'])
            ->name('api.mutations.revert');
    });

    // --- import (Tahap 6.1; Stage 6.9 R6) — `can:assets.import`, NOT
    // `can:operator`: admits `unit_admin` too, location-scoped (staging and
    // promotion both independently enforce it — see ImportManager /
    // AssetPromoter). `imports` (the cross-batch history LIST) deliberately
    // stays on `can:operator` below — unit_admin does not get a global
    // import-history browser in this phase. `imports/template` is
    // registered BEFORE `imports/{batch}` so the literal path wins over the
    // wildcard. ---
    Route::middleware('can:assets.import')->group(function () {
        Route::get('imports/template', [ImportTemplateController::class, 'show'])->name('api.imports.template');
        Route::post('imports', [ImportController::class, 'store'])->name('api.imports.store');
        Route::get('imports/{batch}', [ImportController::class, 'show'])->name('api.imports.show');
        Route::get('imports/{batch}/rows', [ImportController::class, 'rows'])->name('api.imports.rows');
        Route::post('imports/{batch}/promote', [ImportController::class, 'promote'])->name('api.imports.promote');
        Route::get('imports/{batch}/report', [ImportController::class, 'report'])->name('api.imports.report');
    });

    // --- everything else that was, and remains, operator/admin only:
    // labels, export, import HISTORY LIST, room aliases — Stage 6.9 R5/R6
    // deliberately do NOT touch these gates, so unit_admin stays fully
    // blocked from all of them. ---
    Route::middleware('can:operator')->group(function () {
        // Printable label PDF (Tahap 6.0; ?size=small|medium|large added Tahap 6.0.2,
        // default small). No ->withTrashed(): a soft-deleted asset is not something
        // the app prints a fresh label for (see AssetLabelController).
        Route::get('assets/{asset}/label', [AssetLabelController::class, 'show'])->name('api.assets.label.show')->whereNumber('asset');
        Route::post('assets/batch/label', [AssetLabelController::class, 'batch'])->name('api.assets.label.batch');

        // Excel export (Tahap 6.2) — reuses the exact `GET /api/assets` filter
        // vocabulary (AssetIndexRequest) via FiltersAssets; see AssetExportController.
        // Deliberately left on `can:operator` (Stage 6.9 R4/R6 do not add export
        // scope) — unit_admin stays fully blocked from export here, unchanged.
        Route::get('assets/export', [AssetExportController::class, 'export'])->name('api.assets.export');

        // Import history LIST (Tahap 6.1) — every batch across every user/
        // location; deliberately NOT scoped for unit_admin (Stage 6.9 R6 §
        // "Scope Control") — see ImportController's docblock.
        Route::get('imports', [ImportController::class, 'index'])->name('api.imports.index');

        // Room aliases (Tahap 6.8.2) — write side; read is `can:viewer` above.
        // Real DELETE (unlike every other master-data entity in Tahap 6.8):
        // RoomAlias is a leaf table with no dependents, see RoomAliasController.
        Route::post('room-aliases', [RoomAliasController::class, 'store'])->name('api.room-aliases.store');
        Route::match(['put', 'patch'], 'room-aliases/{roomAlias}', [RoomAliasController::class, 'update'])
            ->name('api.room-aliases.update');
        Route::delete('room-aliases/{roomAlias}', [RoomAliasController::class, 'destroy'])
            ->name('api.room-aliases.destroy');
    });

    // --- user management (Tahap 6.4; Stage 6.9 R2 — gate switched from
    // `can:admin` to the named `users.manage` ability, currently satisfied
    // by admin/super_admin only) — unit_admin/operator/viewer get 403 here,
    // before UserController is ever reached (no per-action check needed) ---
    Route::middleware('can:users.manage')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('api.users.index');
        Route::post('users', [UserController::class, 'store'])->name('api.users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('api.users.show');
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->name('api.users.update');
        // No DELETE route — see UserController's docblock for why (nullOnDelete
        // audit-attribution FKs; deactivation is the only lifecycle mechanism).
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->name('api.users.reset-password');
    });

    // --- room master-data management (Tahap 6.8.1; Stage 6.9 R7) ---
    // A separate `GET /rooms` (flat, all locations, active AND inactive) rather
    // than changing the existing viewer-facing `index()`/`show()` above — see
    // RoomController's docblock. No DELETE: deactivation (`is_active`) is the
    // only lifecycle mechanism, same precedent as user management.
    //
    // `GET /rooms` stays `can:admin` only — it is a flat, cross-location
    // (active AND inactive) browser, and unit_admin's existing R4 read scope
    // (`GET /api/locations/{location}/rooms`, `GET /api/rooms/{room}`) is the
    // read surface they get instead; giving them this endpoint too would leak
    // other units' room existence, which R7 does not ask for.
    Route::middleware('can:admin')->group(function () {
        Route::get('rooms', [RoomController::class, 'adminIndex'])->name('api.rooms.admin-index');
    });

    // `POST /rooms` / `PUT|PATCH /rooms/{room}` — regated from `can:admin` to
    // the named `can:rooms.manage` ability (Stage 6.9 R7) so `unit_admin` can
    // reach them too (App\Support\PermissionRegistry now grants unit_admin
    // this ability). Unchanged for admin/super_admin (both still `*`) and a
    // no-op for operator/viewer (neither has `rooms.manage`, same denial as
    // `can:admin` before). WHERE (is this room's location in the actor's
    // scope) is checked inside RoomController via RoomPolicy — the route
    // gate alone never grants cross-unit access.
    Route::middleware('can:rooms.manage')->group(function () {
        Route::post('rooms', [RoomController::class, 'store'])->name('api.rooms.store');
        Route::match(['put', 'patch'], 'rooms/{room}', [RoomController::class, 'update'])->name('api.rooms.update');
    });

    // --- location master-data management (Tahap 6.8.3) — admin only ---
    // Unlike rooms, no separate GET route: the existing `GET /locations` above
    // gained an opt-in `?include_inactive=1` (honoured only for `can:admin`)
    // instead — see LocationController's docblock for why. No DELETE: same
    // is_active-only lifecycle precedent as every other Tahap 6.8 entity
    // except room aliases.
    Route::middleware('can:admin')->group(function () {
        Route::post('locations', [LocationController::class, 'store'])->name('api.locations.store');
        Route::match(['put', 'patch'], 'locations/{location}', [LocationController::class, 'update'])
            ->name('api.locations.update');
    });

    // --- category master-data management (Tahap 6.8.4) — admin only ---
    // Same opt-in `?include_inactive=1` pattern as locations — see
    // CategoryController's docblock. No DELETE.
    Route::middleware('can:admin')->group(function () {
        Route::post('categories', [CategoryController::class, 'store'])->name('api.categories.store');
        Route::match(['put', 'patch'], 'categories/{category}', [CategoryController::class, 'update'])
            ->name('api.categories.update');
    });

    // --- subcategory master-data management (Tahap 6.8.4) — admin only ---
    // A separate `GET /subcategories` (flat, all categories, active AND
    // inactive) rather than changing the existing nested `index()` — same
    // pattern as rooms, see SubcategoryController's docblock. No DELETE.
    Route::middleware('can:admin')->group(function () {
        Route::get('subcategories', [SubcategoryController::class, 'adminIndex'])
            ->name('api.subcategories.admin-index');
        Route::post('subcategories', [SubcategoryController::class, 'store'])->name('api.subcategories.store');
        Route::match(['put', 'patch'], 'subcategories/{subcategory}', [SubcategoryController::class, 'update'])
            ->name('api.subcategories.update');
    });
});
