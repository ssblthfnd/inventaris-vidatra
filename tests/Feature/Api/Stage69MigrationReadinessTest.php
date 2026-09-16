<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Support\LocationScopeTest;

/**
 * Stage 6.9 R3 — existing user migration READINESS, not an actual migration.
 *
 * Every scenario here proves the EXISTING (R2) user-management API can
 * explicitly assign a super_admin/unit_admin role to a user, on demand, by an
 * authorized actor — without any new code, migration, or automatic role
 * inference. No test in this file touches the three real legacy users
 * (admin@vidatra.test / operator@vidatra.test / test@example.com); it only
 * proves the mechanism works on freshly-created, disposable test accounts.
 *
 * Most of the individual (role, location) validation combinations are
 * already exhaustively covered by {@see Stage69UserManagementScopeTest} (R2)
 * — this file does NOT re-test that matrix. It covers exactly the one gap
 * R3's audit found: proving "multiple unit_admin per location" holds at the
 * real persistence layer (via the HTTP API + actual DB rows), not just at
 * the {@see LocationScope} unit-test level (already covered by
 * {@see LocationScopeTest::test_multiple_unit_admins_on_the_same_location_are_independently_valid},
 * which only proves the scope OBJECT behaves independently per instance —
 * not that the database/API layer permits creating two such rows at all).
 */
class Stage69MigrationReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function seedUnitLocations(): void
    {
        Location::factory()->create(['code' => '02']);
        Location::factory()->create(['code' => '03']);
        Location::factory()->create(['code' => '04']);
    }

    private function actingAsSuperAdmin(): User
    {
        $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        Sanctum::actingAs($superAdmin);

        return $superAdmin;
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Migration Candidate',
            'email' => 'candidate@vidatra.test',
            'role' => 'unit_admin',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ], $overrides);
    }

    #[DataProvider('unitLocationProvider')]
    public function test_multiple_unit_admins_can_be_explicitly_assigned_to_the_same_location(string $locationCode): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $first = $this->postJson('/api/users', $this->createPayload([
            'email' => "unit-admin-a-{$locationCode}@vidatra.test",
            'location_code' => $locationCode,
        ]));
        $second = $this->postJson('/api/users', $this->createPayload([
            'email' => "unit-admin-b-{$locationCode}@vidatra.test",
            'location_code' => $locationCode,
        ]));

        $first->assertCreated();
        $second->assertCreated();

        $this->assertSame(2, User::query()
            ->where('role', UserRole::UnitAdmin)
            ->where('location_code', $locationCode)
            ->count());
    }

    /** @return list<array{0:string}> */
    public static function unitLocationProvider(): array
    {
        return [['02'], ['03'], ['04']];
    }

    /**
     * Explicit assignment traceability (R3 §2/§9): a super_admin can, TODAY,
     * without any code change, assign every one of the four target
     * combinations to an arbitrary OTHER user via the existing API. Kept as
     * one compact test (not four) since each individual combination is
     * already proven in depth by Stage69UserManagementScopeTest — this only
     * re-confirms the end-to-end capability exists as a readiness artifact.
     */
    public function test_super_admin_can_explicitly_assign_every_target_combination_to_another_user(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $combinations = [
            ['role' => 'unit_admin', 'location_code' => '02'],
            ['role' => 'unit_admin', 'location_code' => '03'],
            ['role' => 'unit_admin', 'location_code' => '04'],
            ['role' => 'super_admin', 'location_code' => null],
        ];

        foreach ($combinations as $i => $combo) {
            $email = "readiness-{$i}@vidatra.test";
            $this->postJson('/api/users', $this->createPayload(array_merge($combo, ['email' => $email])))
                ->assertCreated();

            $this->assertDatabaseHas('users', array_merge(['email' => $email], $combo));
        }
    }

    /**
     * R3 §10/critical constraint: nothing in this readiness-proving suite
     * (which creates and deletes-via-rollback several disposable users on
     * the ISOLATED test database) can reach the three real legacy accounts —
     * they simply don't exist on the test connection at all. This test
     * documents that isolation explicitly rather than leaving it implicit.
     */
    public function test_readiness_suite_never_touches_the_real_legacy_accounts(): void
    {
        $this->assertDatabaseMissing('users', ['email' => 'admin@vidatra.test']);
        $this->assertDatabaseMissing('users', ['email' => 'operator@vidatra.test']);
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
