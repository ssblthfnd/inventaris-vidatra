<?php

namespace Tests\Unit\Support;

use App\Support\DeploymentSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Tahap 6.6 (H-2). Deliberately calls the static helper directly with
 * arbitrary (env, debug) pairs rather than booting a second Application with a
 * different `.env` — this project's real `.env` stays `APP_ENV=local` +
 * `APP_DEBUG=true`, which the assertions below confirm is explicitly fine.
 */
class DeploymentSafetyTest extends TestCase
{
    public function test_production_with_debug_true_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=production with APP_DEBUG=true');

        DeploymentSafety::assertDebugSafety('production', true);
    }

    public function test_production_with_debug_false_is_allowed(): void
    {
        DeploymentSafety::assertDebugSafety('production', false);
        $this->addToAssertionCount(1); // reaching here without an exception IS the assertion
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function nonProductionCombinations(): array
    {
        return [
            'local + debug true (this project\'s own real .env)' => ['local', true],
            'local + debug false' => ['local', false],
            'testing + debug true (this project\'s own .env.testing)' => ['testing', true],
            'testing + debug false' => ['testing', false],
            'staging + debug true' => ['staging', true],
        ];
    }

    #[DataProvider('nonProductionCombinations')]
    public function test_non_production_environments_are_always_allowed_regardless_of_debug(string $env, bool $debug): void
    {
        DeploymentSafety::assertDebugSafety($env, $debug);
        $this->addToAssertionCount(1);
    }

    public function test_the_actual_running_test_environment_configuration_passes(): void
    {
        // Proves the guard is actually wired into boot (AppServiceProvider::boot())
        // without throwing for THIS run's real config — if it had rejected the
        // testing environment, the whole suite would already have failed to boot.
        DeploymentSafety::assertDebugSafety((string) config('app.env'), (bool) config('app.debug'));
        $this->addToAssertionCount(1);
    }

    /**
     * Tahap 6.7.1 — S67-10. Same-shaped guard as assertDebugSafety() above,
     * for LOG_LEVEL instead of APP_DEBUG.
     */
    public function test_production_with_log_level_debug_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=production with LOG_LEVEL=debug');

        DeploymentSafety::assertLogLevelSafety('production', 'debug');
    }

    public function test_production_with_log_level_debug_uppercase_is_rejected(): void
    {
        // Log-level strings are conventionally lowercase, but the guard should
        // not be foolable by a stray "Debug"/"DEBUG" value in a real .env.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=production with LOG_LEVEL=debug');

        DeploymentSafety::assertLogLevelSafety('production', 'DEBUG');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonDebugLogLevels(): array
    {
        return [
            'info' => ['info'],
            'notice' => ['notice'],
            'warning' => ['warning'],
            'error' => ['error'],
            'critical' => ['critical'],
            'alert' => ['alert'],
            'emergency' => ['emergency'],
        ];
    }

    #[DataProvider('nonDebugLogLevels')]
    public function test_production_with_a_non_debug_log_level_is_allowed(string $logLevel): void
    {
        DeploymentSafety::assertLogLevelSafety('production', $logLevel);
        $this->addToAssertionCount(1); // reaching here without an exception IS the assertion
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonProductionLogLevelCombinations(): array
    {
        return [
            'local + debug (this project\'s own real .env)' => ['local', 'debug'],
            'testing + debug (this project\'s own .env.testing)' => ['testing', 'debug'],
            'staging + debug' => ['staging', 'debug'],
        ];
    }

    #[DataProvider('nonProductionLogLevelCombinations')]
    public function test_non_production_environments_allow_debug_log_level(string $env, string $logLevel): void
    {
        DeploymentSafety::assertLogLevelSafety($env, $logLevel);
        $this->addToAssertionCount(1);
    }

    public function test_the_actual_running_test_environment_log_level_configuration_passes(): void
    {
        // Same "proves it's wired into boot" purpose as the APP_DEBUG
        // equivalent above.
        DeploymentSafety::assertLogLevelSafety((string) config('app.env'), (string) config('logging.level'));
        $this->addToAssertionCount(1);
    }
}
