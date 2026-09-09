<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 5.2 — the /api/* JSON error contract. An API request NEVER falls through to
 * the SPA HTML shell, and every error is `{ "message": ... }` (Laravel-standard),
 * with validation adding `errors`.
 */
class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_api_route_is_a_json_404_not_the_spa(): void
    {
        $response = $this->get('/api/does-not-exist', ['Accept' => 'application/json']);

        $response->assertStatus(404);
        $response->assertHeader('content-type', 'application/json');
        $this->assertArrayHasKey('message', $response->json());
        $this->assertStringNotContainsStringIgnoringCase('<!doctype html', $response->getContent());
    }

    public function test_unknown_api_route_without_accept_header_still_returns_json_404(): void
    {
        // a bare browser hit — must not render the SPA / a 500
        $response = $this->get('/api/nope/nope');

        $response->assertStatus(404);
        $this->assertJson($response->getContent());
    }

    public function test_wrong_http_method_on_an_api_route_is_a_json_405(): void
    {
        $response = $this->getJson('/api/login'); // login is POST-only

        $response->assertStatus(405);
        $response->assertHeader('content-type', 'application/json');
        $this->assertArrayHasKey('message', $response->json());
    }

    public function test_unauthenticated_api_request_is_a_json_401(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_unauthenticated_api_request_without_accept_header_still_returns_json_401(): void
    {
        // regression: framework default redirectGuestsTo(route('login')) would 500 here
        $response = $this->get('/api/me');

        $response->assertStatus(401);
        $this->assertJson($response->getContent());
    }

    public function test_deactivated_user_gets_a_json_403(): void
    {
        Sanctum::actingAs(User::factory()->inactive()->create());

        $this->getJson('/api/me')
            ->assertStatus(403)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Akun Anda telah dinonaktifkan.');
    }

    public function test_validation_error_uses_the_standard_laravel_shape(): void
    {
        $this->postJson('/api/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email', 'password']]);
    }

    public function test_error_bodies_never_use_a_custom_success_envelope(): void
    {
        $body = $this->getJson('/api/me')->json();

        $this->assertSame(['message'], array_keys($body));
        $this->assertArrayNotHasKey('success', $body);
        $this->assertArrayNotHasKey('status', $body);
        $this->assertArrayNotHasKey('data', $body);
    }

    public function test_non_api_route_is_handled_by_the_spa_route_not_the_api_stack(): void
    {
        // The SPA shell needs a built Vite manifest; when it is absent this route 500s
        // rather than 200s. What matters: it is NOT a JSON 404 from the API stack —
        // the {any} web route matched and tried to render the SPA view.
        $response = $this->get('/dashboard');

        $this->assertNotSame(404, $response->status());
        $this->assertStringNotContainsString('application/json', (string) $response->headers->get('content-type'));
    }
}
