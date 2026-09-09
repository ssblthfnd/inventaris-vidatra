<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.4 §14, §30 — every write endpoint: unauthenticated → 401,
 * viewer / inactive → 403, operator & admin → success.
 */
class AssetWriteAuthorizationTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function writeEndpoints(): array
    {
        return [
            'create' => ['post', '/api/assets'],
            'update' => ['patch', '/api/assets/{id}'],
            'delete' => ['delete', '/api/assets/{id}'],
            'write-off' => ['post', '/api/assets/{id}/write-off'],
            'unwrite-off' => ['post', '/api/assets/{id}/unwrite-off'],
            'restore' => ['post', '/api/assets/{id}/restore'],
        ];
    }

    #[DataProvider('writeEndpoints')]
    public function test_unauthenticated_gets_401(string $method, string $path): void
    {
        $asset = $this->existingAsset('001');

        $this->json($method, str_replace('{id}', (string) $asset->id, $path), $this->bodyFor($path))
            ->assertStatus(401);
    }

    #[DataProvider('writeEndpoints')]
    public function test_viewer_gets_403(string $method, string $path): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $this->json($method, str_replace('{id}', (string) $asset->id, $path), $this->bodyFor($path))
            ->assertStatus(403);
    }

    #[DataProvider('writeEndpoints')]
    public function test_inactive_operator_gets_403(string $method, string $path): void
    {
        Sanctum::actingAs($this->operator(active: false));
        $asset = $this->existingAsset('001');

        $this->json($method, str_replace('{id}', (string) $asset->id, $path), $this->bodyFor($path))
            ->assertStatus(403);
    }

    public function test_operator_and_admin_can_create(): void
    {
        foreach ([$this->operator(), $this->admin()] as $i => $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 2020 + $i]))
                ->assertStatus(201);
        }
    }

    public function test_operator_can_update_delete_writeoff_restore(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson("/api/assets/{$asset->id}", ['brand_model' => 'Updated'])->assertOk();
        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])->assertOk();
        $this->postJson("/api/assets/{$asset->id}/unwrite-off")->assertOk();
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();
        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyFor(string $path): array
    {
        return str_ends_with($path, '/write-off') ? ['written_off_on' => '2024-01-01'] : [];
    }
}
