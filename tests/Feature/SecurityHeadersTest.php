<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.6 (M-1 / M-4) — `App\Http\Middleware\SecurityHeaders`, registered
 * globally in `bootstrap/app.php`. Asserts the headers that are ALWAYS
 * expected regardless of route/environment; HSTS and CSP have their own
 * conditional tests since they deliberately do NOT always apply (HSTS only
 * over HTTPS, CSP only when the Vite dev server isn't running).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_headers_present_on_an_api_response(): void
    {
        $response = $this->getJson('/api/me'); // 401, but headers apply regardless of status

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_baseline_headers_present_on_an_authenticated_api_response(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer, 'is_active' => true]));

        $response = $this->getJson('/api/me')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_baseline_headers_present_on_the_spa_shell(): void
    {
        $response = $this->get('/dashboard'); // SPA catch-all, unauthenticated is still served the shell

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_permissions_policy_disables_unused_browser_features(): void
    {
        $response = $this->getJson('/api/me');

        $value = $response->headers->get('Permissions-Policy');
        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()'] as $directive) {
            $this->assertStringContainsString($directive, $value);
        }
    }

    public function test_hsts_is_absent_over_plain_http(): void
    {
        // The Laravel test client's default request is not marked secure —
        // matches real local HTTP development exactly (see SecurityHeaders'
        // own docblock: HSTS must never fire on plain-HTTP localhost).
        $response = $this->getJson('/api/me');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_present_over_https(): void
    {
        // An explicit https:// URL (rather than a server-variable trick) is
        // what reliably makes Symfony's Request::isSecure() true in tests.
        $response = $this->getJson('https://localhost/api/me');

        $response->assertHeader('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_x_powered_by_is_removed(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_csp_is_present_when_the_vite_dev_server_is_not_running(): void
    {
        // The real project state during `php artisan test` — no `public/hot`.
        $this->assertFileDoesNotExist(public_path('hot'));

        $response = $this->getJson('/api/me');

        $response->assertHeader('Content-Security-Policy');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_csp_is_skipped_when_the_vite_dev_server_is_running(): void
    {
        // Simulates `npm run dev` being active without actually starting it —
        // `public/hot`'s mere presence is the exact signal `@vite()` itself
        // uses; see SecurityHeaders::applyContentSecurityPolicy()'s docblock.
        file_put_contents(public_path('hot'), 'http://[::1]:5173');

        try {
            $response = $this->getJson('/api/me');
            $response->assertHeaderMissing('Content-Security-Policy');
        } finally {
            @unlink(public_path('hot'));
        }
    }
}
