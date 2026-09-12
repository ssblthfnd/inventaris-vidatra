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
}
