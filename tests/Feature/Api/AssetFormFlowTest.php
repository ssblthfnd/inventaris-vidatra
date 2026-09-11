<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\MutationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.8.3 — gaps around the Create / Edit form flow that the SPA relies on and
 * that were not already covered by {@see AssetCreateTest} / {@see AssetUpdateTest} /
 * {@see AssetWriteAuthorizationTest} / {@see AssetMutationLogTest}.
 */
class AssetFormFlowTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /* ------------------------------------------------------------- create */

    public function test_create_response_carries_the_new_id_and_generated_code_for_redirect(): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 2025]))
            ->assertStatus(201);

        $id = $response->json('data.id');
        $this->assertIsInt($id);
        $this->assertSame('ZL.ZC.001.001.2025', $response->json('data.asset_code'));
        $this->assertDatabaseHas('assets', ['id' => $id, 'sequence_no' => '001']);
    }

    public function test_admin_can_create(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/assets', $this->validCreatePayload())->assertStatus(201);
    }

    public function test_create_ignores_protected_columns_sent_in_the_body(): void
    {
        Sanctum::actingAs($this->operator());
        $other = $this->viewer();

        $this->postJson('/api/assets', $this->validCreatePayload([
            'created_by' => $other->id,
            'updated_by' => $other->id,
            'import_row_id' => 999999,
            'is_written_off' => false,
        ]))->assertStatus(201);

        $asset = Asset::query()->latest('id')->firstOrFail();
        $this->assertNull($asset->import_row_id);
        $this->assertNotSame($other->id, $asset->created_by, 'created_by must be the actor, not the body');
        $this->assertNotSame($other->id, $asset->updated_by);
    }

    /* ------------------------------------------------------------- edit */

    public function test_admin_can_update(): void
    {
        Sanctum::actingAs($this->admin());
        $asset = $this->existingAsset('001', overrides: ['brand_model' => 'Old']);

        $this->putJson("/api/assets/{$asset->id}", ['brand_model' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.brand_model', 'New');
    }

    public function test_update_works_over_the_put_verb_used_by_the_spa(): void
    {
        Sanctum::actingAs($this->operator());
        $asset = $this->existingAsset('017', year: 2021);
        $code = $asset->asset_code;

        $this->putJson("/api/assets/{$asset->id}", [
            'serial_no' => 'SN-PUT-1',
            'condition' => 'kurang_baik',
        ])
            ->assertOk()
            ->assertJsonPath('data.serial_no', 'SN-PUT-1')
            ->assertJsonPath('data.condition', 'kurang_baik')
            ->assertJsonPath('data.asset_code', $code)
            ->assertJsonPath('data.sequence_no', '017');
    }

    public function test_update_ignores_protected_columns_sent_in_the_body(): void
    {
        Sanctum::actingAs($this->operator());
        $other = $this->viewer();
        $asset = $this->existingAsset('001', overrides: ['import_row_id' => null]);

        $this->putJson("/api/assets/{$asset->id}", [
            'brand_model' => 'legit change',
            'created_by' => $other->id,
            'import_row_id' => 123456,
        ])->assertOk();

        $asset->refresh();
        $this->assertSame('legit change', $asset->brand_model);
        $this->assertNull($asset->import_row_id);
        $this->assertNotSame($other->id, $asset->created_by);
    }

    public function test_room_change_via_put_records_exactly_one_mutation(): void
    {
        Sanctum::actingAs($this->operator());
        $roomA = $this->room('ZL', ['name' => 'A']);
        $roomB = $this->room('ZL', ['name' => 'B']);
        $asset = $this->existingAsset('001', overrides: ['room_id' => $roomA->id]);

        $this->putJson("/api/assets/{$asset->id}", [
            'room_id' => $roomB->id,
            'mutation_note' => 'renovasi',
        ])->assertOk();

        $logs = MutationLog::query()->where('asset_id', $asset->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('pindah_ruangan', $logs[0]->type);
        $this->assertSame('renovasi', $logs[0]->notes);
    }

    public function test_pure_descriptive_edit_via_put_records_an_edit_mutation(): void
    {
        // Tahap 5.8.8: a descriptive-only edit (no room/location change) is now
        // audited as a generic EDIT event, not silently skipped.
        Sanctum::actingAs($this->operator());
        $room = $this->room('ZL');
        $asset = $this->existingAsset('001', overrides: [
            'room_id' => $room->id, 'brand_model' => 'original', 'condition' => 'baik',
        ]);

        $this->putJson("/api/assets/{$asset->id}", [
            'brand_model' => 'changed',
            'notes' => 'note only',
            'condition' => 'rusak_berat',
        ])->assertOk();

        $log = MutationLog::query()->where('asset_id', $asset->id)->sole();
        $this->assertSame('EDIT', $log->event_type->value);
        $this->assertNull($log->type);
        $this->assertSame('original', $log->before_snapshot['brand_model']);
        $this->assertSame('changed', $log->after_snapshot['brand_model']);
        $this->assertSame('baik', $log->before_snapshot['condition']);
        $this->assertSame('rusak_berat', $log->after_snapshot['condition']);
    }

    public function test_pure_no_op_edit_via_put_records_no_mutation(): void
    {
        Sanctum::actingAs($this->operator());
        $room = $this->room('ZL');
        $asset = $this->existingAsset('001', overrides: [
            'room_id' => $room->id, 'brand_model' => 'same', 'condition' => 'baik',
        ]);

        // resubmits the exact same values already on the asset
        $this->putJson("/api/assets/{$asset->id}", [
            'brand_model' => 'same',
            'condition' => 'baik',
        ])->assertOk();

        $this->assertSame(0, MutationLog::query()->where('asset_id', $asset->id)->count());
    }

    public function test_viewer_cannot_reach_create_or_edit(): void
    {
        Sanctum::actingAs($this->viewer());
        $asset = $this->existingAsset('001');

        $this->postJson('/api/assets', $this->validCreatePayload())->assertStatus(403);
        $this->putJson("/api/assets/{$asset->id}", ['brand_model' => 'x'])->assertStatus(403);
    }
}
