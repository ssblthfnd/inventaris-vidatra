<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.8.5 — gaps the lifecycle UI relies on and that were not already covered by
 * {@see AssetLifecycleTest} / {@see AssetWriteAuthorizationTest}:
 *  - admin (not just operator) can run every lifecycle action;
 *  - `AssetResource.is_trashed` is present and correct.
 */
class AssetLifecycleFrontendContractTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    public function test_admin_can_run_every_lifecycle_action(): void
    {
        Sanctum::actingAs($this->admin());
        $asset = $this->existingAsset('001');

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])
            ->assertOk()->assertJsonPath('data.is_written_off', true);

        $this->postJson("/api/assets/{$asset->id}/unwrite-off")
            ->assertOk()->assertJsonPath('data.is_written_off', false);

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->postJson("/api/assets/{$asset->id}/restore")
            ->assertOk()->assertJsonPath('data.id', $asset->id);
    }

    public function test_is_trashed_is_false_for_an_active_asset(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.is_trashed', false);

        $this->getJson('/api/assets')
            ->assertOk()
            ->assertJsonPath('data.0.is_trashed', false);
    }

    public function test_lifecycle_responses_carry_is_trashed(): void
    {
        Sanctum::actingAs($this->operator());

        $created = $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.is_trashed', false);
        $id = $created->json('data.id');

        $this->postJson("/api/assets/{$id}/write-off", ['written_off_on' => '2024-01-01'])
            ->assertOk()
            ->assertJsonPath('data.is_trashed', false)
            ->assertJsonPath('data.is_written_off', true);

        $this->deleteJson("/api/assets/{$id}")->assertNoContent();

        $this->postJson("/api/assets/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_trashed', false);
    }

    public function test_a_soft_deleted_asset_cannot_be_written_off_or_edited(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        // lifecycle routes without ->withTrashed() still treat it as gone
        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])
            ->assertStatus(404);
        $this->patchJson("/api/assets/{$asset->id}", ['brand_model' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(404);
    }
}
