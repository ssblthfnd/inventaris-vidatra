<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stage 6.9 R2 — user-management authorization (`users.manage`) and
 * role/location structural validation. {@see UserManagementTest} (untouched
 * by R2) already covers the legacy admin/operator/viewer CRUD contract in
 * full — this file adds only what's new: the `users.manage` ability switch,
 * the (role, location_code) combination rules, and the IDOR/escalation
 * checks specific to `unit_admin`/`super_admin` existing at all now.
 *
 * Deliberately does NOT test any inventory endpoint — R2 is a
 * user-management/data-integrity phase only; a `unit_admin` created here is
 * proven structurally valid, never proven to have (or not have) inventory
 * access, which is out of scope until a later Stage 6.9 phase.
 */
class Stage69UserManagementScopeTest extends TestCase
{
    use RefreshDatabase;

    private function seedUnitLocations(): void
    {
        Location::factory()->create(['code' => '02']);
        Location::factory()->create(['code' => '03']);
        Location::factory()->create(['code' => '04']);
    }

    private function user(UserRole $role, ?string $locationCode = null, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'location_code' => $locationCode, 'is_active' => $active]);
    }

    private function actingAsSuperAdmin(): User
    {
        $superAdmin = $this->user(UserRole::SuperAdmin);
        Sanctum::actingAs($superAdmin);

        return $superAdmin;
    }

    private function validCreatePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'newuser@vidatra.test',
            'role' => 'operator',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ], $overrides);
    }

    /* ================================================================== AUTHORIZATION (users.manage) */

    public function test_super_admin_can_access_user_management(): void
    {
        $this->actingAsSuperAdmin();

        $this->getJson('/api/users')->assertOk();
    }

    public function test_legacy_admin_retains_access(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));

        $this->getJson('/api/users')->assertOk();
    }

    public function test_unit_admin_receives_403_on_every_user_management_endpoint(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $target = $this->user(UserRole::Viewer);

        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', $this->validCreatePayload())->assertStatus(403);
        $this->getJson("/api/users/{$target->id}")->assertStatus(403);
        $this->putJson("/api/users/{$target->id}", [])->assertStatus(403);
        $this->postJson("/api/users/{$target->id}/reset-password", [])->assertStatus(403);
    }

    public function test_operator_receives_403(): void
    {
        Sanctum::actingAs($this->user(UserRole::Operator));

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_viewer_receives_403(): void
    {
        Sanctum::actingAs($this->user(UserRole::Viewer));

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_inactive_super_admin_receives_403(): void
    {
        Sanctum::actingAs($this->user(UserRole::SuperAdmin, active: false));

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_inactive_admin_receives_403(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin, active: false));

        $this->getJson('/api/users')->assertStatus(403);
    }

    /* ================================================================== STORE VALIDATION */

    public function test_store_super_admin_with_null_location_is_valid(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'super_admin', 'email' => 'sa@vidatra.test']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'sa@vidatra.test', 'role' => 'super_admin', 'location_code' => null]);
    }

    public function test_store_super_admin_with_location_is_invalid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'super_admin', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_store_unit_admin_with_02_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'unit_admin', 'location_code' => '02', 'email' => 'ua02@vidatra.test']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'ua02@vidatra.test', 'role' => 'unit_admin', 'location_code' => '02']);
    }

    public function test_store_unit_admin_with_03_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'unit_admin', 'location_code' => '03', 'email' => 'ua03@vidatra.test']))
            ->assertCreated();
    }

    public function test_store_unit_admin_with_04_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'unit_admin', 'location_code' => '04', 'email' => 'ua04@vidatra.test']))
            ->assertCreated();
    }

    public function test_store_unit_admin_without_location_is_invalid(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'unit_admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_store_unit_admin_with_location_01_is_invalid(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'unit_admin', 'location_code' => '01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_store_unit_admin_with_nonexistent_code_is_invalid(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'unit_admin', 'location_code' => '99']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_store_unit_admin_with_inactive_location_is_invalid(): void
    {
        Location::factory()->inactive()->create(['code' => '02']);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'unit_admin', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_store_operator_with_null_location_is_valid(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'operator']))
            ->assertCreated();
    }

    public function test_store_operator_with_location_is_invalid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'operator', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_store_viewer_with_null_location_is_valid(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'viewer']))
            ->assertCreated();
    }

    public function test_store_viewer_with_location_is_invalid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'viewer', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_store_admin_with_null_location_is_valid(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'admin']))
            ->assertCreated();
    }

    public function test_store_admin_with_location_is_invalid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'admin', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    /* ================================================================== UPDATE VALIDATION */

    private function updatePayload(User $target, array $overrides = []): array
    {
        return array_merge([
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role->value,
            'is_active' => $target->is_active,
        ], $overrides);
    }

    public function test_update_viewer_to_unit_admin_02_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::Viewer);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'unit_admin', 'location_code' => '02']))
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'unit_admin', 'location_code' => '02']);
    }

    public function test_update_viewer_to_unit_admin_without_location_is_invalid(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::Viewer);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'unit_admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_update_unit_admin_02_to_unit_admin_03_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::UnitAdmin, '02');

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'unit_admin', 'location_code' => '03']))
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'location_code' => '03']);
    }

    public function test_update_unit_admin_02_to_super_admin_with_null_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::UnitAdmin, '02');

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'super_admin', 'location_code' => null]))
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'super_admin', 'location_code' => null]);
    }

    public function test_update_unit_admin_02_to_super_admin_with_02_is_invalid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::UnitAdmin, '02');

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'super_admin', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'unit_admin', 'location_code' => '02']);
    }

    public function test_update_super_admin_to_unit_admin_02_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::SuperAdmin);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'unit_admin', 'location_code' => '02']))
            ->assertOk();
    }

    public function test_update_super_admin_to_unit_admin_null_is_invalid(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::SuperAdmin);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'unit_admin', 'location_code' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_update_operator_to_unit_admin_04_is_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::Operator);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'unit_admin', 'location_code' => '04']))
            ->assertOk();
    }

    /** admin -> unit_admin+02 is structurally valid regardless of who performs it; authorization is a separate concern (proven by the IDOR block below). */
    public function test_update_target_admin_to_unit_admin_02_is_structurally_valid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::Admin);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'unit_admin', 'location_code' => '02']))
            ->assertOk();
    }

    public function test_partial_patch_omitted_location_merges_with_existing_value(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::UnitAdmin, '02');

        // `location_code` deliberately absent — role unchanged, so the merged
        // final state (unit_admin, 02) is still valid.
        $this->patchJson("/api/users/{$target->id}", [
            'name' => 'Renamed User',
            'email' => $target->email,
            'role' => 'unit_admin',
            'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Renamed User', 'location_code' => '02']);
    }

    public function test_partial_patch_omitted_location_is_rejected_when_merged_state_is_invalid(): void
    {
        $this->seedUnitLocations();
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::UnitAdmin, '02');

        // `location_code` omitted; merges to the target's existing '02', but
        // the new role (super_admin) requires NULL — final state is invalid.
        $this->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'super_admin',
            'is_active' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('location_code');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'unit_admin', 'location_code' => '02']);
    }

    /* ================================================================== SELF-PROTECTION */

    public function test_super_admin_cannot_remove_own_users_manage_ability(): void
    {
        $this->seedUnitLocations();
        $superAdmin = $this->actingAsSuperAdmin();

        $this->putJson("/api/users/{$superAdmin->id}", $this->updatePayload($superAdmin, ['role' => 'unit_admin', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::SuperAdmin, $superAdmin->fresh()->role);
    }

    /**
     * Stage 6.9 R2 final adjustment — legacy `admin` is transitional, not a
     * durable identity: unlike `super_admin` (which may self-edit to
     * anything that retains `users.manage`), `admin` may not change its own
     * role AT ALL, `admin -> super_admin` included.
     */
    public function test_legacy_admin_self_update_to_super_admin_is_rejected(): void
    {
        $admin = $this->user(UserRole::Admin);
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", $this->updatePayload($admin, ['role' => 'super_admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_legacy_admin_self_update_to_unit_admin_is_rejected(): void
    {
        $this->seedUnitLocations();
        $admin = $this->user(UserRole::Admin);
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", $this->updatePayload($admin, ['role' => 'unit_admin', 'location_code' => '02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertNull($admin->fresh()->location_code);
    }

    /** Keeping role=admin on a self-edit remains fully allowed — only an actual role CHANGE is blocked. */
    public function test_legacy_admin_self_update_keeping_role_admin_is_still_allowed(): void
    {
        $admin = $this->user(UserRole::Admin);
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", $this->updatePayload($admin, ['name' => 'Renamed Admin', 'role' => 'admin']))
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => 'Renamed Admin', 'role' => 'admin']);
    }

    /** The restriction is scoped to SELF-edits only — admin managing another user is untouched, including assigning super_admin to them. */
    public function test_legacy_admin_can_still_assign_super_admin_to_another_user(): void
    {
        $admin = $this->user(UserRole::Admin);
        Sanctum::actingAs($admin);
        $target = $this->user(UserRole::Viewer);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'super_admin']))
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'super_admin', 'location_code' => null]);
    }

    /** super_admin managing another user's role is unaffected by the admin-specific restriction above. */
    public function test_super_admin_can_update_another_users_role_normally(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::Viewer);

        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target, ['role' => 'operator']))
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'operator']);
    }

    public function test_existing_self_deactivation_protection_remains_intact_for_super_admin(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->putJson("/api/users/{$superAdmin->id}", $this->updatePayload($superAdmin, ['is_active' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    /* ================================================================== SECURITY / IDOR */

    public function test_unit_admin_cannot_reach_user_management_via_direct_url(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $other = $this->user(UserRole::Viewer);

        $this->getJson("/api/users/{$other->id}")->assertStatus(403);
    }

    public function test_unit_admin_cannot_modify_another_users_role_or_location(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $other = $this->user(UserRole::Viewer);

        $this->putJson("/api/users/{$other->id}", $this->updatePayload($other, ['role' => 'super_admin', 'location_code' => null]))
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $other->id, 'role' => 'viewer', 'location_code' => null]);
    }

    public function test_unit_admin_cannot_reset_another_users_password(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $other = $this->user(UserRole::Viewer);

        $this->postJson("/api/users/{$other->id}/reset-password", [
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ])->assertStatus(403);
    }

    public function test_unit_admin_cannot_create_a_user_by_any_role_value(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'viewer']))->assertStatus(403);
    }

    /** Authorization must not depend on anything the client sends — an unauthenticated/wrong-role caller is rejected before any body is even inspected. */
    public function test_authorization_does_not_depend_on_request_body_content(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->postJson('/api/users', ['role' => 'super_admin'])->assertStatus(403);
    }

    /* ================================================================== REGRESSION */

    public function test_existing_admin_operator_viewer_store_behaviour_is_unchanged(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users', $this->validCreatePayload(['role' => 'operator', 'email' => 'regression@vidatra.test']));

        $response->assertCreated()->assertJsonPath('data.role', 'operator');
        $this->assertDatabaseHas('users', ['email' => 'regression@vidatra.test', 'role' => 'operator', 'location_code' => null]);
    }

    public function test_existing_password_reset_behaviour_is_unchanged(): void
    {
        $this->actingAsSuperAdmin();
        $target = $this->user(UserRole::Viewer);

        $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ])->assertOk()->assertJsonMissing(['password' => 'brand-new-secret-1']);
    }
}
