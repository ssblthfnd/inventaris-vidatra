<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Landing page at `/` — server side.
 *
 * The page itself is rendered by the React SPA (resources/js/pages/Landing.jsx,
 * guarded by GuestRoute), so on the server `/` is still just the SPA shell. These
 * tests pin that adding the page changed nothing else: the shell, the strict CSP,
 * the public QR redirect and the API's JSON 404 all behave exactly as before.
 */
class LandingPageRouteTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private const EXPECTED_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; "
        ."base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_root_serves_the_spa_shell_without_authentication(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false)
            ->assertSee('<title>Inventaris Vidatra</title>', false)
            ->assertSee('<meta name="description" content="Sistem Inventaris Yayasan Vidatra', false);
    }

    public function test_root_keeps_the_strict_content_security_policy(): void
    {
        $this->get('/')->assertHeader('Content-Security-Policy', self::EXPECTED_CSP);
    }

    public function test_login_and_app_paths_still_serve_the_spa_shell(): void
    {
        foreach (['/login', '/dashboard', '/inventory'] as $path) {
            $this->get($path)->assertOk()->assertSee('<div id="app"></div>', false);
        }
    }

    public function test_qr_redirect_is_unaffected(): void
    {
        $asset = $this->existingAsset('001');

        $this->get("/a/{$asset->id}")->assertRedirect("/inventory/{$asset->id}");
    }

    public function test_unknown_api_path_is_still_a_json_404_not_the_landing_shell(): void
    {
        $this->getJson('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
