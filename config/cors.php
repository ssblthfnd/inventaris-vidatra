<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Tahap 6.6 (M-2) — this app previously had NO published `config/cors.php`,
| so Laravel's framework-internal default applied: `allowed_origins => ['*']`.
| The Stage 6.6 Pass 1 audit confirmed live that `Access-Control-Allow-Origin:
| *` was being sent even for a spoofed `Origin: http://evil-attacker.test`.
|
| `allowed_origins` is deliberately NOT a hardcoded/invented production
| hostname — it is derived from `SANCTUM_STATEFUL_DOMAINS`, the env var this
| app ALREADY uses to say "these origins are trusted to authenticate via the
| Sanctum SPA cookie" (see `bootstrap/app.php`'s `statefulApi()` and
| `.env.example`). Reusing it here means: (a) no new env var to configure,
| (b) the set of "trusted to log in from" and "trusted to make cross-origin
| API calls with credentials" origins can never silently drift apart, and
| (c) when a real production domain exists, setting it ONCE in
| SANCTUM_STATEFUL_DOMAINS is enough — see .env.example for where that
| happens. `SANCTUM_STATEFUL_DOMAINS` entries are bare host[:port] (no
| scheme); both http/https variants are allowed here since that env var
| doesn't encode which scheme is meant.
|
| `supports_credentials` is true because this app's authentication IS
| cookie-based (Sanctum SPA) — pairing that with an explicit, non-wildcard
| origin list (rather than the previous `*` + implicit `false`) is the
| correct, Sanctum-documented posture: a wildcard origin can never legally be
| paired with credentials anyway (browsers reject it), so this was always
| the honest expression of what the app needs, not new exposure.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => collect(explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')))
        ->map(fn (string $host) => trim($host))
        ->filter()
        ->flatMap(fn (string $host) => ["http://{$host}", "https://{$host}"])
        ->values()
        ->all(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
