<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tahap 5.0 — authorization foundation: the role Gates defined in
 * App\Providers\AuthServiceProvider must distinguish admin / operator / viewer,
 * and deny every inactive user.
 *
 * There are no protected inventory endpoints yet, so the Gates are asserted directly.
 */
class RoleFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    public function test_admin_passes_every_gate(): void
    {
        $admin = $this->user(UserRole::Admin);

        $this->assertTrue(Gate::forUser($admin)->allows('viewer'));
        $this->assertTrue(Gate::forUser($admin)->allows('operator'));
        $this->assertTrue(Gate::forUser($admin)->allows('admin'));
    }

    public function test_operator_can_read_and_write_inventory_but_not_admin(): void
    {
        $operator = $this->user(UserRole::Operator);

        $this->assertTrue(Gate::forUser($operator)->allows('viewer'));
        $this->assertTrue(Gate::forUser($operator)->allows('operator'));
        $this->assertFalse(Gate::forUser($operator)->allows('admin'));
    }

    public function test_viewer_can_read_only(): void
    {
        $viewer = $this->user(UserRole::Viewer);

        $this->assertTrue(Gate::forUser($viewer)->allows('viewer'));
        $this->assertFalse(Gate::forUser($viewer)->allows('operator'));
        $this->assertFalse(Gate::forUser($viewer)->allows('admin'));
    }

    public function test_inactive_user_fails_every_gate_regardless_of_role(): void
    {
        foreach ([UserRole::Admin, UserRole::Operator, UserRole::Viewer] as $role) {
            $user = $this->user($role, active: false);

            $this->assertFalse(Gate::forUser($user)->allows('viewer'), "inactive {$role->value} passed 'viewer'");
            $this->assertFalse(Gate::forUser($user)->allows('operator'), "inactive {$role->value} passed 'operator'");
            $this->assertFalse(Gate::forUser($user)->allows('admin'), "inactive {$role->value} passed 'admin'");
        }
    }

    public function test_user_model_role_helpers(): void
    {
        $this->assertTrue($this->user(UserRole::Admin)->isAdmin());
        $this->assertFalse($this->user(UserRole::Operator)->isAdmin());

        $this->assertTrue($this->user(UserRole::Operator)->canWriteInventory());
        $this->assertFalse($this->user(UserRole::Viewer)->canWriteInventory());
        $this->assertFalse($this->user(UserRole::Operator, active: false)->canWriteInventory());

        $this->assertTrue($this->user(UserRole::Viewer)->hasRole(UserRole::Viewer, UserRole::Operator));
        $this->assertFalse($this->user(UserRole::Admin)->hasRole(UserRole::Viewer, UserRole::Operator));
    }
}
