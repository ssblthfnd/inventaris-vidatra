<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Tahap 6.6 (M-3) — `config/session.php`'s `secure` key:
 *   env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')
 *
 * Verifies the FILE's own logic directly (by re-evaluating it with a
 * temporarily overridden environment, then always restoring it) rather than
 * booting a second Application with a different `.env` — this project's real
 * `.env`/`.env.testing` are never touched. `env()` reads `$_ENV`/`$_SERVER`/
 * `getenv()`; all three are set and restored together so the override is
 * picked up reliably regardless of which one Laravel's env repository
 * consults in this PHP version.
 */
class SessionCookieSecurityTest extends TestCase
{
    /**
     * @param  array<string, string|null>  $vars  null unsets the variable
     */
    private function withEnv(array $vars, \Closure $callback): mixed
    {
        $originals = [];
        foreach ($vars as $key => $value) {
            $originals[$key] = getenv($key) === false ? null : getenv($key);

            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($originals as $key => $original) {
                if ($original === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv("{$key}={$original}");
                    $_ENV[$key] = $original;
                    $_SERVER[$key] = $original;
                }
            }
        }
    }

    private function freshSessionConfig(): array
    {
        return require config_path('session.php');
    }

    public function test_production_without_an_explicit_override_defaults_to_secure(): void
    {
        $config = $this->withEnv(
            ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => null],
            fn () => $this->freshSessionConfig(),
        );

        $this->assertTrue($config['secure']);
    }

    public function test_local_without_an_explicit_override_defaults_to_not_secure(): void
    {
        $config = $this->withEnv(
            ['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => null],
            fn () => $this->freshSessionConfig(),
        );

        $this->assertFalse($config['secure']);
    }

    public function test_an_explicit_override_always_wins_over_the_environment_default(): void
    {
        $forcedOff = $this->withEnv(
            ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'],
            fn () => $this->freshSessionConfig(),
        );
        $this->assertFalse(filter_var($forcedOff['secure'], FILTER_VALIDATE_BOOLEAN));

        $forcedOn = $this->withEnv(
            ['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true'],
            fn () => $this->freshSessionConfig(),
        );
        $this->assertTrue(filter_var($forcedOn['secure'], FILTER_VALIDATE_BOOLEAN));
    }

    public function test_the_actual_running_environment_does_not_force_secure_cookies_over_local_http(): void
    {
        // The real, currently-loaded config for THIS test run (APP_ENV=testing)
        // — proves the change didn't break local/testing HTTP development.
        $this->assertNotTrue(config('session.secure'));
    }

    public function test_http_only_and_same_site_are_unaffected_by_this_change(): void
    {
        $config = $this->freshSessionConfig();

        $this->assertTrue($config['http_only']);
        $this->assertSame('lax', $config['same_site']);
    }
}
