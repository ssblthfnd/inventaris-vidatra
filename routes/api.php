<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\SubcategoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  (prefix: /api)
|--------------------------------------------------------------------------
|
| Auth: Sanctum SPA cookie/session (guard `web`). Authorization: role Gates via
| the `can:` middleware. See docs/api_convention.md.
|
| Tahap 5.3 — read-only inventory + master-data endpoints. No write operations.
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
        Route::apiResource('assets', AssetController::class)
            ->only(['index', 'show'])
            ->names('api.assets');

        Route::apiResource('locations', LocationController::class)
            ->only(['index', 'show'])
            ->names('api.locations');

        Route::apiResource('categories', CategoryController::class)
            ->only(['index', 'show'])
            ->names('api.categories');

        Route::get('categories/{category}/subcategories', [SubcategoryController::class, 'index'])
            ->name('api.categories.subcategories.index');
        Route::get('subcategories/{subcategory}', [SubcategoryController::class, 'show'])
            ->name('api.subcategories.show');

        Route::get('locations/{location}/rooms', [RoomController::class, 'index'])
            ->name('api.locations.rooms.index');
        Route::get('rooms/{room}', [RoomController::class, 'show'])
            ->name('api.rooms.show');
    });
});
