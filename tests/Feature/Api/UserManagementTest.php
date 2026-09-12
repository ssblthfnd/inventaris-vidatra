<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\ImportRow;
use App\Models\MutationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.4 — `GET/POST/PUT|PATCH /api/users`, `POST /api/users/{user}/reset-password`.
 * All `can:admin` — a viewer OR operator gets 403 (unlike every other write
 * endpoint in this app, which is `can:operator` and lets operators through).
 *
 * No `DELETE /api/users/{user}` exists at all (see UserController's docblock);
 * `test_delete_route_does_not_exist` documents that deliberately.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $active = true): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => $active]);
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => UserRole::Viewer, 'is_active' => true]);
    }

    private function validCreatePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'email' => 'budi@vidatra.test',
            'role' => 'operator',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ], $overrides);
    }

    /* ================================================================== authorization */

    public function test_unauthenticated_cannot_list_users(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_unauthenticated_cannot_create_user(): void
    {
        $this->postJson('/api/users', $this->validCreatePayload())->assertStatus(401);
    }

    public function test_viewer_cannot_access_user_management(): void
    {
        Sanctum::actingAs($this->viewer());
        $target = $this->operator();

        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', $this->validCreatePayload())->assertStatus(403);
        $this->getJson("/api/users/{$target->id}")->assertStatus(403);
        $this->putJson("/api/users/{$target->id}", [])->assertStatus(403);
        $this->postJson("/api/users/{$target->id}/reset-password", [])->assertStatus(403);
    }

    public function test_operator_cannot_access_user_management(): void
    {
        Sanctum::actingAs($this->operator());
        $target = $this->viewer();

        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', $this->validCreatePayload())->assertStatus(403);
        $this->getJson("/api/users/{$target->id}")->assertStatus(403);
        $this->putJson("/api/users/{$target->id}", [])->assertStatus(403);
        $this->postJson("/api/users/{$target->id}/reset-password", [])->assertStatus(403);
    }

    public function test_inactive_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->admin(active: false));

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        Sanctum::actingAs($this->admin());
        $this->operator();
        $this->viewer();

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at']], 'meta']);
    }

    /* ================================================================== CRUD */

    public function test_admin_can_create_user(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/users', $this->validCreatePayload())
            ->assertStatus(201);

        $response->assertJsonPath('data.name', 'Budi Santoso');
        $response->assertJsonPath('data.email', 'budi@vidatra.test');
        $response->assertJsonPath('data.role', 'operator');
        $response->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('users', ['email' => 'budi@vidatra.test', 'role' => 'operator']);
    }

    public function test_created_password_is_hashed_and_usable_to_log_in(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->validCreatePayload())->assertStatus(201);

        $user = User::query()->where('email', 'budi@vidatra.test')->firstOrFail();

        $this->assertNotSame('super-secret-1', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('super-secret-1', $user->password));
    }

    public function test_password_and_hash_are_never_exposed_in_any_response(): void
    {
        Sanctum::actingAs($this->admin());

        $create = $this->postJson('/api/users', $this->validCreatePayload())->assertStatus(201);
        $create->assertJsonMissingPath('data.password');
        $create->assertJsonMissingPath('data.password_hash');
        $create->assertJsonMissingPath('data.remember_token');
        $this->assertStringNotContainsString('super-secret-1', $create->getContent());

        $user = User::query()->where('email', 'budi@vidatra.test')->firstOrFail();

        $show = $this->getJson("/api/users/{$user->id}")->assertOk();
        $show->assertJsonMissingPath('data.password');
        $show->assertJsonMissingPath('data.remember_token');

        $list = $this->getJson('/api/users')->assertOk();
        $this->assertStringNotContainsString('password', $list->getContent());
        $this->assertStringNotContainsString('remember_token', $list->getContent());
    }

    public function test_admin_can_view_a_single_user(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->getJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.email', $target->email);
    }

    public function test_missing_user_is_404(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/users/999999')->assertStatus(404);
    }

    public function test_admin_can_update_a_user(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->putJson("/api/users/{$target->id}", [
            'name' => 'Nama Baru',
            'email' => $target->email,
            'role' => 'viewer',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru')
            ->assertJsonPath('data.role', 'viewer');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Nama Baru', 'role' => 'viewer']);
    }

    public function test_update_allows_keeping_the_current_users_own_email(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role->value,
            'is_active' => true,
        ])->assertOk();
    }

    public function test_email_uniqueness_is_enforced_on_create(): void
    {
        Sanctum::actingAs($this->admin());
        $existing = $this->operator();

        $this->postJson('/api/users', $this->validCreatePayload(['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_email_uniqueness_is_enforced_on_update(): void
    {
        Sanctum::actingAs($this->admin());
        $existing = $this->operator();
        $target = $this->viewer();

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $existing->email,
            'role' => 'viewer',
            'is_active' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_invalid_role_is_rejected_on_create(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'superadmin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_invalid_role_is_rejected_on_update(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'superadmin',
            'is_active' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_invalid_data_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'role', 'password']);
    }

    public function test_password_mismatch_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->validCreatePayload(['password_confirmation' => 'different']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_short_password_is_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->validCreatePayload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /**
     * Tahap 6.6 (M-5): the policy was raised from min(8) to min(12) — this
     * specifically locks in the NEW boundary (an 8-11 char password used to be
     * accepted and no longer is), distinct from `test_short_password_is_rejected`
     * above, which already covered a password short under the OLD policy too.
     */
    public function test_password_between_eight_and_eleven_characters_is_now_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->validCreatePayload(['password' => 'eleven-ch1', 'password_confirmation' => 'eleven-ch1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_twelve_character_password_is_accepted(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/users', $this->validCreatePayload(['password' => 'exactly12chr', 'password_confirmation' => 'exactly12chr']))
            ->assertStatus(201);
    }

    public function test_reset_password_endpoint_follows_the_same_minimum_length_policy(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'short-pass',
            'password_confirmation' => 'short-pass',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'long-enough-pass-1',
            'password_confirmation' => 'long-enough-pass-1',
        ])->assertOk();
    }

    public function test_mass_assignment_cannot_set_password_via_update(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();
        $originalHash = $target->password;

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role->value,
            'is_active' => true,
            'password' => 'ignored-password-1',
        ])->assertOk();

        $this->assertSame($originalHash, $target->fresh()->password);
    }

    /* ================================================================== reset password */

    public function test_admin_can_reset_another_users_password(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])
            ->assertOk()
            ->assertJsonMissingPath('password');

        $this->assertTrue(Hash::check('brand-new-pass-1', $target->fresh()->password));
    }

    public function test_reset_password_requires_confirmation(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'mismatch',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /* ================================================================== security: self-protection */

    public function test_non_admin_cannot_modify_roles(): void
    {
        Sanctum::actingAs($this->operator());
        $target = $this->viewer();

        $this->putJson("/api/users/{$target->id}", ['role' => 'admin'])->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'viewer']);
    }

    public function test_non_admin_cannot_create_admin_users(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->postJson('/api/users', $this->validCreatePayload(['role' => 'admin']))->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'budi@vidatra.test']);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'admin',
            'is_active' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_cannot_remove_their_own_admin_role(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'operator',
            'is_active' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_admin_can_still_update_their_own_name_and_email(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", [
            'name' => 'Nama Admin Baru',
            'email' => $admin->email,
            'role' => 'admin',
            'is_active' => true,
        ])->assertOk();

        $this->assertSame('Nama Admin Baru', $admin->fresh()->name);
    }

    public function test_admin_cannot_deactivate_another_admin_is_still_allowed(): void
    {
        // Self-protection only blocks acting on ONE'S OWN account — an admin
        // may still deactivate a *different* admin.
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$otherAdmin->id}", [
            'name' => $otherAdmin->name,
            'email' => $otherAdmin->email,
            'role' => 'admin',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_delete_route_does_not_exist(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $response = $this->deleteJson("/api/users/{$target->id}");

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    /* ================================================================== status / auth integration */

    public function test_admin_can_deactivate_another_user(): void
    {
        Sanctum::actingAs($this->admin());
        $target = $this->operator();

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role->value,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_deactivated_user_cannot_authenticate(): void
    {
        Sanctum::actingAs($this->admin());
        $target = User::factory()->create([
            'email' => 'target@vidatra.test',
            'password' => Hash::make('original-pass-1'),
            'role' => UserRole::Operator,
            'is_active' => true,
        ]);

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'operator',
            'is_active' => false,
        ])->assertOk();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => 'target@vidatra.test', 'password' => 'original-pass-1'])
            ->assertStatus(403);
        $this->assertGuest('web');
    }

    public function test_reactivating_a_user_restores_normal_authentication(): void
    {
        Sanctum::actingAs($this->admin());
        $target = User::factory()->create([
            'email' => 'target2@vidatra.test',
            'password' => Hash::make('original-pass-2'),
            'role' => UserRole::Operator,
            'is_active' => false,
        ]);

        $this->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'operator',
            'is_active' => true,
        ])->assertOk();

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => 'target2@vidatra.test', 'password' => 'original-pass-2'])
            ->assertOk();
        $this->assertAuthenticatedAs($target->fresh(), 'web');
    }

    /* ================================================================== read-only-elsewhere safety */

    public function test_user_management_never_touches_inventory_tables(): void
    {
        Sanctum::actingAs($this->admin());

        $before = [
            'assets' => Asset::withTrashed()->count(),
            'import_rows' => ImportRow::count(),
            'mutation_logs' => MutationLog::count(),
        ];

        $target = $this->operator();
        $this->postJson('/api/users', $this->validCreatePayload())->assertStatus(201);
        $this->putJson("/api/users/{$target->id}", [
            'name' => 'Changed',
            'email' => $target->email,
            'role' => 'viewer',
            'is_active' => false,
        ])->assertOk();

        $after = [
            'assets' => Asset::withTrashed()->count(),
            'import_rows' => ImportRow::count(),
            'mutation_logs' => MutationLog::count(),
        ];

        $this->assertSame($before, $after);
    }
}
