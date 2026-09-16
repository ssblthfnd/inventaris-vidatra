<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Stage 6.9 R1 — authorization foundation. Covers exactly the schema check
 * and the legacy-Gate regression/critical-security-rule assertions called
 * for by the R1 spec (§12.A and §12.D); the full Stage 6.9 security matrix
 * is deliberately out of scope for this phase.
 *
 * {@see RoleFoundationTest} (untouched by R1) already
 * covers admin/operator/viewer Gate behavior end-to-end — this file adds
 * only what's new: schema shape, and that the two new roles change nothing
 * about what the legacy Gates grant.
 */
class Stage69AuthorizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_nullable_location_code_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'location_code'));

        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'location_code');

        $this->assertNotNull($column);
        $this->assertTrue($column['nullable']);
    }

    public function test_location_code_foreign_key_points_to_locations_code_with_restrict(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('users'))
            ->firstWhere('name', 'fk_users_location');

        $this->assertNotNull($foreignKeys, 'Expected fk_users_location to exist on users.location_code.');
        $this->assertSame(['location_code'], $foreignKeys['columns']);
        $this->assertSame('locations', $foreignKeys['foreign_table']);
        $this->assertSame(['code'], $foreignKeys['foreign_columns']);
        $this->assertSame('restrict', strtolower($foreignKeys['on_update']));
        $this->assertSame('restrict', strtolower($foreignKeys['on_delete']));
    }

    public function test_new_roles_exist_and_location_code_defaults_to_null(): void
    {
        $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->assertNull($superAdmin->location_code);

        Location::factory()->create(['code' => '02']);
        $unitAdmin = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']);
        $this->assertSame('02', $unitAdmin->location_code);
    }

    /**
     * Critical security rule (§17): a super_admin/unit_admin account must
     * not be granted anything through the EXISTING admin/operator/viewer
     * Gates in this phase — only actual location-scope enforcement (a later
     * phase) may give unit_admin real inventory access.
     */
    public function test_new_roles_pass_none_of_the_legacy_write_or_admin_gates(): void
    {
        $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewer'));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('operator'));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('admin'));

        Location::factory()->create(['code' => '02']);
        $unitAdmin = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']);
        $this->assertTrue(Gate::forUser($unitAdmin)->allows('viewer'));
        $this->assertFalse(Gate::forUser($unitAdmin)->allows('operator'));
        $this->assertFalse(Gate::forUser($unitAdmin)->allows('admin'));
    }

    /** Regression: existing three roles are completely unaffected by the R1 additions. */
    public function test_legacy_roles_gate_behaviour_is_unchanged(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->assertTrue(Gate::forUser($admin)->allows('viewer'));
        $this->assertTrue(Gate::forUser($admin)->allows('operator'));
        $this->assertTrue(Gate::forUser($admin)->allows('admin'));

        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $this->assertTrue(Gate::forUser($operator)->allows('viewer'));
        $this->assertTrue(Gate::forUser($operator)->allows('operator'));
        $this->assertFalse(Gate::forUser($operator)->allows('admin'));

        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->assertTrue(Gate::forUser($viewer)->allows('viewer'));
        $this->assertFalse(Gate::forUser($viewer)->allows('operator'));
        $this->assertFalse(Gate::forUser($viewer)->allows('admin'));
    }

    public function test_authentication_still_works_after_schema_change(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'secret-password']);

        $response->assertOk();
    }
}
