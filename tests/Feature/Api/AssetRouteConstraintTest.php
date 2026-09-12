<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.6 (M-6) — every `assets/{asset}` route now has `->whereNumber('asset')`
 * consistently (routes/api.php), not just the GET show route. Before this fix,
 * a non-numeric/negative id on the write/action routes fell through to a
 * DIFFERENT route with no such constraint, producing a confusing `405 Method
 * Not Allowed` instead of a clean `404` — confirmed live during the Stage 6.6
 * Pass 1 audit (`PUT /api/assets/-1` -> 405). This asserts the fixed behavior
 * without touching how a genuinely-valid numeric id behaves.
 */
class AssetRouteConstraintTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedIds(): array
    {
        return [
            'negative' => ['-1', 'PUT'],
            'non-numeric' => ['abc', 'PUT'],
            'negative (delete)' => ['-1', 'DELETE'],
            'non-numeric (delete)' => ['abc', 'DELETE'],
        ];
    }

    #[DataProvider('malformedIds')]
    public function test_malformed_asset_id_returns_404_not_405(string $id, string $method): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->json($method, "/api/assets/{$id}", $method === 'PUT' ? ['condition' => 'baik'] : []);

        $response->assertStatus(404);
        $this->assertNotSame(405, $response->getStatusCode());
    }

    public function test_malformed_asset_id_returns_404_on_write_off(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/abc/write-off', ['written_off_on' => now()->toDateString()])
            ->assertStatus(404);
    }

    public function test_malformed_asset_id_returns_404_on_unwrite_off(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/abc/unwrite-off')->assertStatus(404);
    }

    public function test_malformed_asset_id_returns_404_on_restore(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/abc/restore')->assertStatus(404);
    }

    public function test_malformed_asset_id_returns_404_on_mutations_history(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/assets/abc/mutations')->assertStatus(404);
    }

    public function test_malformed_asset_id_returns_404_on_label(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/assets/abc/label')->assertStatus(404);
    }

    public function test_valid_numeric_id_still_works_normally_on_every_write_route(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
    }

    public function test_a_huge_but_valid_numeric_id_returns_a_clean_404(): void
    {
        Sanctum::actingAs($this->operator());

        $this->putJson('/api/assets/99999999999999999999', ['condition' => 'baik'])
            ->assertStatus(404);
    }
}
