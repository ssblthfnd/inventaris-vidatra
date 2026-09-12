<?php

namespace App\Support;

use RuntimeException;

/**
 * Tahap 6.6 — H-2: refuses to let the application boot with a dangerous
 * production configuration (`APP_ENV=production` and `APP_DEBUG=true`
 * simultaneously), which would leak stack traces — full server file paths,
 * exception internals — to any caller who triggers an error (confirmed live
 * during the Stage 6.6 Pass 1 audit).
 *
 * Deliberately a plain static method, not tied to booting a real Application
 * instance, so it is directly unit-testable with arbitrary (env, debug) pairs
 * without needing a second app bootstrap or touching the real `.env` — this
 * project's actual `.env` stays `APP_ENV=local` + `APP_DEBUG=true`, which is
 * explicitly fine (see the guard's own logic: only `production` is ever
 * rejected). Wired into the real boot path via `AppServiceProvider::boot()`,
 * so a genuine misconfigured production deploy fails fast at startup instead
 * of silently serving debug output.
 */
final class DeploymentSafety
{
    /**
     * @throws RuntimeException when $env is "production" and $debug is true
     */
    public static function assertDebugSafety(string $env, bool $debug): void
    {
        if ($env === 'production' && $debug === true) {
            throw new RuntimeException(
                'Refusing to boot: APP_ENV=production with APP_DEBUG=true would leak stack traces '.
                'to every caller. Set APP_DEBUG=false (or APP_ENV to a non-production value) before deploying.'
            );
        }
    }
}
