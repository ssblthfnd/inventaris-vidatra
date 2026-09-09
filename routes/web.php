<?php

use Illuminate\Support\Facades\Route;

/*
| The React SPA is served for every non-API path. `/api/*` is deliberately
| EXCLUDED so an unknown or wrong-method API request produces a JSON 404 / 405
| instead of falling through to the SPA HTML shell (see docs/api_convention.md §7).
*/
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '^(?!api($|/)).*$');
