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
 * Tahap 6.7.1 — S67-10 extended this with a second, same-shaped guard:
 * `APP_ENV=production` with `LOG_LEVEL=debug` is also refused (see
 * `assertLogLevelSafety()`).
 *
 * Deliberately plain static methods, not tied to booting a real Application
 * instance, so each is directly unit-testable with arbitrary argument pairs
 * without needing a second app bootstrap or touching the real `.env` — this
 * project's actual `.env` stays `APP_ENV=local` + `APP_DEBUG=true` +
 * `LOG_LEVEL=debug`, which is explicitly fine (both guards only ever reject
 * `production`). Wired into the real boot path via `AppServiceProvider::boot()`,
 * so a genuine misconfigured production deploy fails fast at startup instead
 * of silently serving debug output or logging verbosely.
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

    /**
     * Tahap 6.7.1 — S67-10: refuses to let the application boot with
     * `APP_ENV=production` and `LOG_LEVEL=debug` simultaneously. A deploy that
     * only flips `APP_ENV` (leaving `.env.example`'s `LOG_LEVEL=debug` default
     * untouched) would otherwise log verbosely in production indefinitely —
     * this mirrors `assertDebugSafety()`'s guard, not a new mechanism.
     *
     * Only the literal `debug` level is rejected; any other level (info,
     * notice, warning, error, critical, alert, emergency) is a legitimate
     * production choice and is never second-guessed here.
     *
     * @throws RuntimeException when $env is "production" and $logLevel is "debug"
     */
    public static function assertLogLevelSafety(string $env, string $logLevel): void
    {
        if ($env === 'production' && strtolower($logLevel) === 'debug') {
            throw new RuntimeException(
                'Refusing to boot: APP_ENV=production with LOG_LEVEL=debug would log verbose '.
                'debug-level output in production. Set LOG_LEVEL to a non-debug level (e.g. "warning" '.
                'or "error") before deploying.'
            );
        }
    }
}
