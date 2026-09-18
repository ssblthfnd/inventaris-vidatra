<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.0 — `GET /a/{asset}`, the public QR-code redirect layer.
 *
 * No authentication is involved here: this route never returns asset data, only a
 * redirect to the SPA path. Whether the visitor can actually see the asset once
 * there is entirely the existing `GET /api/assets/{asset}` authorization's concern.
 */
class AssetQrRedirectTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    public function test_valid_asset_id_redirects_to_the_spa_detail_path(): void
    {
        $asset = $this->existingAsset('001');

        $this->get("/a/{$asset->id}")
            ->assertRedirect("/inventory/{$asset->id}");
    }

    public function test_no_authentication_is_required(): void
    {
        $asset = $this->existingAsset('001');

        // No Sanctum::actingAs() at all — this must still redirect, not 401/403.
        $this->get("/a/{$asset->id}")->assertRedirect("/inventory/{$asset->id}");
    }

    public function test_nonexistent_asset_id_is_404(): void
    {
        $this->get('/a/999999')->assertStatus(404);
    }

    /**
     * `->whereNumber('asset')` means a non-numeric path segment doesn't match this
     * route at all, so it falls through to the SPA catch-all (same as any other
     * unrecognized path in this app) rather than hitting this controller — not a
     * hard 404 here, but it never reaches AssetQrRedirectController either.
     */
    public function test_non_numeric_asset_id_falls_through_to_the_spa_shell_not_the_redirect(): void
    {
        $this->get('/a/not-a-number')->assertOk();
    }

    /**
     * The physical tag must keep resolving even after the asset is soft-deleted —
     * the redirect layer's only job is "does this id exist at all"; the existing
     * `AssetController::show()` authorization (not this route) decides whether the
     * signed-in visitor may actually see a trashed asset's detail.
     */
    public function test_trashed_asset_id_still_redirects(): void
    {
        $asset = $this->existingAsset('001');
        $asset->delete();

        $this->get("/a/{$asset->id}")->assertRedirect("/inventory/{$asset->id}");
    }

    public function test_redirect_does_not_expose_asset_data(): void
    {
        $asset = $this->existingAsset('001');

        $response = $this->get("/a/{$asset->id}");

        $response->assertRedirect();
        $this->assertStringNotContainsString($asset->asset_code, $response->getContent() ?: '');
    }

    /**
     * Tahap 6.9 R8.2 (P3-2) — `throttle:qr-redirect`, defined in
     * `AppServiceProvider::configureQrRedirectRateLimiter()` (60/min per IP).
     * `TestCase::setUp()` flushes the cache before every test (see
     * `LoginRateLimitTest`'s own docblock for why), so this never sees hits left
     * over from another test.
     */
    private function requestFromIp(string $path, string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get($path);
    }

    public function test_repeated_requests_from_one_ip_eventually_receive_429(): void
    {
        $asset = $this->existingAsset('001');

        for ($i = 1; $i <= 60; $i++) {
            $response = $this->requestFromIp("/a/{$asset->id}", '203.0.113.40');
            $this->assertNotSame(429, $response->getStatusCode(), "Request {$i} was throttled too early.");
        }

        $this->requestFromIp("/a/{$asset->id}", '203.0.113.40')->assertStatus(429);
    }

    public function test_throttle_does_not_block_normal_scanning_volume(): void
    {
        $asset = $this->existingAsset('001');

        for ($i = 1; $i <= 5; $i++) {
            $this->requestFromIp("/a/{$asset->id}", '203.0.113.41')->assertRedirect("/inventory/{$asset->id}");
        }
    }

    public function test_a_different_ip_is_not_affected_by_another_ips_throttle(): void
    {
        $asset = $this->existingAsset('001');

        for ($i = 1; $i <= 60; $i++) {
            $this->requestFromIp("/a/{$asset->id}", '203.0.113.42');
        }
        $this->requestFromIp("/a/{$asset->id}", '203.0.113.42')->assertStatus(429);

        // A different IP is on its own separate bucket, unaffected.
        $this->requestFromIp("/a/{$asset->id}", '203.0.113.43')->assertRedirect("/inventory/{$asset->id}");
    }
}
