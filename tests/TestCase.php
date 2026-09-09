<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
}
