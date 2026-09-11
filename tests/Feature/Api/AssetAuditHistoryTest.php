<?php

namespace Tests\Feature\Api;

use App\Enums\MutationEventType;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\User;
use App\Services\Asset\AssetMutationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.8.8 — generic audit/history foundation. Covers every canonical event type
 * that isn't already exhaustively covered by the room-move-specific
 * {@see AssetMutationLogTest} / {@see MutationHistoryApiTest}: CREATE, WRITE_OFF,
 * UNWRITE_OFF, SOFT_DELETE, RESTORE, and the batch event-type/grouping semantics.
 */
class AssetAuditHistoryTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->operator());
    }

    /* ------------------------------------------------------------------ CREATE */

    public function test_individual_create_records_a_create_event(): void
    {
        $actor = $this->operator();
        Sanctum::actingAs($actor);
        $room = $this->room('ZL');

        $response = $this->postJson('/api/assets', $this->validCreatePayload([
            'room_id' => $room->id,
            'brand_model' => 'Merek A',
            'condition' => 'baik',
        ]))->assertStatus(201);
        $assetId = $response->json('data.id');

        $log = MutationLog::query()->where('asset_id', $assetId)->sole();
        $this->assertSame(MutationEventType::Create, $log->event_type);
        $this->assertNull($log->type);
        $this->assertNull($log->before_snapshot);
        $this->assertSame($assetId, $log->after_snapshot['id']);
        $this->assertSame('Merek A', $log->after_snapshot['brand_model']);
        $this->assertSame('baik', $log->after_snapshot['condition']);
        $this->assertSame($room->id, $log->after_snapshot['room_id']);
        $this->assertSame($room->name, $log->after_snapshot['room_name']);
        $this->assertSame($actor->id, $log->performed_by);
        $this->assertNull($log->batch_operation_id);
    }

    public function test_batch_create_shares_one_batch_operation_id_across_create_events(): void
    {
        $this->scope();
        $response = $this->postJson('/api/assets/batch', array_merge(
            $this->validCreatePayload(), ['count' => 4]
        ))->assertStatus(201);
        $ids = collect($response->json('data'))->pluck('id');

        $logs = MutationLog::query()->whereIn('asset_id', $ids)->get();
        $this->assertCount(4, $logs);
        $this->assertTrue($logs->every(fn (MutationLog $l) => $l->event_type === MutationEventType::Create));
        $batchIds = $logs->pluck('batch_operation_id')->unique();
        $this->assertCount(1, $batchIds);
        $this->assertNotNull($batchIds->first());
    }

    /* ------------------------------------------------------------------ WRITE_OFF / UNWRITE_OFF */

    public function test_write_off_records_a_write_off_event_with_correct_snapshots(): void
    {
        $asset = $this->existingAsset('001', overrides: ['is_written_off' => false]);

        $this->postJson("/api/assets/{$asset->id}/write-off", [
            'written_off_on' => '2024-07-01',
            'written_off_note' => 'sudah dilelang',
        ])->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame(MutationEventType::WriteOff, $log->event_type);
        $this->assertNull($log->type);
        $this->assertFalse($log->before_snapshot['is_written_off']);
        $this->assertNull($log->before_snapshot['written_off_on']);
        $this->assertTrue($log->after_snapshot['is_written_off']);
        $this->assertSame('2024-07-01', $log->after_snapshot['written_off_on']);
        $this->assertSame('sudah dilelang', $log->after_snapshot['written_off_note']);
    }

    public function test_unwrite_off_records_an_unwrite_off_event_with_correct_snapshots(): void
    {
        $asset = $this->existingAsset('001', overrides: [
            'is_written_off' => true, 'written_off_on' => '2024-01-01', 'written_off_note' => 'note',
        ]);

        $this->postJson("/api/assets/{$asset->id}/unwrite-off")->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame(MutationEventType::UnwriteOff, $log->event_type);
        $this->assertTrue($log->before_snapshot['is_written_off']);
        $this->assertSame('2024-01-01', $log->before_snapshot['written_off_on']);
        $this->assertFalse($log->after_snapshot['is_written_off']);
        $this->assertNull($log->after_snapshot['written_off_on']);
        $this->assertNull($log->after_snapshot['written_off_note']);
    }

    public function test_write_off_conflict_does_not_record_a_second_event(): void
    {
        $asset = $this->existingAsset('001');
        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])->assertOk();

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-02-01'])->assertStatus(409);

        $this->assertSame(1, MutationLog::query()->where('asset_id', $asset->id)->count());
    }

    /* ------------------------------------------------------------------ SOFT_DELETE / RESTORE */

    public function test_soft_delete_records_a_soft_delete_event(): void
    {
        $actor = $this->operator();
        Sanctum::actingAs($actor);
        $asset = $this->existingAsset('001');

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame(MutationEventType::SoftDelete, $log->event_type);
        $this->assertNull($log->before_snapshot['deleted_at']);
        $this->assertNotNull($log->after_snapshot['deleted_at']);
        $this->assertSame($actor->id, $log->performed_by);

        // row + history both survive
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('mutation_logs', ['id' => $log->id]);
    }

    public function test_restore_records_a_restore_event_and_preserves_identity(): void
    {
        $asset = $this->existingAsset('009', year: 2021);
        $code = $asset->asset_code;
        $asset->delete();

        $this->postJson("/api/assets/{$asset->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.sequence_no', '009')
            ->assertJsonPath('data.asset_code', $code);

        $log = MutationLog::query()->where('asset_id', $asset->id)->where('event_type', 'RESTORE')->sole();
        $this->assertNotNull($log->before_snapshot['deleted_at']);
        $this->assertNull($log->after_snapshot['deleted_at']);
        $this->assertSame($code, $log->after_snapshot['asset_code']);
        $this->assertSame('009', $log->after_snapshot['sequence_no']);
    }

    public function test_restoring_an_already_active_asset_records_no_event(): void
    {
        $asset = $this->existingAsset('001');

        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();

        $this->assertSame(0, MutationLog::query()->where('asset_id', $asset->id)->count());
    }

    /* ------------------------------------------------------------------ trashed asset history (5.8.9) */

    public function test_every_active_role_can_read_a_trashed_assets_history(): void
    {
        $asset = $this->existingAsset('001');
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        foreach ([$this->viewer(), $this->operator(), $this->admin()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/assets/{$asset->id}/mutations")
                ->assertOk()
                ->assertJsonPath('data.0.event_type', 'SOFT_DELETE');
        }
    }

    public function test_trashed_asset_history_is_append_only_across_delete_and_restore(): void
    {
        $asset = $this->existingAsset('001');
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();
        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();

        Sanctum::actingAs($this->viewer());
        $response = $this->getJson("/api/assets/{$asset->id}/mutations")->assertOk();

        $eventTypes = collect($response->json('data'))->pluck('event_type');
        $this->assertSame(['RESTORE', 'SOFT_DELETE'], $eventTypes->all());
    }

    public function test_reading_trashed_asset_history_as_viewer_grants_no_write_access(): void
    {
        $asset = $this->existingAsset('001');
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        Sanctum::actingAs($this->viewer());
        $this->getJson("/api/assets/{$asset->id}/mutations")->assertOk();

        // reading history changed nothing about the viewer's lifecycle permissions
        $this->postJson("/api/assets/{$asset->id}/restore")->assertStatus(403);
        $this->getJson("/api/assets/{$asset->id}")->assertStatus(404); // detail still hidden for viewer
    }

    public function test_inactive_user_is_still_blocked_from_trashed_asset_history(): void
    {
        $asset = $this->existingAsset('001');
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        Sanctum::actingAs(User::factory()->create([
            'role' => UserRole::Viewer, 'is_active' => false,
        ]));
        $this->getJson("/api/assets/{$asset->id}/mutations")->assertStatus(403);
    }

    /* ------------------------------------------------------------------ historical labels */

    public function test_room_name_snapshot_in_generic_events_survives_a_later_rename(): void
    {
        [$location] = $this->scope();
        $room = Room::factory()->forLocation($location)->create(['name' => 'Gudang Lama']);
        $asset = $this->existingAsset('001', overrides: [
            'room_id' => $room->id, 'brand_model' => 'A',
        ]);

        $this->patchJson("/api/assets/{$asset->id}", ['brand_model' => 'B'])->assertOk();

        $room->update(['name' => 'Gudang Baru']);

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame('Gudang Lama', $log->before_snapshot['room_name']);
        $this->assertSame('Gudang Lama', $log->after_snapshot['room_name']);
    }

    /* ------------------------------------------------------------------ API surface */

    public function test_mutations_endpoint_returns_new_event_type_and_snapshot_fields(): void
    {
        $asset = $this->existingAsset('001');
        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])->assertOk();

        Sanctum::actingAs($this->viewer());
        $response = $this->getJson("/api/assets/{$asset->id}/mutations")->assertOk();

        $response->assertJsonPath('data.0.event_type', 'WRITE_OFF');
        $response->assertJsonPath('data.0.mutation_type', null);
        $this->assertNotNull($response->json('data.0.after_snapshot'));
        $this->assertArrayHasKey('batch_operation_id', $response->json('data.0'));
    }

    public function test_mutations_endpoint_filters_by_event_type(): void
    {
        $asset = $this->existingAsset('001');
        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])->assertOk();
        $this->postJson("/api/assets/{$asset->id}/unwrite-off")->assertOk();

        Sanctum::actingAs($this->viewer());
        $this->getJson("/api/assets/{$asset->id}/mutations?event_type=WRITE_OFF")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event_type', 'WRITE_OFF');
    }

    public function test_unknown_event_type_filter_is_rejected(): void
    {
        $asset = $this->existingAsset('001');

        Sanctum::actingAs($this->viewer());
        $this->getJson("/api/assets/{$asset->id}/mutations?event_type=NOT_A_TYPE")
            ->assertStatus(422)->assertJsonValidationErrors('event_type');
    }

    /* ------------------------------------------------------------------ transaction atomicity */

    public function test_a_failing_recorder_rolls_back_a_write_off(): void
    {
        $this->app->bind(AssetMutationRecorder::class, function () {
            return new class extends AssetMutationRecorder
            {
                public function record(
                    Asset $asset,
                    MutationEventType $eventType,
                    ?array $before,
                    array $after,
                    User $actor,
                    ?string $batchOperationId = null,
                    ?string $note = null,
                ): MutationLog {
                    throw new \RuntimeException('boom');
                }
            };
        });

        $asset = $this->existingAsset('001', overrides: ['is_written_off' => false]);

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])
            ->assertStatus(500);

        $this->assertFalse($asset->fresh()->is_written_off);
        $this->assertSame(0, MutationLog::query()->where('asset_id', $asset->id)->count());
    }
}
