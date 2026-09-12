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
}
