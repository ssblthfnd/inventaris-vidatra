<?php

namespace Tests\Feature\Api;

use App\Enums\MutationEventType;
use App\Enums\UserRole;
use App\Import\ImportManager;
use App\Import\Reporting\ImportReporter;
use App\Models\Asset;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.9 R9.4-B0 — backend import integrity fixes.
 *
 *   R9.4-08  promote scope: ALL located rows of the batch must be in a scoped
 *            actor's scope (previously only rows still awaiting promotion, so a
 *            fully-promoted foreign batch passed vacuously).
 *   R9.4-09  `import_batches.imported_at` is written once, on first import.
 *   R9.4-19  promotion never assigns an asset to a room deactivated since staging.
 *   R9.4-12  ImportReporter consistency checks compare the right populations.
 */
class Stage694ImportIntegrityTest extends TestCase
{
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    private const CATEGORY = '02';

    private const SUBCATEGORY = '001';

    /* ================================================================== fixtures */

    private function ensureLocation(string $code): Location
    {
        return Location::query()->firstOrCreate(['code' => $code], ['name' => "Lokasi {$code}", 'is_active' => true]);
    }

    private function ensureMasterData(): void
    {
        foreach (['01', '02', '03', '04'] as $code) {
            $this->ensureLocation($code);
        }
        Category::query()->firstOrCreate(['code' => self::CATEGORY], ['name' => 'Kategori 02', 'is_active' => true]);
        Subcategory::query()->firstOrCreate(
            ['category_code' => self::CATEGORY, 'code' => self::SUBCATEGORY],
            ['name' => 'Sub 001', 'is_active' => true],
        );
    }

    private function globalUser(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function unitAdmin(string $locationCode): User
    {
        $this->ensureLocation($locationCode);

        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    private function roomIn(string $locationCode, string $name): Room
    {
        $this->ensureLocation($locationCode);

        return Room::factory()->create(['location_code' => $locationCode, 'name' => $name]);
    }

    private function rowFor(string $locationCode, string $sequenceNo, array $overrides = []): array
    {
        return $this->validImportRowCells(array_merge([
            'B' => $locationCode, 'C' => self::CATEGORY, 'D' => self::SUBCATEGORY, 'E' => $sequenceNo,
        ], $overrides));
    }

    /** Stages a batch through the real pipeline (no actor = global CLI path) and returns its id. */
    private function stage(array $rows, ?User $uploader = null): int
    {
        $this->ensureMasterData();
        $upload = $this->makeImportUpload(self::CATEGORY, $rows);

        return app(ImportManager::class)->stageFile($upload->getPathname(), $uploader?->id)['batch_id'];
    }

    private function promote(int $batchId)
    {
        return $this->postJson("/api/imports/{$batchId}/promote");
    }

    /** @return array<string, array{check:string, ok:bool, detail:string}> */
    private function checks(int $batchId): array
    {
        return collect((new ImportReporter([$batchId]))->consistencyCheck())->keyBy('check')->all();
    }

    private const ROOM_CHECK = 'promoted assets keep the room their staged row had at promotion';

    private const CREATE_LOG_CHECK = 'no CREATE mutation_logs for assets imported by this batch';

    /* ================================================================== R9.4-08 promote scope */

    public function test_unit_admin_can_promote_an_own_location_batch(): void
    {
        $batchId = $this->stage([$this->rowFor('02', '701')]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 1);
        $this->assertSame(1, Asset::where('sequence_no', '701')->count());
    }

    public function test_unit_admin_cannot_promote_a_foreign_location_batch(): void
    {
        $batchId = $this->stage([$this->rowFor('03', '702')]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->promote($batchId)->assertForbidden();
        $this->assertSame(0, Asset::where('sequence_no', '702')->count());
    }

    /**
     * Documented behaviour for a mixed batch: a scoped actor is denied the WHOLE
     * batch while any row lies outside its scope — even when that foreign row is
     * an error row that would never be promoted. The 02 row is not promoted
     * either (no partial promotion on the actor's behalf). Same rule staging's
     * rejectOutOfScopeRows() applies.
     */
    public function test_unit_admin_is_denied_a_mixed_batch_even_when_the_foreign_row_is_an_error(): void
    {
        $batchId = $this->stage([
            $this->rowFor('02', '703'),
            $this->rowFor('03', '704', ['D' => '999']), // unknown subcategory -> error row
        ]);
        $this->assertSame('error', ImportRow::where('import_batch_id', $batchId)->where('sequence_no', '704')->value('validation_status'));
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->promote($batchId)->assertForbidden();
        $this->assertSame(0, Asset::whereIn('sequence_no', ['703', '704'])->count());
    }

    /** The R9.4 audit's runtime-confirmed bug: a fully-promoted foreign batch used to pass vacuously. */
    public function test_unit_admin_cannot_promote_a_fully_promoted_foreign_batch_and_nothing_about_it_leaks(): void
    {
        $uploader = $this->globalUser(UserRole::Operator);
        $uploader->update(['name' => 'Pengunggah Rahasia']);
        $batchId = $this->stage([$this->rowFor('03', '705')], $uploader);
        Sanctum::actingAs($uploader);
        $this->promote($batchId)->assertOk();
        $before = DB::table('import_batches')->where('id', $batchId)->first(['imported_at', 'updated_at', 'status']);
        $filename = DB::table('import_batches')->where('id', $batchId)->value('source_filename');

        $this->travel(10)->minutes();
        Sanctum::actingAs($this->unitAdmin('02'));
        $response = $this->promote($batchId)->assertForbidden();

        $response->assertDontSee($filename)->assertDontSee('Pengunggah Rahasia')->assertJsonMissingPath('data')->assertJsonMissingPath('promotion');
        $this->assertSame("Batch {$batchId} contains data outside your assigned location.", $response->json('message'));
        $after = DB::table('import_batches')->where('id', $batchId)->first(['imported_at', 'updated_at', 'status']);
        $this->assertEquals($before, $after, 'a denied promote must not touch the foreign batch at all');
    }

    /** Scope runs before the status check, so a foreign batch's status isn't revealed by a 422. */
    public function test_foreign_failed_batch_is_denied_without_revealing_its_status(): void
    {
        $batchId = $this->stage([$this->rowFor('03', '706', ['D' => '999'])]);
        $this->assertSame('failed', ImportBatch::find($batchId)->status);
        Sanctum::actingAs($this->unitAdmin('02'));

        $message = $this->promote($batchId)->assertForbidden()->json('message');

        $this->assertSame("Batch {$batchId} contains data outside your assigned location.", $message);
        $this->assertStringNotContainsString('failed', $message);
    }

    public function test_batch_without_any_located_row_is_denied_to_a_scoped_actor(): void
    {
        $actor = $this->unitAdmin('02');
        $batchId = $this->stage([$this->rowFor('02', '707', ['B' => ''])], $actor);
        $this->assertSame(0, ImportRow::where('import_batch_id', $batchId)->whereNotNull('location_code')->count());
        Sanctum::actingAs($actor);

        $this->promote($batchId)->assertForbidden();
    }

    public function test_global_actors_promotion_is_unchanged_including_mixed_and_already_promoted_batches(): void
    {
        $batchId = $this->stage([$this->rowFor('02', '708'), $this->rowFor('03', '709')]);

        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 2);

        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));
        $this->promote($batchId)->assertOk()
            ->assertJsonPath('promotion.promoted', 0)
            ->assertJsonPath('promotion.skipped_already', 2);
    }

    public function test_nonexistent_batch_is_404_for_scoped_and_global_actors(): void
    {
        $this->ensureMasterData();

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->promote(999999)->assertNotFound();

        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $this->promote(999999)->assertNotFound();
    }

    /* ================================================================== R9.4-09 imported_at */

    public function test_first_promote_sets_imported_at(): void
    {
        $batchId = $this->stage([$this->rowFor('02', '711')]);
        $this->assertNull(ImportBatch::find($batchId)->imported_at);
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->promote($batchId)->assertOk();

        $this->assertNotNull(ImportBatch::find($batchId)->imported_at);
    }

    public function test_idempotent_re_promote_leaves_imported_at_unchanged(): void
    {
        $batchId = $this->stage([$this->rowFor('02', '712')]);
        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $this->promote($batchId)->assertOk();
        $first = DB::table('import_batches')->where('id', $batchId)->value('imported_at');

        $this->travel(2)->hours();
        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.skipped_already', 1);

        $this->assertSame($first, DB::table('import_batches')->where('id', $batchId)->value('imported_at'));
    }

    /** A later promote that DOES import more rows still keeps the first import time. */
    public function test_re_promote_importing_more_rows_keeps_the_first_imported_at(): void
    {
        $room = $this->roomIn('02', 'Gudang Uji');
        $batchId = $this->stage([
            $this->rowFor('02', '713'),
            $this->rowFor('02', '714', ['O' => 'Gudang Uji']),
        ]);
        $room->update(['is_active' => false]); // row 714 fails on the first promote (R9.4-19)
        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 1)->assertJsonPath('promotion.failed', 1);
        $first = DB::table('import_batches')->where('id', $batchId)->value('imported_at');

        $this->travel(1)->day();
        $room->update(['is_active' => true]);
        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 1);

        $this->assertSame($first, DB::table('import_batches')->where('id', $batchId)->value('imported_at'));
        $this->assertSame('imported', ImportBatch::find($batchId)->status);
    }

    public function test_imported_at_stays_null_when_nothing_gets_imported(): void
    {
        $room = $this->roomIn('02', 'Gudang Uji');
        $batchId = $this->stage([$this->rowFor('02', '715', ['O' => 'Gudang Uji'])]);
        $room->update(['is_active' => false]);
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 0)->assertJsonPath('promotion.failed', 1);
        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 0);

        $this->assertNull(DB::table('import_batches')->where('id', $batchId)->value('imported_at'));
    }

    /* ================================================================== R9.4-19 inactive room */

    public function test_room_deactivated_after_matching_fails_the_row_instead_of_assigning_it(): void
    {
        $room = $this->roomIn('02', 'Gudang Uji');
        $batchId = $this->stage([$this->rowFor('02', '721', ['O' => 'Gudang Uji'])]);
        $row = ImportRow::where('import_batch_id', $batchId)->firstOrFail();
        $this->assertSame($room->id, $row->matched_room_id); // matched while active
        $room->update(['is_active' => false]);
        $roomsBefore = Room::count();
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $response = $this->promote($batchId)->assertOk();

        $response->assertJsonPath('promotion.promoted', 0)->assertJsonPath('promotion.failed', 1);
        $this->assertStringContainsString('no longer active', $response->json('promotion.errors.0.message'));
        $this->assertSame(0, Asset::where('sequence_no', '721')->count());
        $this->assertNull($row->fresh()->promoted_asset_id);
        $this->assertTrue(collect($row->fresh()->validation_messages)->contains(fn ($m) => $m['code'] === 'promotion_failed'));
        $this->assertFalse($room->fresh()->is_active, 'room must not be auto-reactivated');
        $this->assertSame($roomsBefore, Room::count(), 'no replacement room may be created');
        $this->assertSame($room->id, $row->fresh()->matched_room_id, 'the mapping itself is left untouched');
    }

    public function test_active_matched_room_still_promotes_normally(): void
    {
        $room = $this->roomIn('02', 'Gudang Uji');
        $batchId = $this->stage([$this->rowFor('02', '722', ['O' => 'Gudang Uji'])]);
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 1);

        $this->assertSame($room->id, Asset::where('sequence_no', '722')->value('room_id'));
    }

    public function test_cross_location_matched_room_is_still_rejected_as_before(): void
    {
        $this->roomIn('02', 'Gudang Uji');
        $foreignRoom = $this->roomIn('03', 'Ruang Lain');
        $batchId = $this->stage([$this->rowFor('02', '723', ['O' => 'Gudang Uji'])]);
        DB::table('import_rows')->where('import_batch_id', $batchId)->update(['matched_room_id' => $foreignRoom->id]);
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->promote($batchId)->assertOk()->assertJsonPath('promotion.promoted', 0)->assertJsonPath('promotion.failed', 1);

        $this->assertSame(0, Asset::where('sequence_no', '723')->count());
    }

    /* ================================================================== R9.4-12 consistency checks */

    /** Mixed batch: matched room, unmapped room (NULL), error row; plus unrelated mutation_logs elsewhere. */
    private function promotedMixedBatch(): int
    {
        $this->roomIn('02', 'Gudang Uji');
        $batchId = $this->stage([
            $this->rowFor('02', '731', ['O' => 'Gudang Uji']),   // matched -> promoted with room
            $this->rowFor('02', '732', ['O' => 'PLANET MARS']),  // unmapped -> promoted without room
            $this->rowFor('02', '733', ['D' => '999']),           // error -> never promoted
        ]);
        app(ImportManager::class)->promoteBatch($batchId);

        return $batchId;
    }

    public function test_checks_pass_for_a_mixed_batch_despite_unrelated_mutation_logs(): void
    {
        $batchId = $this->promotedMixedBatch();
        MutationLog::factory()->count(3)->create(); // unrelated assets' history
        MutationLog::factory()->create(['event_type' => MutationEventType::Create]); // a UI-created asset elsewhere

        $checks = $this->checks($batchId);

        foreach ($checks as $c) {
            $this->assertTrue($c['ok'], $c['check'].' — '.$c['detail']);
        }
        $this->assertStringContainsString('2 compared (of which 1 without room), 0 mismatched; 0 not verifiable', $checks[self::ROOM_CHECK]['detail']);
    }

    public function test_room_check_counts_a_later_room_move_as_not_verifiable_not_as_a_mismatch(): void
    {
        $batchId = $this->promotedMixedBatch();
        $other = $this->roomIn('02', 'Ruang Pindahan');
        $asset = Asset::where('sequence_no', '732')->firstOrFail();
        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $this->patchJson('/api/assets/batch', ['asset_ids' => [$asset->id], 'changes' => ['room_id' => $other->id]])->assertOk();

        $room = $this->checks($batchId)[self::ROOM_CHECK];

        $this->assertTrue($room['ok'], $room['detail']);
        $this->assertStringContainsString('1 compared (of which 0 without room), 0 mismatched; 1 not verifiable', $room['detail']);
    }

    /** The check is real, not vacuous: an untracked room change (no mutation_log) is caught. */
    public function test_room_check_fails_on_an_untracked_room_change(): void
    {
        $batchId = $this->promotedMixedBatch();
        $other = $this->roomIn('02', 'Ruang Pindahan');
        DB::table('assets')->where('sequence_no', '732')->update(['room_id' => $other->id]);

        $room = $this->checks($batchId)[self::ROOM_CHECK];

        $this->assertFalse($room['ok']);
        $this->assertStringContainsString('1 mismatched', $room['detail']);
    }

    public function test_create_log_check_fails_only_for_a_create_log_on_this_batchs_imported_asset(): void
    {
        $batchId = $this->promotedMixedBatch();
        $imported = Asset::where('sequence_no', '731')->firstOrFail();
        MutationLog::factory()->create(['asset_id' => $imported->id, 'event_type' => MutationEventType::Edit]); // later edit: fine

        $this->assertTrue($this->checks($batchId)[self::CREATE_LOG_CHECK]['ok']);

        MutationLog::factory()->create(['asset_id' => $imported->id, 'event_type' => MutationEventType::Create]);
        $check = $this->checks($batchId)[self::CREATE_LOG_CHECK];

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('== 1', $check['detail']);
    }

    public function test_report_endpoint_consistency_passes_for_a_real_batch_with_history_elsewhere(): void
    {
        $batchId = $this->promotedMixedBatch();
        MutationLog::factory()->count(2)->create();
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $response = $this->getJson("/api/imports/{$batchId}/report")->assertOk();

        foreach ($response->json('data.consistency') as $check) {
            $this->assertTrue($check['ok'], $check['check'].' — '.$check['detail']);
        }
    }
}
