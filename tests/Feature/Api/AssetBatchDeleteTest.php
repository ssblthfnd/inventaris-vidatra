<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\ImportRow;
use App\Models\MutationLog;
use App\Services\Asset\AssetWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.8.7 — `DELETE /api/assets/batch`.
 */
class AssetBatchDeleteTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /* ------------------------------------------------------------------ authorization */

    public function test_unauthenticated_gets_401(): void
    {
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertStatus(401);
    }

    public function test_viewer_gets_403(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertStatus(403);
    }

    public function test_operator_is_allowed(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();
    }

    public function test_admin_is_allowed(): void
    {
        Sanctum::actingAs($this->admin());
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();
    }

    public function test_inactive_operator_gets_403(): void
    {
        Sanctum::actingAs($this->operator(active: false));
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertStatus(403);
    }

    /* ------------------------------------------------------------------ validation */

    public function test_missing_asset_ids_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->deleteJson('/api/assets/batch', [])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    public function test_empty_asset_ids_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->deleteJson('/api/assets/batch', ['asset_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    public function test_duplicate_asset_ids_are_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id, $asset->id]])
            ->assertStatus(422);
    }

    public function test_nonexistent_asset_id_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id, 999999]])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids.1');
    }

    public function test_more_than_maximum_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $ids = range(1, 1001);
        $this->deleteJson('/api/assets/batch', ['asset_ids' => $ids])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    /* ------------------------------------------------------------------ batch behavior */

    public function test_single_asset_batch_delete(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $response = $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();

        $response->assertJsonPath('requested', 1);
        $response->assertJsonPath('deleted', 1);
        $this->assertSoftDeleted('assets', ['id' => $asset->id]);
    }

    public function test_multiple_asset_batch_delete(): void
    {
        Sanctum::actingAs($this->operator());
        $assets = collect(range(1, 5))->map(
            fn (int $i) => $this->existingAsset(str_pad((string) $i, 3, '0', STR_PAD_LEFT))
        );

        $response = $this->deleteJson('/api/assets/batch', ['asset_ids' => $assets->pluck('id')->all()])
            ->assertOk();

        $response->assertJsonPath('requested', 5);
        $response->assertJsonPath('deleted', 5);
        foreach ($assets as $asset) {
            $this->assertSoftDeleted('assets', ['id' => $asset->id]);
        }
    }

    public function test_rows_remain_in_database_after_batch_delete(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();

        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    public function test_asset_code_sequence_and_year_are_never_changed(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('017', year: 2021);
        $originalCode = $asset->asset_code;

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame('017', $fresh->sequence_no);
        $this->assertSame($originalCode, $fresh->asset_code);
        $this->assertSame(2021, $fresh->asset_year);
    }

    public function test_import_rows_are_never_touched(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');
        $importRow = ImportRow::factory()->create();
        $asset->import_row_id = $importRow->id;
        $asset->saveQuietly();

        $countBefore = ImportRow::query()->count();

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();

        $this->assertSame($countBefore, ImportRow::query()->count());
        $this->assertDatabaseHas('import_rows', ['id' => $importRow->id]);
    }

    public function test_write_off_state_is_never_changed(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001', overrides: [
            'is_written_off' => true,
            'written_off_on' => '2024-01-01',
            'written_off_note' => 'sudah rusak',
        ]);

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();

        $fresh = $asset->fresh();
        $this->assertTrue($fresh->is_written_off);
        $this->assertSame('2024-01-01', $fresh->written_off_on->toDateString());
        $this->assertSame('sudah rusak', $fresh->written_off_note);
    }

    public function test_batch_delete_records_one_batch_delete_event_per_asset(): void
    {
        // Tahap 5.8.8: batch delete records one BATCH_DELETE event per asset,
        // sharing one batch_operation_id — no new mutation type is invented, and no
        // mutation log existed for this action before 5.8.8.
        Sanctum::actingAs($this->operator());
        $assets = collect(range(1, 3))->map(
            fn (int $i) => $this->existingAsset(str_pad((string) $i, 3, '0', STR_PAD_LEFT))
        );

        $this->deleteJson('/api/assets/batch', ['asset_ids' => $assets->pluck('id')->all()])->assertOk();

        $logs = MutationLog::query()->whereIn('asset_id', $assets->pluck('id'))->get();
        $this->assertCount(3, $logs);
        $this->assertTrue($logs->every(fn (MutationLog $l) => $l->event_type->value === 'BATCH_DELETE'));
        $this->assertNull($logs->first()->type);
        $this->assertCount(1, $logs->pluck('batch_operation_id')->unique());
        $this->assertNotNull($logs->first()->before_snapshot);
        $this->assertNotNull($logs->first()->after_snapshot['deleted_at']);
    }

    public function test_mixed_condition_room_and_write_off_state_does_not_block_delete(): void
    {
        Sanctum::actingAs($this->operator());
        $room = $this->room('ZL');
        $a1 = $this->existingAsset('001', overrides: ['condition' => null, 'room_id' => $room->id]);
        $a2 = $this->existingAsset('002', overrides: [
            'condition' => 'rusak_berat', 'is_written_off' => true, 'written_off_on' => '2024-01-01',
        ]);

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$a1->id, $a2->id]])->assertOk();

        $this->assertSoftDeleted('assets', ['id' => $a1->id]);
        $this->assertSoftDeleted('assets', ['id' => $a2->id]);
    }

    /* ------------------------------------------------------------------ atomicity */

    public function test_one_already_trashed_asset_causes_full_rollback(): void
    {
        Sanctum::actingAs($this->operator());
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $c = $this->existingAsset('003');
        $c->delete(); // already trashed

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$a->id, $b->id, $c->id]])
            ->assertStatus(422);

        $this->assertNotSoftDeleted('assets', ['id' => $a->id]);
        $this->assertNotSoftDeleted('assets', ['id' => $b->id]);
        $this->assertSoftDeleted('assets', ['id' => $c->id]); // unchanged, was already trashed
    }

    public function test_already_trashed_asset_alone_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');
        $asset->delete();

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids.0');
    }

    public function test_service_level_concurrent_delete_does_not_partially_apply(): void
    {
        // Bypasses the FormRequest to exercise the service's own under-lock re-check:
        // asset C is soft-deleted AFTER validation would have passed (simulating a
        // concurrent delete that landed between request validation and the lock).
        $a = $this->existingAsset('001');
        $b = $this->existingAsset('002');
        $c = $this->existingAsset('003');
        $c->delete();

        $service = app(AssetWriteService::class);
        $actor = $this->operator();

        $this->expectException(NotFoundHttpException::class);

        try {
            $service->batchSoftDelete([$a->id, $b->id, $c->id], $actor);
        } finally {
            $this->assertNotSoftDeleted('assets', ['id' => $a->id]);
            $this->assertNotSoftDeleted('assets', ['id' => $b->id]);
        }
    }

    /* ------------------------------------------------------------------ restore compatibility */

    public function test_batch_deleted_asset_can_be_restored_via_existing_endpoint(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('009', year: 2021);
        $code = $asset->asset_code;

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset->id]])->assertOk();

        $this->postJson("/api/assets/{$asset->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.sequence_no', '009')
            ->assertJsonPath('data.asset_code', $code);

        $this->assertNotSoftDeleted('assets', ['id' => $asset->id]);
    }

    /* ------------------------------------------------------------------ regression */

    public function test_active_inventory_list_excludes_batch_deleted_assets(): void
    {
        Sanctum::actingAs($this->operator());
        $keep = $this->existingAsset('001');
        $remove = $this->existingAsset('002');

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$remove->id]])->assertOk();

        $this->getJson('/api/assets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $keep->id);
    }

    public function test_existing_individual_soft_delete_still_works(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->assertSoftDeleted('assets', ['id' => $asset->id]);
    }
}
