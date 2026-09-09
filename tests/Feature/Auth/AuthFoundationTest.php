<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 5.0 — Sanctum SPA cookie/session authentication foundation.
 *
 * Login-endpoint tests drive the real credential/session flow (an `Origin` header
 * for a stateful domain makes EnsureFrontendRequestsAreStateful start the session,
 * mirroring the SPA). Endpoint-behaviour tests that need an already-authenticated
 * caller use `Sanctum::actingAs()` — the canonical Sanctum test helper — because the
 * test HTTP client keeps no cookie jar between requests.
 */
class AuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'operator@vidatra.test',
            'password' => Hash::make('secret-password'),
            'role' => UserRole::Operator,
            'is_active' => true,
        ], $overrides));
    }

    /** Real SPA login request (stateful origin so the session middleware runs). */
    private function login(string $email, string $password)
    {
        return $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_login_succeeds_for_active_user_and_returns_the_user(): void
    {
        $user = $this->makeUser();

        $this->login('operator@vidatra.test', 'secret-password')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'operator@vidatra.test')
            ->assertJsonPath('data.role', 'operator')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_fails_with_422_for_wrong_password(): void
    {
        $this->makeUser();

        $this->login('operator@vidatra.test', 'WRONG')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_login_fails_with_422_for_missing_fields(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rejected_with_403_for_inactive_user(): void
    {
        $this->makeUser(['email' => 'disabled@vidatra.test', 'is_active' => false]);

        $this->login('disabled@vidatra.test', 'secret-password')
            ->assertStatus(403);

        $this->assertGuest('web');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->makeUser(['email' => 'viewer@vidatra.test', 'role' => UserRole::Viewer]);
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'viewer@vidatra.test')
            ->assertJsonPath('data.role', 'viewer')
            ->assertJsonPath('data.is_active', true)
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => 'viewer@vidatra.test',
                'role' => 'viewer',
                'is_active' => true,
            ]]);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->makeUser();

        $this->login('operator@vidatra.test', 'secret-password')->assertOk();
        $this->assertAuthenticated('web');

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->assertGuest('web');
    }

    public function test_authenticated_user_who_is_deactivated_is_locked_out_with_403(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/me')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/me')->assertStatus(403);
    }
}
