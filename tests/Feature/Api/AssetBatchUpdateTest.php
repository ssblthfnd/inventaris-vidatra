<?php

namespace Tests\Feature\Api;

use App\Enums\AssetCondition;
use App\Models\MutationLog;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.8.6 — `PATCH /api/assets/batch`.
 */
class AssetBatchUpdateTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /* ------------------------------------------------------------------ authorization */

    public function test_unauthenticated_gets_401(): void
    {
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertStatus(401);
    }

    public function test_viewer_gets_403(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertStatus(403);
    }

    public function test_operator_is_allowed(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();
    }

    public function test_admin_is_allowed(): void
    {
        Sanctum::actingAs($this->admin());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();
    }

    public function test_inactive_operator_gets_403(): void
    {
        Sanctum::actingAs($this->operator(active: false));
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertStatus(403);
    }

    /* ------------------------------------------------------------------ validation */

    public function test_missing_asset_ids_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->patchJson('/api/assets/batch', ['changes' => ['condition' => 'baik']])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    public function test_empty_asset_ids_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->patchJson('/api/assets/batch', ['asset_ids' => [], 'changes' => ['condition' => 'baik']])
            ->assertStatus(422)->assertJsonValidationErrors('asset_ids');
    }

    public function test_duplicate_asset_ids_are_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id, $asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertStatus(422);
    }

    public function test_nonexistent_asset_id_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id, 999999],
            'changes' => ['condition' => 'baik'],
        ])->assertStatus(422)->assertJsonValidationErrors('asset_ids.1');
    }

    public function test_missing_changes_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', ['asset_ids' => [$asset->id]])
            ->assertStatus(422)->assertJsonValidationErrors('changes');
    }

    public function test_empty_changes_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', ['asset_ids' => [$asset->id], 'changes' => []])
            ->assertStatus(422)->assertJsonValidationErrors('changes');
    }

    public function test_unknown_change_key_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['foo' => 'bar'],
        ])->assertStatus(422)->assertJsonValidationErrors('changes');
    }

    public function test_forbidden_field_asset_year_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['asset_year' => 2030],
        ])->assertStatus(422)->assertJsonValidationErrors('changes');
    }

    public function test_forbidden_lifecycle_field_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['is_written_off' => true],
        ])->assertStatus(422)->assertJsonValidationErrors('changes');
    }

    public function test_invalid_condition_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'hancur'],
        ])->assertStatus(422)->assertJsonValidationErrors('changes.condition');
    }

    public function test_invalid_room_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['room_id' => 999999],
        ])->assertStatus(422)->assertJsonValidationErrors('changes.room_id');
    }

    public function test_null_condition_works(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001', overrides: ['condition' => AssetCondition::Baik]);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => null],
        ])->assertOk();

        $this->assertNull($asset->fresh()->condition);
    }

    public function test_null_notes_works(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001', overrides: ['notes' => 'catatan lama']);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['notes' => null],
        ])->assertOk();

        $this->assertNull($asset->fresh()->notes);
    }

    /* ------------------------------------------------------------------ batch behavior */

    public function test_one_field_update_works(): void
    {
        Sanctum::actingAs($this->operator());
        $a1 = $this->existingAsset('001', overrides: ['brand_model' => 'Keep']);
        $a2 = $this->existingAsset('002', overrides: ['brand_model' => 'Keep']);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a1->id, $a2->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();

        $this->assertSame(AssetCondition::Baik, $a1->fresh()->condition);
        $this->assertSame(AssetCondition::Baik, $a2->fresh()->condition);
        $this->assertSame('Keep', $a1->fresh()->brand_model);
    }

    public function test_multiple_allowed_fields_update_together(): void
    {
        Sanctum::actingAs($this->operator());
        $room = $this->room('ZL', ['name' => 'Ruang Baru']);
        $asset = $this->existingAsset('001', loc: 'ZL');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => [
                'room_id' => $room->id,
                'condition' => 'kurang_baik',
                'notes' => 'dipindah massal',
            ],
        ])->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame($room->id, (int) $fresh->room_id);
        $this->assertSame(AssetCondition::KurangBaik, $fresh->condition);
        $this->assertSame('dipindah massal', $fresh->notes);
    }

    public function test_field_not_included_in_changes_stays_untouched(): void
    {
        Sanctum::actingAs($this->operator());
        $room = $this->room('ZL');
        $asset = $this->existingAsset('001', overrides: ['room_id' => $room->id, 'notes' => 'jangan diubah']);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame($room->id, (int) $fresh->room_id);
        $this->assertSame('jangan diubah', $fresh->notes);
    }

    public function test_asset_with_already_equal_value_is_reported_unchanged(): void
    {
        Sanctum::actingAs($this->operator());
        $a1 = $this->existingAsset('001', overrides: ['condition' => AssetCondition::Baik]);
        $a2 = $this->existingAsset('002', overrides: ['condition' => AssetCondition::KurangBaik]);

        $response = $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a1->id, $a2->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();

        $response->assertJsonPath('requested', 2);
        $response->assertJsonPath('updated', 1);
        $response->assertJsonPath('unchanged', 1);
    }

    public function test_all_assets_update_atomically(): void
    {
        Sanctum::actingAs($this->operator());
        $assets = collect(range(1, 5))->map(
            fn (int $i) => $this->existingAsset(str_pad((string) $i, 3, '0', STR_PAD_LEFT))
        );

        $response = $this->patchJson('/api/assets/batch', [
            'asset_ids' => $assets->pluck('id')->all(),
            'changes' => ['condition' => 'rusak_berat'],
        ])->assertOk();

        $response->assertJsonPath('updated', 5);
        foreach ($assets as $asset) {
            $this->assertSame(AssetCondition::RusakBerat, $asset->fresh()->condition);
        }
    }

    public function test_selected_assets_from_multiple_locations_with_common_condition_works(): void
    {
        Sanctum::actingAs($this->operator());
        $a1 = $this->existingAsset('001', loc: 'ZL');
        $a2 = $this->existingAsset('001', loc: 'ZM');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a1->id, $a2->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();

        $this->assertSame(AssetCondition::Baik, $a1->fresh()->condition);
        $this->assertSame(AssetCondition::Baik, $a2->fresh()->condition);
    }

    public function test_invalid_target_room_for_multi_location_selection_causes_full_rollback(): void
    {
        Sanctum::actingAs($this->operator());
        $roomZl = $this->room('ZL');
        // a1's location matches roomZl (would succeed alone); a2's does not.
        $a1 = $this->existingAsset('001', loc: 'ZL', overrides: ['notes' => 'Original1']);
        $a2 = $this->existingAsset('001', loc: 'ZM', overrides: ['notes' => 'Original2']);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a1->id, $a2->id],
            'changes' => ['room_id' => $roomZl->id, 'notes' => 'Should Not Persist'],
        ])->assertStatus(422);

        $this->assertNull($a1->fresh()->room_id);
        $this->assertNull($a2->fresh()->room_id);
        $this->assertSame('Original1', $a1->fresh()->notes);
        $this->assertSame(0, MutationLog::query()->count());
    }

    /* ------------------------------------------------------------------ room mutation */

    public function test_room_change_creates_pindah_ruangan_mutation_per_changed_asset(): void
    {
        Sanctum::actingAs($this->operator());
        $roomA = $this->room('ZL', ['name' => 'Ruang A']);
        $roomB = $this->room('ZL', ['name' => 'Ruang B']);
        $a1 = $this->existingAsset('001', overrides: ['room_id' => $roomA->id]);
        $a2 = $this->existingAsset('002', overrides: ['room_id' => $roomA->id]);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a1->id, $a2->id],
            'changes' => ['room_id' => $roomB->id],
        ])->assertOk();

        $this->assertSame(2, MutationLog::query()->where('type', 'pindah_ruangan')->count());
        $log1 = MutationLog::query()->where('asset_id', $a1->id)->sole();
        $this->assertSame($roomA->id, $log1->from_room_id);
        $this->assertSame($roomB->id, $log1->to_room_id);
        $this->assertSame('Ruang A', $log1->from_room_label);
        $this->assertSame('Ruang B', $log1->to_room_label);
    }

    public function test_unchanged_room_creates_no_mutation(): void
    {
        Sanctum::actingAs($this->operator());
        $room = $this->room('ZL');
        $asset = $this->existingAsset('001', overrides: ['room_id' => $room->id]);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['room_id' => $room->id],
        ])->assertOk();

        $this->assertSame(0, MutationLog::query()->count());
    }

    public function test_no_op_condition_and_notes_changes_create_no_mutation(): void
    {
        Sanctum::actingAs($this->operator());
        // default factory state: condition=baik, notes=null — both changes below
        // resubmit the values the asset already has.
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik', 'notes' => null],
        ])->assertOk();

        $this->assertSame(0, MutationLog::query()->count());
    }

    public function test_condition_and_notes_changes_create_a_batch_edit_mutation(): void
    {
        // Tahap 5.8.8: a batch edit that changes non-room fields is audited as
        // BATCH_EDIT, not silently skipped.
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'rusak_berat', 'notes' => 'x'],
        ])->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame('BATCH_EDIT', $log->event_type->value);
        $this->assertNull($log->type);
        $this->assertNotNull($log->batch_operation_id);
        $this->assertSame('baik', $log->before_snapshot['condition']);
        $this->assertSame('rusak_berat', $log->after_snapshot['condition']);
        $this->assertSame('x', $log->after_snapshot['notes']);
    }

    /* ------------------------------------------------------------------ integrity */

    public function test_asset_code_sequence_and_year_are_never_changed(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('017', year: 2021);
        $originalCode = $asset->asset_code;

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame('017', $fresh->sequence_no);
        $this->assertSame($originalCode, $fresh->asset_code);
        $this->assertSame(2021, $fresh->asset_year);
    }

    public function test_write_off_state_is_never_changed(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001', overrides: [
            'is_written_off' => true,
            'written_off_on' => '2024-01-01',
        ]);

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertOk();

        $fresh = $asset->fresh();
        $this->assertTrue($fresh->is_written_off);
        $this->assertSame('2024-01-01', $fresh->written_off_on->toDateString());
    }

    public function test_trashed_asset_is_excluded_and_cannot_be_batch_edited(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001');
        $asset->delete();

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['condition' => 'baik'],
        ])->assertStatus(422)->assertJsonValidationErrors('asset_ids.0');

        $this->assertTrue($asset->fresh()->trashed());
    }

    public function test_room_from_another_location_is_rejected_for_a_single_asset(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('001', loc: 'ZL');
        $foreignRoom = $this->room('ZM');

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset->id],
            'changes' => ['room_id' => $foreignRoom->id],
        ])->assertStatus(422);

        $this->assertNull($asset->fresh()->room_id);
    }
}
