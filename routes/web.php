<?php

use App\Http\Controllers\AssetQrRedirectController;
use Illuminate\Support\Facades\Route;

/*
| Public QR-code redirect layer (Tahap 6.0). Registered BEFORE the SPA catch-all so
| it never falls through to the HTML shell. See AssetQrRedirectController for why
| this exists, why it uses asset.id, and why it deliberately does not require auth.
*/
Route::get('/a/{asset}', AssetQrRedirectController::class)
    ->whereNumber('asset')
    ->withTrashed()
    ->name('asset.qr-redirect');

/*
| The React SPA is served for every non-API path. `/api/*` is deliberately
| EXCLUDED so an unknown or wrong-method API request produces a JSON 404 / 405
| instead of falling through to the SPA HTML shell (see docs/api_convention.md §7).
*/
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '^(?!api($|/)).*$');
