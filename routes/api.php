<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes  (prefix: /api)
|--------------------------------------------------------------------------
|
| Tahap 5.0 — authentication foundation only. Inventory endpoints (assets,
| master data, mutations, imports, reports) arrive in Tahap 5.1+.
|
| Auth model: Sanctum SPA cookie/session (guard `web`). See docs/api_convention.md.
|
*/

// --- guest ---
Route::post('login', [AuthController::class, 'login'])->name('api.login');

// --- authenticated (active) ---
Route::middleware(['auth:sanctum', 'auth.active'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.me');
});
