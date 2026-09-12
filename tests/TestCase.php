<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety guard (Tahap 5.0): the test suite runs `migrate:fresh` on the default
     * connection via RefreshDatabase, which DROPS EVERY TABLE. Refuse to boot unless
     * that connection points at the dedicated test database — never the development
     * database `inventaris_vidatra`.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $default = config('database.default');
        $database = config("database.connections.{$default}.database");

        if ($database !== 'inventaris_vidatra_test') {
            throw new RuntimeException(
                "REFUSING TO RUN TESTS: default DB connection [{$default}] points at "
                ."[{$database}], expected [inventaris_vidatra_test]. Check phpunit.xml / .env.testing."
            );
        }
    }

    /**
     * Tahap 6.6 — the test suite's `CACHE_STORE=array` (.env.testing) lives for
     * the whole PHPUnit process, NOT per test — unlike RefreshDatabase, nothing
     * resets it between test methods. The new `login` RateLimiter (H-1) counts
     * hits in that same cache, so without this, two unrelated test METHODS (in
     * the same or different files) that both call `POST /api/login` with the
     * same email+IP would silently accumulate hits across tests and could start
     * failing with a spurious 429 purely because of execution order. Flushing
     * here makes every test start from a clean rate-limiter slate, same as it
     * already effectively starts from a clean database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }
}
