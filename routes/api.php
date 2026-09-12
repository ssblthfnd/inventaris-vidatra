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
Route::post('login', [AuthController::class, 'login'])->name('api.login');

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
            ->withTrashed();

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
    });

    // --- write API: operator / admin (Gate `operator` already passes admins) ---
    Route::middleware('can:operator')->group(function () {
        Route::post('assets', [AssetController::class, 'store'])->name('api.assets.store');
        Route::post('assets/batch', [AssetController::class, 'storeBatch'])->name('api.assets.store-batch');
        Route::patch('assets/batch', [AssetController::class, 'batchUpdate'])->name('api.assets.update-batch');
        Route::match(['put', 'patch'], 'assets/{asset}', [AssetController::class, 'update'])->name('api.assets.update');
        Route::delete('assets/batch', [AssetController::class, 'batchDestroy'])->name('api.assets.destroy-batch');
        Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->name('api.assets.destroy');

        // restore resolves a soft-deleted asset — the only route that does
        Route::post('assets/{asset}/restore', [AssetController::class, 'restore'])
            ->withTrashed()
            ->name('api.assets.restore');

        Route::post('assets/{asset}/write-off', [AssetController::class, 'writeOff'])->name('api.assets.write-off');
        Route::post('assets/{asset}/unwrite-off', [AssetController::class, 'unwriteOff'])->name('api.assets.unwrite-off');

        // Mutation revert/undo (Tahap 6.5). `{mutation}` binds a `MutationLog` row
        // directly (that table is append-only, never soft-deleted, so no
        // `->withTrashed()` is needed here) — the mutation being reverted may be the
        // very SOFT_DELETE that trashed its asset (reverting it means restoring);
        // MutationRevertService itself loads the asset `withTrashed()` and does the
        // real per-field conflict check.
        Route::post('mutations/{mutation}/revert', [MutationRevertController::class, 'revert'])
            ->name('api.mutations.revert');

        // Printable label PDF (Tahap 6.0; ?size=small|medium|large added Tahap 6.0.2,
        // default small). No ->withTrashed(): a soft-deleted asset is not something
        // the app prints a fresh label for (see AssetLabelController).
        Route::get('assets/{asset}/label', [AssetLabelController::class, 'show'])->name('api.assets.label.show');
        Route::post('assets/batch/label', [AssetLabelController::class, 'batch'])->name('api.assets.label.batch');

        // Excel export (Tahap 6.2) — reuses the exact `GET /api/assets` filter
        // vocabulary (AssetIndexRequest) via FiltersAssets; see AssetExportController.
        Route::get('assets/export', [AssetExportController::class, 'export'])->name('api.assets.export');

        // Reporting / rekap inventaris (Tahap 6.3) — `can:operator`, unlike
        // `GET /api/dashboard` above which is `can:viewer`; a viewer is forbidden
        // here per this stage's explicit requirement. Same filter vocabulary as
        // `GET /api/assets` / `GET /api/assets/export` (AssetIndexRequest via
        // FiltersAssets); see ReportController.
        Route::get('reports/inventory', [ReportController::class, 'index'])->name('api.reports.inventory');

        // Import Excel UI (Tahap 6.1) — operator/admin only, viewer forbidden. A thin
        // HTTP layer over the pre-existing App\Import staging/promotion pipeline (see
        // ImportController); NEVER a second importer. `imports/template` is registered
        // BEFORE `imports/{batch}` so the literal path wins over the wildcard.
        Route::get('imports/template', [ImportTemplateController::class, 'show'])->name('api.imports.template');
        Route::get('imports', [ImportController::class, 'index'])->name('api.imports.index');
        Route::post('imports', [ImportController::class, 'store'])->name('api.imports.store');
        Route::get('imports/{batch}', [ImportController::class, 'show'])->name('api.imports.show');
        Route::get('imports/{batch}/rows', [ImportController::class, 'rows'])->name('api.imports.rows');
        Route::post('imports/{batch}/promote', [ImportController::class, 'promote'])->name('api.imports.promote');
        Route::get('imports/{batch}/report', [ImportController::class, 'report'])->name('api.imports.report');
    });

    // --- user management (Tahap 6.4) — admin only; operator gets 403 here ---
    Route::middleware('can:admin')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('api.users.index');
        Route::post('users', [UserController::class, 'store'])->name('api.users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('api.users.show');
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->name('api.users.update');
        // No DELETE route — see UserController's docblock for why (nullOnDelete
        // audit-attribution FKs; deactivation is the only lifecycle mechanism).
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->name('api.users.reset-password');
    });
});
