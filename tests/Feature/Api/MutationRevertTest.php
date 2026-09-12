<?php

namespace Tests\Feature\Api;

use App\Enums\MutationEventType;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Services\Asset\AssetMutationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.5 — `POST /api/mutations/{mutation}/revert`.
 *
 * Every mutation used here is produced by the REAL write endpoints
 * (`PUT /api/assets/{id}`, write-off/unwrite-off, batch edit, soft-delete,
 * restore) rather than hand-built `MutationLog` rows, so these tests exercise
 * the actual `before_snapshot`/`after_snapshot` shape
 * `AssetMutationRecorder` produces — the same data the revert engine reads.
 */
class MutationRevertTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private function latestLogFor(Asset $asset): MutationLog
    {
        return MutationLog::query()->where('asset_id', $asset->id)->latest('id')->first();
    }

    /* ================================================================== authorization */

    public function test_unauthenticated_cannot_revert(): void
    {
        // Built directly (no Sanctum::actingAs / HTTP call) so the test client
        // stays genuinely unauthenticated for the actual assertion below —
        // Sanctum::actingAs() persists for the rest of the test method once called.
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        $log = $this->recordDirectConditionEdit($asset, 'kurang_baik');

        $this->postJson("/api/mutations/{$log->id}/revert")->assertStatus(401);

        $this->assertSame('kurang_baik', $asset->fresh()->condition->value);
    }

    /** Writes a realistic EDIT mutation log directly via the recorder, with no
     *  Sanctum session involved — used only where a test must stay unauthenticated. */
    private function recordDirectConditionEdit(Asset $asset, string $to): MutationLog
    {
        $recorder = app(AssetMutationRecorder::class);
        $before = $recorder->snapshot($asset);
        $asset->condition = $to;
        $asset->save();
        $asset->refresh();
        $after = $recorder->snapshot($asset);

        return $recorder->record($asset, MutationEventType::Edit, $before, $after, $this->operator());
    }

    public function test_viewer_cannot_revert(): void
    {
        $asset = $this->existingAsset('001');
        $log = $this->makeConditionEditLog($asset);

        Sanctum::actingAs($this->viewer());
        $this->postJson("/api/mutations/{$log->id}/revert")->assertStatus(403);

        $this->assertSame('kurang_baik', $asset->fresh()->condition->value);
    }

    public function test_operator_can_revert_supported_mutations(): void
    {
        $asset = $this->existingAsset('001');
        $log = $this->makeConditionEditLog($asset);

        Sanctum::actingAs($this->operator());
        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();
    }

    public function test_admin_can_revert_supported_mutations(): void
    {
        $asset = $this->existingAsset('001');
        $log = $this->makeConditionEditLog($asset);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();
    }

    public function test_missing_mutation_is_404(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/mutations/999999/revert')->assertStatus(404);
    }

    /* ================================================================== individual update revert */

    /** Creates a condition edit Baik -> Kurang Baik via the real write endpoint. */
    private function makeConditionEditLog(Asset $asset, string $to = 'kurang_baik'): MutationLog
    {
        Sanctum::actingAs($this->operator());
        $this->putJson("/api/assets/{$asset->id}", ['condition' => $to])->assertOk();

        return $this->latestLogFor($asset);
    }

    public function test_individual_edit_revert_restores_asset_and_logs_correctly(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        $operator = $this->operator();
        Sanctum::actingAs($operator);

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $original = $this->latestLogFor($asset);
        $this->assertSame(MutationEventType::Edit, $original->event_type);

        $response = $this->postJson("/api/mutations/{$original->id}/revert")->assertOk();

        $this->assertSame('baik', $asset->fresh()->condition->value);

        // original mutation untouched
        $this->assertNotNull($original->fresh());
        $this->assertNull($original->fresh()->reverted_mutation_id);

        // new revert mutation exists, correctly linked
        $revertLog = MutationLog::query()->where('reverted_mutation_id', $original->id)->first();
        $this->assertNotNull($revertLog);
        $this->assertSame(MutationEventType::Revert, $revertLog->event_type);
        $this->assertSame($operator->id, $revertLog->performed_by);
        $this->assertSame('kurang_baik', $revertLog->before_snapshot['condition']);
        $this->assertSame('baik', $revertLog->after_snapshot['condition']);

        $response->assertJsonPath('reverted_mutation_ids', [$original->id]);
        $response->assertJsonCount(1, 'new_mutations');
        $response->assertJsonPath('new_mutations.0.reverted_mutation_id', $original->id);
    }

    public function test_move_room_revert_restores_room(): void
    {
        $roomA = $this->room('01', ['name' => 'Ruang Guru']);
        $roomB = $this->room('01', ['name' => 'Gudang']);
        $asset = $this->existingAsset('001', overrides: ['room_id' => $roomA->id], loc: '01');
        Sanctum::actingAs($this->operator());

        $this->putJson("/api/assets/{$asset->id}", ['room_id' => $roomB->id])->assertOk();
        $original = $this->latestLogFor($asset);
        $this->assertSame(MutationEventType::MoveRoom, $original->event_type);

        $this->postJson("/api/mutations/{$original->id}/revert")->assertOk();

        $this->assertSame($roomA->id, $asset->fresh()->room_id);
    }

    /* ================================================================== field-level conflict */

    public function test_conflicting_field_change_after_the_mutation_returns_409(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        Sanctum::actingAs($this->operator());

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $first = $this->latestLogFor($asset);

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'rusak_berat'])->assertOk();

        $response = $this->postJson("/api/mutations/{$first->id}/revert");
        $response->assertStatus(409);

        $this->assertSame('rusak_berat', $asset->fresh()->condition->value);
        $this->assertNull($first->fresh()->reverted_mutation_id);
        $this->assertDatabaseMissing('mutation_logs', ['reverted_mutation_id' => $first->id]);
    }

    /* ================================================================== unrelated-field change */

    public function test_revert_succeeds_when_only_an_unrelated_field_changed_since(): void
    {
        $roomA = $this->room('01', ['name' => 'Ruang Guru']);
        $roomB = $this->room('01', ['name' => 'Gudang']);
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik', 'room_id' => $roomA->id], loc: '01');
        Sanctum::actingAs($this->operator());

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $conditionMutation = $this->latestLogFor($asset);

        // unrelated field changes afterward
        $this->putJson("/api/assets/{$asset->id}", ['room_id' => $roomB->id])->assertOk();

        $this->postJson("/api/mutations/{$conditionMutation->id}/revert")->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame('baik', $fresh->condition->value);
        // the unrelated room change made afterward is NOT touched by this revert
        $this->assertSame($roomB->id, $fresh->room_id);
    }

    /* ================================================================== double revert */

    public function test_reverting_the_same_mutation_twice_is_rejected(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        Sanctum::actingAs($this->operator());

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $original = $this->latestLogFor($asset);

        $this->postJson("/api/mutations/{$original->id}/revert")->assertOk();
        $countAfterFirstRevert = MutationLog::count();

        $response = $this->postJson("/api/mutations/{$original->id}/revert");
        $response->assertStatus(409);

        $this->assertSame($countAfterFirstRevert, MutationLog::count());
        $this->assertSame(
            1,
            MutationLog::query()->where('reverted_mutation_id', $original->id)->count(),
        );
    }

    public function test_reverting_a_revert_log_itself_is_rejected(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        Sanctum::actingAs($this->operator());

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $original = $this->latestLogFor($asset);
        $this->postJson("/api/mutations/{$original->id}/revert")->assertOk();
        $revertLog = MutationLog::query()->where('reverted_mutation_id', $original->id)->first();

        $this->postJson("/api/mutations/{$revertLog->id}/revert")->assertStatus(409);
    }

    /* ================================================================== batch revert */

    public function test_batch_revert_succeeds_when_every_asset_is_compatible(): void
    {
        $asset1 = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        $asset2 = $this->existingAsset('002', overrides: ['condition' => 'baik']);
        Sanctum::actingAs($this->operator());

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset1->id, $asset2->id],
            'changes' => ['condition' => 'rusak_berat'],
        ])->assertOk();

        $log1 = $this->latestLogFor($asset1);
        $log2 = $this->latestLogFor($asset2);
        $this->assertNotNull($log1->batch_operation_id);
        $this->assertSame($log1->batch_operation_id, $log2->batch_operation_id);

        $response = $this->postJson("/api/mutations/{$log1->id}/revert")->assertOk();

        $this->assertSame('baik', $asset1->fresh()->condition->value);
        $this->assertSame('baik', $asset2->fresh()->condition->value);
        $response->assertJsonCount(2, 'new_mutations');
        $response->assertJsonCount(2, 'assets');

        $newLogs = MutationLog::query()->whereIn('reverted_mutation_id', [$log1->id, $log2->id])->get();
        $this->assertCount(2, $newLogs);
        $this->assertTrue($newLogs->every(fn (MutationLog $l) => $l->event_type === MutationEventType::BatchRevert));
        $this->assertNotNull($newLogs->first()->batch_operation_id);
        $this->assertSame($newLogs->first()->batch_operation_id, $newLogs->last()->batch_operation_id);
    }

    public function test_batch_revert_rolls_back_entirely_when_one_asset_conflicts(): void
    {
        $asset1 = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        $asset2 = $this->existingAsset('002', overrides: ['condition' => 'baik']);
        Sanctum::actingAs($this->operator());

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$asset1->id, $asset2->id],
            'changes' => ['condition' => 'rusak_berat'],
        ])->assertOk();

        $log1 = $this->latestLogFor($asset1);
        $log2 = $this->latestLogFor($asset2);

        // asset2 changes again AFTER the batch edit -> conflicts with the batch revert
        $this->putJson("/api/assets/{$asset2->id}", ['condition' => 'kurang_baik'])->assertOk();

        $countBefore = MutationLog::count();

        $response = $this->postJson("/api/mutations/{$log1->id}/revert");
        $response->assertStatus(409);

        // NOTHING changed — not even asset1, which was individually compatible
        $this->assertSame('rusak_berat', $asset1->fresh()->condition->value);
        $this->assertSame('kurang_baik', $asset2->fresh()->condition->value);
        $this->assertSame($countBefore, MutationLog::count());
        $this->assertNull($log1->fresh()->reverted_mutation_id);
        $this->assertDatabaseMissing('mutation_logs', ['reverted_mutation_id' => $log1->id]);
        $this->assertDatabaseMissing('mutation_logs', ['reverted_mutation_id' => $log2->id]);
    }

    /* ================================================================== lifecycle */

    public function test_write_off_revert_restores_active_status(): void
    {
        $asset = $this->existingAsset('001');
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/assets/{$asset->id}/write-off", [
            'written_off_on' => now()->toDateString(),
            'written_off_note' => 'Rusak total',
        ])->assertOk();
        $log = $this->latestLogFor($asset);
        $this->assertSame(MutationEventType::WriteOff, $log->event_type);

        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $fresh = $asset->fresh();
        $this->assertFalse($fresh->is_written_off);
        $this->assertNull($fresh->written_off_on);
        $this->assertNull($fresh->written_off_note);
    }

    public function test_unwrite_off_revert_restores_written_off_status(): void
    {
        $asset = $this->existingAsset('001');
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/assets/{$asset->id}/write-off", [
            'written_off_on' => '2026-01-05',
            'written_off_note' => 'Rusak total',
        ])->assertOk();
        $this->postJson("/api/assets/{$asset->id}/unwrite-off")->assertOk();
        $log = $this->latestLogFor($asset);
        $this->assertSame(MutationEventType::UnwriteOff, $log->event_type);

        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $fresh = $asset->fresh();
        $this->assertTrue($fresh->is_written_off);
        $this->assertSame('2026-01-05', $fresh->written_off_on->toDateString());
        $this->assertSame('Rusak total', $fresh->written_off_note);
    }

    public function test_soft_delete_revert_restores_the_asset(): void
    {
        $asset = $this->existingAsset('001');
        Sanctum::actingAs($this->operator());

        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(204);
        $log = $this->latestLogFor($asset);
        $this->assertSame(MutationEventType::SoftDelete, $log->event_type);
        $this->assertTrue($asset->fresh()->trashed());

        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $this->assertFalse($asset->fresh()->trashed());
    }

    public function test_restore_revert_re_trashes_the_asset(): void
    {
        $asset = $this->existingAsset('001');
        Sanctum::actingAs($this->operator());

        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(204);
        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();
        $log = $this->latestLogFor($asset);
        $this->assertSame(MutationEventType::Restore, $log->event_type);
        $this->assertFalse($asset->fresh()->trashed());

        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $this->assertTrue($asset->fresh()->trashed());
    }

    public function test_batch_delete_revert_restores_every_asset(): void
    {
        $asset1 = $this->existingAsset('001');
        $asset2 = $this->existingAsset('002');
        Sanctum::actingAs($this->operator());

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$asset1->id, $asset2->id]])->assertOk();
        $log1 = $this->latestLogFor($asset1);
        $this->assertSame(MutationEventType::BatchDelete, $log1->event_type);

        $this->postJson("/api/mutations/{$log1->id}/revert")->assertOk();

        $this->assertFalse($asset1->fresh()->trashed());
        $this->assertFalse($asset2->fresh()->trashed());
    }

    /* ================================================================== unsupported mutations */

    public function test_create_mutation_cannot_be_reverted(): void
    {
        $payload = $this->validCreatePayload();
        Sanctum::actingAs($this->operator());

        $created = $this->postJson('/api/assets', $payload)->assertStatus(201)->json('data');
        $asset = Asset::find($created['id']);
        $log = $this->latestLogFor($asset);
        $this->assertSame(MutationEventType::Create, $log->event_type);

        $countBefore = Asset::withTrashed()->count();

        $this->postJson("/api/mutations/{$log->id}/revert")->assertStatus(409);

        $this->assertSame($countBefore, Asset::withTrashed()->count());
        $this->assertFalse($asset->fresh()->trashed());
    }

    public function test_legacy_mutation_without_snapshots_cannot_be_reverted(): void
    {
        $asset = $this->existingAsset('001');
        $roomA = $this->room('ZL', ['name' => 'Ruang Lama']);

        // Hand-built to match a REAL pre-Tahap-5.8.8 row: event_type backfilled to
        // MOVE_ROOM, but no before_snapshot/after_snapshot JSON exists for it.
        $legacy = MutationLog::create([
            'asset_id' => $asset->id,
            'type' => 'pindah_ruangan',
            'mutation_date' => now()->toDateString(),
            'from_location_code' => 'ZL',
            'to_location_code' => $asset->location_code,
            'from_room_id' => $roomA->id,
            'to_room_id' => $asset->room_id,
            'from_room_label' => 'Ruang Lama',
            'to_room_label' => null,
            'performed_by' => $this->operator()->id,
            'event_type' => MutationEventType::MoveRoom,
        ]);

        Sanctum::actingAs($this->operator());
        $this->postJson("/api/mutations/{$legacy->id}/revert")
            ->assertStatus(409);

        $this->assertDatabaseMissing('mutation_logs', ['reverted_mutation_id' => $legacy->id]);
    }

    /* ================================================================== database safety */

    public function test_revert_does_not_touch_unrelated_assets(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        $unrelated = $this->existingAsset('002', overrides: ['condition' => 'baik']);
        Sanctum::actingAs($this->operator());

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $log = $this->latestLogFor($asset);

        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $this->assertSame('baik', $unrelated->fresh()->condition->value);
    }

    public function test_revert_creates_exactly_one_new_mutation_log_for_an_individual_revert(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        Sanctum::actingAs($this->operator());

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $before = MutationLog::count();

        $log = $this->latestLogFor($asset);
        $this->postJson("/api/mutations/{$log->id}/revert")->assertOk();

        $this->assertSame($before + 1, MutationLog::count());
    }

    /* ================================================================== mass assignment (Tahap 6.6) */

    /**
     * Regression test for the Stage 6.6 Pass 1 audit's live probe: a crafted
     * body with `before_snapshot`/`after_snapshot`/`reverted_mutation_id`/
     * `performed_by` was confirmed to have zero effect, because
     * `MutationRevertController::revert()` never reads the request body at
     * all — snapshots come exclusively from the DB-stored `MutationLog` row
     * and a freshly-computed live asset snapshot, and the actor comes
     * exclusively from `$request->user()` (the session), never from input.
     * Locks in that property so it can't silently regress.
     */
    public function test_revert_ignores_malicious_body_fields_entirely(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => 'baik']);
        $operator = $this->operator();
        $attacker = $this->operator();
        Sanctum::actingAs($operator);

        $this->putJson("/api/assets/{$asset->id}", ['condition' => 'kurang_baik'])->assertOk();
        $original = $this->latestLogFor($asset);

        Sanctum::actingAs($attacker);
        $response = $this->postJson("/api/mutations/{$original->id}/revert", [
            'before_snapshot' => ['condition' => 'rusak_berat'],
            'after_snapshot' => ['condition' => 'kurang_baik'],
            'reverted_mutation_id' => 999999,
            'performed_by' => $operator->id,
            'event_type' => 'CREATE',
            'asset_id' => 999999,
        ])->assertOk();

        // The restored value came from the REAL stored before_snapshot ('baik'),
        // never the attacker-supplied 'rusak_berat'.
        $this->assertSame('baik', $asset->fresh()->condition->value);

        $revertLog = MutationLog::query()->where('reverted_mutation_id', $original->id)->first();
        $this->assertNotNull($revertLog);
        // Correctly linked to the REAL original mutation, never the attacker's
        // fake id (999999).
        $this->assertSame($original->id, $revertLog->reverted_mutation_id);
        // The actor is whoever the SESSION says it is (the attacker, since they
        // made the call) — never spoofable to a different user via the body.
        $this->assertSame($attacker->id, $revertLog->performed_by);
        $this->assertNotSame($operator->id, $revertLog->performed_by);
        // event_type is always REVERT for an individual revert — never the
        // attacker-supplied 'CREATE'.
        $this->assertSame(MutationEventType::Revert, $revertLog->event_type);
        // asset_id is always the mutation's REAL asset — never the attacker's
        // fake 999999.
        $this->assertSame($asset->id, $revertLog->asset_id);
        $response->assertJsonPath('new_mutations.0.reverted_mutation_id', $original->id);
    }
}
