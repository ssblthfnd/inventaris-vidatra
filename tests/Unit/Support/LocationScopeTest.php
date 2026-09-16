<?php

namespace Tests\Unit\Support;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 6.9 R1 — App\Support\LocationScope is not called from anywhere in
 * the application yet; these tests exercise it directly as the foundation
 * for later phases (inventory/room/import/export/report/dashboard/revert
 * scope enforcement).
 */
class LocationScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, ?string $locationCode = null): User
    {
        return User::factory()->create(['role' => $role, 'location_code' => $locationCode]);
    }

    public function test_super_admin_is_global(): void
    {
        $scope = LocationScope::for($this->user(UserRole::SuperAdmin));

        $this->assertTrue($scope->isGlobal());
        $this->assertTrue($scope->allows('02'));
        $this->assertTrue($scope->allows('99')); // even a nonexistent code — global means global, LocationScope doesn't validate location existence.
        $this->assertSame(['02', '03'], $scope->filterCodes(['02', '03']));
    }

    public function test_legacy_admin_is_global(): void
    {
        $this->assertTrue(LocationScope::for($this->user(UserRole::Admin))->isGlobal());
    }

    public function test_operator_is_global(): void
    {
        $this->assertTrue(LocationScope::for($this->user(UserRole::Operator))->isGlobal());
    }

    public function test_viewer_is_global(): void
    {
        $this->assertTrue(LocationScope::for($this->user(UserRole::Viewer))->isGlobal());
    }

    public function test_global_scope_assert_allowed_never_throws(): void
    {
        $scope = LocationScope::for($this->user(UserRole::SuperAdmin));

        $scope->assertAllowed('02');
        $scope->assertAllowed(['02', '03', '99']);

        $this->addToCounterExceptionNotThrown();
    }

    public function test_unit_admin_with_02_allows_02(): void
    {
        Location::factory()->create(['code' => '02']);
        $scope = LocationScope::for($this->user(UserRole::UnitAdmin, '02'));

        $this->assertFalse($scope->isGlobal());
        $this->assertTrue($scope->allows('02'));
    }

    public function test_unit_admin_with_02_rejects_03(): void
    {
        Location::factory()->create(['code' => '02']);
        $scope = LocationScope::for($this->user(UserRole::UnitAdmin, '02'));

        $this->assertFalse($scope->allows('03'));
    }

    public function test_unit_admin_with_02_rejects_04(): void
    {
        Location::factory()->create(['code' => '02']);
        $scope = LocationScope::for($this->user(UserRole::UnitAdmin, '02'));

        $this->assertFalse($scope->allows('04'));
    }

    public function test_unit_admin_with_02_rejects_01(): void
    {
        Location::factory()->create(['code' => '02']);
        $scope = LocationScope::for($this->user(UserRole::UnitAdmin, '02'));

        $this->assertFalse($scope->allows('01'));
    }

    public function test_unit_admin_filter_codes_keeps_only_own_location(): void
    {
        Location::factory()->create(['code' => '02']);
        $scope = LocationScope::for($this->user(UserRole::UnitAdmin, '02'));

        $this->assertSame(['02'], $scope->filterCodes(['01', '02', '03', '04']));
    }

    public function test_unit_admin_assert_allowed_throws_for_out_of_scope_location(): void
    {
        Location::factory()->create(['code' => '02']);
        Location::factory()->create(['code' => '03']);
        $scope = LocationScope::for($this->user(UserRole::UnitAdmin, '02'));

        $this->expectException(AuthorizationException::class);
        $scope->assertAllowed('03');
    }

    public function test_unit_admin_with_null_location_never_becomes_global(): void
    {
        // Deliberately unsaved: a real INSERT would violate the FK constraint
        // for a NULL-but-required-for-unit_admin business rule that the
        // schema itself doesn't enforce (nullable column) — this represents
        // the corrupt in-memory state LocationScope must still refuse.
        $user = User::factory()->make(['role' => UserRole::UnitAdmin, 'location_code' => null]);
        $user->id = 9999;

        $this->expectException(AuthorizationException::class);
        LocationScope::for($user);
    }

    public function test_unit_admin_with_location_01_never_becomes_global(): void
    {
        Location::factory()->create(['code' => '01']);
        $user = $this->user(UserRole::UnitAdmin, '01');

        $this->expectException(AuthorizationException::class);
        LocationScope::for($user);
    }

    public function test_unit_admin_with_unknown_location_code_never_becomes_global(): void
    {
        // Not persisted — a real row can't reference a nonexistent location
        // (FK), so this represents defense-in-depth against a state the
        // schema already mostly prevents.
        $user = User::factory()->make(['role' => UserRole::UnitAdmin, 'location_code' => 'zz']);
        $user->id = 9998;

        $this->expectException(AuthorizationException::class);
        LocationScope::for($user);
    }

    public function test_multiple_unit_admins_on_the_same_location_are_independently_valid(): void
    {
        Location::factory()->create(['code' => '02']);
        $userA = $this->user(UserRole::UnitAdmin, '02');
        $userB = $this->user(UserRole::UnitAdmin, '02');

        $scopeA = LocationScope::for($userA);
        $scopeB = LocationScope::for($userB);

        $this->assertTrue($scopeA->allows('02'));
        $this->assertTrue($scopeB->allows('02'));
        $this->assertFalse($scopeA->allows('03'));
        $this->assertFalse($scopeB->allows('03'));
    }

    /** No-op assertion helper: reaching this line means the exception-free calls above didn't throw. */
    private function addToCounterExceptionNotThrown(): void
    {
        $this->assertTrue(true);
    }
}
