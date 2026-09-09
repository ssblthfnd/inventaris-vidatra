<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\User;
use App\Services\Asset\AssetMutationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.4 §11, §33 (11–15), §34 — mutation logging on relocation.
 */
class AssetMutationLogTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->operator());
    }

    public function test_room_change_writes_a_pindah_ruangan_log_with_from_and_to(): void
    {
        $roomA = $this->room('ZL', ['name' => 'Ruang A']);
        $roomB = $this->room('ZL', ['name' => 'Ruang B']);
        $asset = $this->existingAsset('001', overrides: ['room_id' => $roomA->id]);

        $this->patchJson("/api/assets/{$asset->id}", ['room_id' => $roomB->id])->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame('pindah_ruangan', $log->type);
        $this->assertSame($roomA->id, $log->from_room_id);
        $this->assertSame($roomB->id, $log->to_room_id);
        $this->assertSame('Ruang A', $log->from_room_label);
        $this->assertSame('Ruang B', $log->to_room_label);
        $this->assertSame('ZL', $log->from_location_code);
        $this->assertSame('ZL', $log->to_location_code);
        $this->assertNotNull($log->performed_by);
        $this->assertNotNull($log->created_at);
    }

    public function test_location_change_captures_old_and_new_location(): void
    {
        $this->scope('ZL', 'ZC', '001');
        $this->scope('ZM', 'ZC', '001');
        $asset = $this->existingAsset('001', loc: 'ZL');

        $this->patchJson("/api/assets/{$asset->id}", ['location_code' => 'ZM', 'room_id' => null])->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame('ZL', $log->from_location_code);
        $this->assertSame('ZM', $log->to_location_code);
        $this->assertNull($log->from_room_id);
        $this->assertNull($log->to_room_id);
    }

    public function test_location_and_room_change_together(): void
    {
        $roomOld = $this->room('ZL', ['name' => 'Lama']);
        $this->scope('ZM', 'ZC', '001');
        $roomNew = $this->room('ZM', ['name' => 'Baru']);
        $asset = $this->existingAsset('001', loc: 'ZL', overrides: ['room_id' => $roomOld->id]);

        $this->patchJson("/api/assets/{$asset->id}", [
            'location_code' => 'ZM',
            'room_id' => $roomNew->id,
        ])->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame('ZL', $log->from_location_code);
        $this->assertSame('ZM', $log->to_location_code);
        $this->assertSame($roomOld->id, $log->from_room_id);
        $this->assertSame($roomNew->id, $log->to_room_id);
        $this->assertSame('Lama', $log->from_room_label);
        $this->assertSame('Baru', $log->to_room_label);
    }

    public function test_assigning_a_room_where_there_was_none_logs_the_move(): void
    {
        $room = $this->room('ZL', ['name' => 'Gudang']);
        $asset = $this->existingAsset('001', overrides: ['room_id' => null]);

        $this->patchJson("/api/assets/{$asset->id}", ['room_id' => $room->id])->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertNull($log->from_room_id);
        $this->assertNull($log->from_room_label);
        $this->assertSame($room->id, $log->to_room_id);
        $this->assertSame('Gudang', $log->to_room_label);
    }

    public function test_mutation_note_is_stored_when_provided(): void
    {
        $roomA = $this->room('ZL');
        $roomB = $this->room('ZL');
        $asset = $this->existingAsset('001', overrides: ['room_id' => $roomA->id]);

        $this->patchJson("/api/assets/{$asset->id}", [
            'room_id' => $roomB->id,
            'mutation_note' => 'dipindah karena renovasi',
        ])->assertOk();

        $this->assertSame(
            'dipindah karena renovasi',
            MutationLog::query()->where('asset_id', $asset->id)->value('notes'),
        );
    }

    public function test_a_failing_mutation_recorder_rolls_the_whole_update_back(): void
    {
        $this->app->bind(AssetMutationRecorder::class, function () {
            return new class extends AssetMutationRecorder
            {
                public function recordRelocation(Asset $asset, array $before, array $after, User $actor, ?string $note = null): MutationLog
                {
                    throw new RuntimeException('boom');
                }
            };
        });

        $roomA = $this->room('ZL');
        $roomB = $this->room('ZL');
        $asset = $this->existingAsset('001', overrides: ['room_id' => $roomA->id, 'brand_model' => 'original']);

        // the recorder throws inside DB::transaction() — the exception handler turns it
        // into a 500, but the whole update must have rolled back.
        $this->patchJson("/api/assets/{$asset->id}", [
            'room_id' => $roomB->id,
            'brand_model' => 'should-not-persist',
        ])->assertStatus(500);

        $asset->refresh();
        $this->assertSame($roomA->id, (int) $asset->room_id, 'room change rolled back');
        $this->assertSame('original', $asset->brand_model, 'field change rolled back');
        $this->assertSame(0, MutationLog::query()->where('asset_id', $asset->id)->count(), 'no log persisted');
    }

    public function test_existing_mutation_history_is_never_touched_by_writes(): void
    {
        $asset = $this->existingAsset('001');
        $legacy = MutationLog::factory()->create(['asset_id' => $asset->id, 'type' => 'perbaikan']);

        $this->patchJson("/api/assets/{$asset->id}", ['brand_model' => 'x'])->assertOk();
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->assertDatabaseHas('mutation_logs', ['id' => $legacy->id, 'type' => 'perbaikan']);
    }
}
