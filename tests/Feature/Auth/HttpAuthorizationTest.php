<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 5.2 — proves the FULL HTTP chain honours authorization:
 *
 *   api  ->  auth:sanctum  ->  auth.active  ->  can:<gate>
 *
 * RoleFoundationTest (5.0) already checks the Gates directly; this checks that the
 * middleware + route stack actually enforces them, with the correct 401 vs 403 split.
 *
 * The probe routes below exist ONLY for this test run — they are registered in
 * setUp() and vanish with the test's application instance. Nothing is added to
 * routes/api.php, so they never appear in development / production or the SPA.
 */
class HttpAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['viewer', 'operator', 'admin'] as $gate) {
            Route::middleware(['api', 'auth:sanctum', 'auth.active', "can:{$gate}"])
                ->get("/api/_probe/{$gate}", fn () => response()->json(['reached' => $gate]));
        }
    }

    private function user(UserRole $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    // ---------------------------------------------------------------- viewer probe

    public function test_viewer_probe_allows_every_active_role(): void
    {
        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/_probe/viewer')->assertOk()->assertJsonPath('reached', 'viewer');
        }
    }

    public function test_viewer_probe_denies_inactive_users_with_403(): void
    {
        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            Sanctum::actingAs($this->user($role, active: false));
            $this->getJson('/api/_probe/viewer')->assertStatus(403);
        }
    }

    public function test_viewer_probe_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/_probe/viewer')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    // ---------------------------------------------------------------- operator probe

    public function test_operator_probe_denies_viewer_with_403(): void
    {
        Sanctum::actingAs($this->user(UserRole::Viewer));
        $this->getJson('/api/_probe/operator')->assertStatus(403);
    }

    public function test_operator_probe_allows_operator_and_admin(): void
    {
        foreach ([UserRole::Operator, UserRole::Admin] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/_probe/operator')->assertOk()->assertJsonPath('reached', 'operator');
        }
    }

    public function test_operator_probe_denies_inactive_operator_with_403(): void
    {
        Sanctum::actingAs($this->user(UserRole::Operator, active: false));
        $this->getJson('/api/_probe/operator')->assertStatus(403);
    }

    public function test_operator_probe_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/_probe/operator')->assertStatus(401);
    }

    // ---------------------------------------------------------------- admin probe

    public function test_admin_probe_denies_viewer_and_operator_with_403(): void
    {
        foreach ([UserRole::Viewer, UserRole::Operator] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/_probe/admin')->assertStatus(403);
        }
    }

    public function test_admin_probe_allows_admin(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin));
        $this->getJson('/api/_probe/admin')->assertOk()->assertJsonPath('reached', 'admin');
    }

    public function test_admin_probe_denies_inactive_admin_with_403(): void
    {
        Sanctum::actingAs($this->user(UserRole::Admin, active: false));
        $this->getJson('/api/_probe/admin')->assertStatus(403);
    }

    public function test_admin_probe_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/_probe/admin')->assertStatus(401);
    }

    // ---------------------------------------------------------------- semantics

    public function test_role_denial_is_403_never_401_or_422(): void
    {
        Sanctum::actingAs($this->user(UserRole::Viewer));

        $response = $this->getJson('/api/_probe/admin');

        $response->assertStatus(403);
        $this->assertNotSame(401, $response->status());
        $this->assertNotSame(422, $response->status());
        $this->assertArrayNotHasKey('errors', $response->json());
        $this->assertArrayHasKey('message', $response->json());
    }
}
