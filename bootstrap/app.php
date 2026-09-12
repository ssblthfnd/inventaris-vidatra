<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie/session auth for /api/* requests from stateful domains.
        $middleware->statefulApi();

        // This is an API + same-origin SPA: there is no server-rendered login page.
        // Never redirect an unauthenticated request — the exception handler returns a
        // JSON 401 for /api/* (see withExceptions below). Without this, the framework
        // default `route('login')` throws RouteNotFoundException for non-JSON requests.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'auth.active' => EnsureActiveUser::class,
        ]);

        // Tahap 6.6 (M-1 / M-4) — baseline security response headers on every
        // request, web (SPA shell) and api alike. `append()` (not `web()`/
        // `api()`) adds it once to the shared global stack both groups run
        // through, rather than duplicating it into each group separately.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* request (and anything expecting JSON) gets a JSON error body —
        // 401 / 403 / 404 / 405 / 422 / 500 — never an HTML page or the SPA shell.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
