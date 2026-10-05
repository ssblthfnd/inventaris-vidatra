<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Import\ImportManager;
use App\Import\Reporting\ImportReporter;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportRoomMappingResolution;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\Room;
use App\Models\RoomAlias;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.9 R9.4 Decision Phase D1.
 *
 *   R9.4-01  a successful room mapping resolves `room_unmapped` (and only that):
 *            the row becomes `valid` unless another issue keeps it `warning`.
 *   R9.4-02  `room_match_method` says where matched_room_id came from:
 *            exact_name / alias (automatic), manual / manual_alias (user), none.
 *   R9.4-11  who/when: one `import_room_mapping_resolutions` record per
 *            successful mapping action; `imported_by` (with write-once
 *            `imported_at`) and per-row `promoted_by` for promotion.
 */
class Stage694RoomMappingAttributionTest extends TestCase
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

    private function rowFor(string $locationCode, string $sequenceNo, string $roomRawValue, array $overrides = []): array
    {
        return $this->validImportRowCells(array_merge([
            'B' => $locationCode, 'C' => self::CATEGORY, 'D' => self::SUBCATEGORY, 'E' => $sequenceNo,
            'O' => $roomRawValue,
        ], $overrides));
    }

    /** Stages through the real HTTP upload as `$actor` and returns the batch id. */
    private function upload(User $actor, array $rows): int
    {
        $this->ensureMasterData();
        Sanctum::actingAs($actor);

        return $this->postJson('/api/imports', ['file' => $this->makeImportUpload(self::CATEGORY, $rows)])
            ->assertCreated()
            ->json('data.id');
    }

    private function resolve(int $batchId, string $locationCode, string $rawValue, int $roomId, bool $saveAsAlias = false): TestResponse
    {
        return $this->postJson("/api/imports/{$batchId}/room-mappings/resolve", [
            'location_code' => $locationCode,
            'raw_value' => $rawValue,
            'room_id' => $roomId,
            'save_as_alias' => $saveAsAlias,
        ]);
    }

    private function row(int $batchId, string $sequenceNo): ImportRow
    {
        return ImportRow::where('import_batch_id', $batchId)->where('sequence_no', $sequenceNo)->firstOrFail();
    }

    /** @return list<string> */
    private function codes(ImportRow $row): array
    {
        return array_column((array) $row->validation_messages, 'code');
    }

    /* ================================================================== A/B. status + method */

    public function test_import_only_mapping_turns_room_unmapped_row_valid_with_method_manual(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->upload($operator, [
            $this->rowFor('02', '101', 'LAB KOMP'),
            $this->rowFor('02', '102', 'Lab  komp'), // same normalized key
        ]);

        $before = $this->row($batchId, '101');
        $this->assertSame('warning', $before->validation_status);
        $this->assertSame('none', $before->room_match_method);
        $this->assertNull($before->matched_room_id);
        $this->assertSame(['room_unmapped'], $this->codes($before));
        $this->assertSame([0, 2], [ImportBatch::find($batchId)->valid_rows, ImportBatch::find($batchId)->warning_rows]);

        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)
            ->assertOk()
            ->assertJsonPath('data.room_match_method', 'manual')
            ->assertJsonPath('data.updated_rows', 2)
            ->assertJsonPath('data.rows_became_valid', 2)
            ->assertJsonPath('data.alias_created', false);

        foreach (['101', '102'] as $seq) {
            $row = $this->row($batchId, $seq);
            $this->assertSame('valid', $row->validation_status);
            $this->assertSame('manual', $row->room_match_method);
            $this->assertSame($room->id, $row->matched_room_id);
            $this->assertSame([], $row->validation_messages);
        }
        $this->assertSame(0, RoomAlias::count());

        // batch counters follow the rows; promotable (and so status) unchanged
        $batch = ImportBatch::find($batchId);
        $this->assertSame([2, 0, 0, 'validated'], [$batch->valid_rows, $batch->warning_rows, $batch->error_rows, $batch->status]);

        // the group is gone from the unmapped list
        $this->getJson("/api/imports/{$batchId}/room-mappings")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_permanent_alias_mapping_sets_method_manual_alias(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->upload($operator, [$this->rowFor('02', '111', 'LAB KOMP')]);

        $this->resolve($batchId, '02', 'LAB KOMP', $room->id, saveAsAlias: true)
            ->assertOk()
            ->assertJsonPath('data.room_match_method', 'manual_alias')
            ->assertJsonPath('data.alias_created', true);

        $row = $this->row($batchId, '111');
        $this->assertSame('valid', $row->validation_status);
        $this->assertSame('manual_alias', $row->room_match_method);
        $this->assertSame($room->id, $row->matched_room_id);

        $alias = RoomAlias::where('location_code', '02')->where('match_key', 'LAB KOMP')->firstOrFail();
        $resolution = ImportRoomMappingResolution::findOrFail($row->room_mapping_resolution_id);
        $this->assertSame('manual_alias', $resolution->method);
        $this->assertSame($alias->id, $resolution->room_alias_id);
        $this->assertTrue($resolution->alias_created);
    }

    /** An alias added after staging (pointing at the chosen room) is reused, not duplicated — still a manual pick backed by a permanent alias. */
    public function test_mapping_backed_by_an_already_existing_alias_is_manual_alias_without_creating_one(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->upload($operator, [$this->rowFor('02', '121', 'LAB KOMP')]);
        $alias = RoomAlias::factory()->create([
            'location_code' => '02', 'raw_value' => 'LAB KOMP', 'match_key' => 'LAB KOMP',
            'room_id' => $room->id, 'source' => 'manual',
        ]);

        Sanctum::actingAs($operator);
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id, saveAsAlias: true)
            ->assertOk()
            ->assertJsonPath('data.room_match_method', 'manual_alias')
            ->assertJsonPath('data.alias_created', false)
            ->assertJsonPath('data.alias_already_existed', true);

        $row = $this->row($batchId, '121');
        $this->assertSame('manual_alias', $row->room_match_method);
        $resolution = $row->roomMappingResolution;
        $this->assertSame($alias->id, $resolution->room_alias_id);
        $this->assertFalse($resolution->alias_created);
        $this->assertSame(1, RoomAlias::count());
    }

    /* ================================================================== C. unrelated issues preserved */

    public function test_an_unrelated_warning_is_kept_and_the_row_stays_warning(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->upload($operator, [
            $this->rowFor('02', '131', 'LAB KOMP', ['L' => '']), // no condition flag -> condition warning
            $this->rowFor('02', '132', 'LAB KOMP'),
        ]);

        $before = $this->row($batchId, '131');
        $otherMessages = array_values(array_filter($before->validation_messages, fn ($m) => $m['code'] !== 'room_unmapped'));
        $this->assertNotEmpty($otherMessages, 'fixture must carry a second, independent warning');
        $this->assertContains('room_unmapped', $this->codes($before));

        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)
            ->assertOk()
            ->assertJsonPath('data.updated_rows', 2)
            ->assertJsonPath('data.rows_became_valid', 1);

        $after = $this->row($batchId, '131');
        $this->assertSame('warning', $after->validation_status);
        $this->assertSame('manual', $after->room_match_method);
        $this->assertSame($room->id, $after->matched_room_id);
        $this->assertSame($otherMessages, $after->validation_messages); // only room_unmapped removed, rest verbatim

        $this->assertSame('valid', $this->row($batchId, '132')->validation_status);
        $batch = ImportBatch::find($batchId);
        $this->assertSame([1, 1], [$batch->valid_rows, $batch->warning_rows]);
    }

    public function test_error_rows_sharing_the_unmapped_value_are_left_untouched(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        // same identity twice: the second row is a duplicate_in_batch ERROR that also has room_unmapped
        $batchId = $this->upload($operator, [
            $this->rowFor('02', '141', 'LAB KOMP'),
            $this->rowFor('02', '141', 'LAB KOMP'),
        ]);
        $errorRow = ImportRow::where('import_batch_id', $batchId)->where('validation_status', 'error')->firstOrFail();
        $this->assertContains('room_unmapped', $this->codes($errorRow));
        $errorBefore = $errorRow->only(['validation_status', 'validation_messages', 'matched_room_id', 'room_match_method', 'room_mapping_resolution_id']);

        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertOk()->assertJsonPath('data.updated_rows', 1);

        $this->assertSame($errorBefore, $errorRow->fresh()->only(array_keys($errorBefore)));
        $this->assertSame(1, ImportBatch::find($batchId)->error_rows);
    }

    /** `promotion_failed` is history, not a validation result: it is kept, and never turns a retryable row into `error`. */
    public function test_promotion_failure_history_is_kept_and_the_row_stays_promotable(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $first = $this->upload($operator, [$this->rowFor('02', '151', 'LAB KOMP')]);
        $second = $this->upload($operator, [$this->rowFor('02', '151', 'LAB KOMP')]); // same identity, staged before either is promoted

        Sanctum::actingAs($operator);
        $this->postJson("/api/imports/{$second}/promote")->assertOk()->assertJsonPath('promotion.promoted', 1);
        $this->postJson("/api/imports/{$first}/promote")->assertOk()->assertJsonPath('promotion.failed', 1);
        $failed = $this->row($first, '151');
        $this->assertSame('warning', $failed->validation_status);
        $this->assertContains('promotion_failed', $this->codes($failed));

        $this->resolve($first, '02', 'LAB KOMP', $room->id)->assertOk()->assertJsonPath('data.updated_rows', 1);

        $after = $failed->fresh();
        $this->assertSame('valid', $after->validation_status);
        $this->assertSame(['promotion_failed'], $this->codes($after));
        $this->assertNull($after->promoted_asset_id);
    }

    /* ================================================================== D. automatic matches untouched */

    public function test_automatic_exact_name_and_alias_matches_keep_their_methods(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $exact = $this->roomIn('02', 'Ruang Guru');
        $aliased = $this->roomIn('02', 'Ruang Tata Usaha');
        $target = $this->roomIn('02', 'Laboratorium Komputer');
        RoomAlias::factory()->create([
            'location_code' => '02', 'raw_value' => 'TU', 'match_key' => 'TU',
            'room_id' => $aliased->id, 'source' => 'manual',
        ]);
        $batchId = $this->upload($operator, [
            $this->rowFor('02', '161', 'Ruang Guru'),
            $this->rowFor('02', '162', 'TU'),
            $this->rowFor('02', '163', 'LAB KOMP'),
        ]);

        $this->assertSame('exact_name', $this->row($batchId, '161')->room_match_method);
        $this->assertSame('alias', $this->row($batchId, '162')->room_match_method);

        $this->resolve($batchId, '02', 'LAB KOMP', $target->id, saveAsAlias: true)->assertOk()->assertJsonPath('data.updated_rows', 1);

        $exactRow = $this->row($batchId, '161');
        $aliasRow = $this->row($batchId, '162');
        $this->assertSame(['exact_name', $exact->id, null], [$exactRow->room_match_method, $exactRow->matched_room_id, $exactRow->room_mapping_resolution_id]);
        $this->assertSame(['alias', $aliased->id, null], [$aliasRow->room_match_method, $aliasRow->matched_room_id, $aliasRow->room_mapping_resolution_id]);
        $this->assertSame('manual_alias', $this->row($batchId, '163')->room_match_method);

        // and a LATER import hitting the alias saved above is an automatic `alias` match again
        $next = $this->upload($operator, [$this->rowFor('02', '164', 'lab komp')]);
        $later = $this->row($next, '164');
        $this->assertSame(['alias', $target->id, 'valid', null], [$later->room_match_method, $later->matched_room_id, $later->validation_status, $later->room_mapping_resolution_id]);

        // the report counts each method separately and stays consistent
        $totals = (new ImportReporter([$batchId]))->roomMethodTotals();
        $this->assertSame([1, 1, 0, 1, 0, 3], [$totals['exact_name'], $totals['alias'], $totals['manual'], $totals['manual_alias'], $totals['none'], $totals['total']]);
    }

    /* ================================================================== E. mapping attribution */

    public function test_successful_mapping_records_actor_and_timestamp(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->upload($operator, [
            $this->rowFor('02', '171', 'LAB KOMP'),
            $this->rowFor('02', '172', 'LAB KOMP'),
        ]);

        $resolver = $this->globalUser(UserRole::Admin);
        Carbon::setTestNow('2026-10-05 09:30:00');
        Sanctum::actingAs($resolver);
        $response = $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertOk()
            ->assertJsonPath('data.resolution.resolved_by.id', $resolver->id)
            ->assertJsonPath('data.resolution.resolved_by.name', $resolver->name);
        Carbon::setTestNow();

        $resolution = ImportRoomMappingResolution::sole();
        $this->assertSame($response->json('data.resolution.id'), $resolution->id);
        $this->assertSame($batchId, $resolution->import_batch_id);
        $this->assertSame(['02', 'LAB KOMP', 'LAB KOMP', $room->id, 'manual', null, false, 2, $resolver->id], [
            $resolution->location_code, $resolution->raw_value, $resolution->match_key, $resolution->room_id,
            $resolution->method, $resolution->room_alias_id, $resolution->alias_created, $resolution->affected_rows,
            $resolution->resolved_by,
        ]);
        $this->assertSame('2026-10-05 09:30:00', $resolution->resolved_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $resolution->importRows()->count());

        // the uploader is not the resolver — attribution names who actually mapped it
        $this->assertNotSame($operator->id, $resolution->resolved_by);

        // visible on the rows preview
        Sanctum::actingAs($resolver);
        $this->getJson("/api/imports/{$batchId}/rows")->assertOk()
            ->assertJsonPath('data.0.room_match_method', 'manual')
            ->assertJsonPath('data.0.room_mapping_resolution.method', 'manual')
            ->assertJsonPath('data.0.room_mapping_resolution.resolved_by.name', $resolver->name);
    }

    public function test_groups_resolved_by_different_users_are_attributed_separately(): void
    {
        $uploader = $this->unitAdmin('02');
        $colleague = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']);
        $roomA = $this->roomIn('02', 'Laboratorium Komputer');
        $roomB = $this->roomIn('02', 'Perpustakaan');
        $batchId = $this->upload($uploader, [
            $this->rowFor('02', '181', 'LAB KOMP'),
            $this->rowFor('02', '182', 'PERPUS'),
        ]);

        Sanctum::actingAs($uploader);
        $this->resolve($batchId, '02', 'LAB KOMP', $roomA->id)->assertOk();
        Sanctum::actingAs($colleague);
        $this->resolve($batchId, '02', 'PERPUS', $roomB->id, saveAsAlias: true)->assertOk();

        $this->assertSame(2, ImportRoomMappingResolution::where('import_batch_id', $batchId)->count());
        $a = $this->row($batchId, '181')->roomMappingResolution;
        $b = $this->row($batchId, '182')->roomMappingResolution;
        $this->assertSame([$uploader->id, 'manual'], [$a->resolved_by, $a->method]);
        $this->assertSame([$colleague->id, 'manual_alias'], [$b->resolved_by, $b->method]);
    }

    /* ================================================================== F. no attribution without a resolution */

    public function test_denied_or_failed_mapping_creates_no_attribution_and_changes_no_row(): void
    {
        $owner = $this->unitAdmin('02');
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $inactive = $this->roomIn('02', 'Gudang Lama');
        $inactive->update(['is_active' => false]);
        $foreignRoom = $this->roomIn('03', 'Laboratorium Komputer');
        $otherRoom = $this->roomIn('02', 'Perpustakaan');
        $batchId = $this->upload($owner, [$this->rowFor('02', '191', 'LAB KOMP')]);
        $snapshot = fn () => $this->row($batchId, '191')->only([
            'validation_status', 'validation_messages', 'matched_room_id', 'room_match_method', 'room_mapping_resolution_id',
        ]);
        $before = $snapshot();

        // out-of-scope actor (403)
        Sanctum::actingAs($this->unitAdmin('03'));
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertForbidden();

        Sanctum::actingAs($owner);
        // business-rule failures (422): inactive room, room in another location
        $this->resolve($batchId, '02', 'LAB KOMP', $inactive->id)->assertStatus(422);
        $this->resolve($batchId, '02', 'LAB KOMP', $foreignRoom->id)->assertStatus(422);

        // conflicting permanent alias (422) — alias added after staging, pointing elsewhere
        RoomAlias::factory()->create([
            'location_code' => '02', 'raw_value' => 'LAB KOMP', 'match_key' => 'LAB KOMP',
            'room_id' => $otherRoom->id, 'source' => 'manual',
        ]);
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id, saveAsAlias: true)->assertStatus(422);

        // alias permission denied (403)
        Gate::define('roomAliases.resolve', fn () => false);
        $this->resolve($batchId, '02', 'LAB KOMP', $otherRoom->id, saveAsAlias: true)->assertForbidden();

        // viewer never reaches the endpoint (403)
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertForbidden();

        $this->assertSame(0, ImportRoomMappingResolution::count());
        $this->assertSame($before, $snapshot());
    }

    public function test_resolving_an_already_resolved_group_records_nothing_new(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->upload($operator, [$this->rowFor('02', '201', 'LAB KOMP')]);

        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertOk();
        $firstId = $this->row($batchId, '201')->room_mapping_resolution_id;

        // a stale UI resubmitting: nothing left to map
        Sanctum::actingAs($this->globalUser(UserRole::Admin));
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertOk()
            ->assertJsonPath('data.updated_rows', 0)
            ->assertJsonPath('data.resolution', null);

        $this->assertSame(1, ImportRoomMappingResolution::count());
        $this->assertSame($firstId, $this->row($batchId, '201')->room_mapping_resolution_id);
    }

    /* ================================================================== G/H/I. promotion attribution */

    public function test_first_successful_promotion_records_actor_and_timestamp(): void
    {
        $uploader = $this->globalUser(UserRole::Operator);
        $batchId = $this->upload($uploader, [$this->rowFor('02', '211', 'LAB KOMP'), $this->rowFor('02', '212', 'LAB KOMP')]);

        $promoter = $this->globalUser(UserRole::Admin);
        Carbon::setTestNow('2026-10-05 10:00:00');
        Sanctum::actingAs($promoter);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 2)
            ->assertJsonPath('data.imported_by.id', $promoter->id)
            ->assertJsonPath('data.imported_by.name', $promoter->name);
        Carbon::setTestNow();

        $batch = ImportBatch::find($batchId);
        $this->assertSame($promoter->id, $batch->imported_by);
        $this->assertSame('2026-10-05 10:00:00', $batch->imported_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, ImportRow::where('import_batch_id', $batchId)->where('promoted_by', $promoter->id)->count());

        $this->getJson("/api/imports/{$batchId}")->assertOk()->assertJsonPath('data.imported_by.name', $promoter->name);
        $this->getJson("/api/imports/{$batchId}/rows")->assertOk()->assertJsonPath('data.0.promoted_by.id', $promoter->id);
    }

    public function test_repeated_promotion_keeps_the_first_actor_and_timestamp(): void
    {
        $first = $this->globalUser(UserRole::Operator);
        $batchId = $this->upload($first, [$this->rowFor('02', '221', 'LAB KOMP')]);

        Sanctum::actingAs($first);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();
        $before = DB::table('import_batches')->where('id', $batchId)->first(['imported_at', 'imported_by']);
        $rowBefore = DB::table('import_rows')->where('import_batch_id', $batchId)->first(['promoted_at', 'promoted_by']);

        $this->travel(3)->hours();
        Sanctum::actingAs($this->globalUser(UserRole::Admin));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.skipped_already', 1)
            ->assertJsonPath('data.imported_by.id', $first->id);

        $this->assertEquals($before, DB::table('import_batches')->where('id', $batchId)->first(['imported_at', 'imported_by']));
        $this->assertEquals($rowBefore, DB::table('import_rows')->where('import_batch_id', $batchId)->first(['promoted_at', 'promoted_by']));
    }

    /** A later promote that imports MORE rows keeps the batch's first actor, and attributes only its own rows. */
    public function test_later_promotion_of_remaining_rows_is_attributed_per_row_only(): void
    {
        $room = $this->roomIn('02', 'Gudang Uji');
        $first = $this->globalUser(UserRole::Operator);
        $batchId = $this->upload($first, [$this->rowFor('02', '231', 'LAB KOMP'), $this->rowFor('02', '232', 'Gudang Uji')]);
        $room->update(['is_active' => false]); // row 232 fails on the first promote

        Sanctum::actingAs($first);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 1)->assertJsonPath('promotion.failed', 1);
        $this->assertNull($this->row($batchId, '232')->promoted_by);

        $room->update(['is_active' => true]);
        $second = $this->globalUser(UserRole::Admin);
        Sanctum::actingAs($second);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 1);

        $this->assertSame($first->id, ImportBatch::find($batchId)->imported_by);
        $this->assertSame($first->id, $this->row($batchId, '231')->promoted_by);
        $this->assertSame($second->id, $this->row($batchId, '232')->promoted_by);
    }

    public function test_failed_or_denied_promotion_claims_no_attribution(): void
    {
        $room = $this->roomIn('02', 'Gudang Uji');
        $owner = $this->unitAdmin('02');
        $batchId = $this->upload($owner, [$this->rowFor('02', '241', 'Gudang Uji')]);

        // denied: another unit's admin (403) — nothing recorded
        Sanctum::actingAs($this->unitAdmin('03'));
        $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();

        // failed: every row fails (inactive room) — nothing recorded
        $room->update(['is_active' => false]);
        Sanctum::actingAs($owner);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 0)
            ->assertJsonPath('promotion.failed', 1)
            ->assertJsonPath('data.imported_by', null)
            ->assertJsonPath('data.imported_at', null);
        $this->assertSame([null, null], [ImportBatch::find($batchId)->imported_at, ImportBatch::find($batchId)->imported_by]);
        $this->assertNull($this->row($batchId, '241')->promoted_by);

        // the first promotion that actually succeeds is the one attributed
        $room->update(['is_active' => true]);
        $colleague = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']);
        Sanctum::actingAs($colleague);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 1);
        $batch = ImportBatch::find($batchId);
        $this->assertSame($colleague->id, $batch->imported_by);
        $this->assertNotNull($batch->imported_at);
    }

    public function test_cli_promotion_sets_imported_at_without_an_actor(): void
    {
        $batchId = $this->upload($this->globalUser(UserRole::Operator), [$this->rowFor('02', '251', 'LAB KOMP')]);

        app(ImportManager::class)->promoteBatch($batchId); // inventory:promote path, no user

        $batch = ImportBatch::find($batchId);
        $this->assertNotNull($batch->imported_at);
        $this->assertNull($batch->imported_by);
        $this->assertNull($this->row($batchId, '251')->promoted_by);
    }

    /* ================================================================== J. historical data */

    public function test_historical_rows_and_batches_stay_readable_and_unchanged(): void
    {
        $this->ensureMasterData();
        $admin = $this->globalUser(UserRole::Admin);
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        // shape left by R9.2-era code: resolved manually but stored as `alias`, still
        // `warning` with room_unmapped, batch promoted with imported_at but no actor
        $batch = ImportBatch::factory()->create([
            'category_code' => self::CATEGORY, 'status' => 'validated', 'total_rows' => 1, 'warning_rows' => 1,
            'imported_at' => null,
        ]);
        $row = ImportRow::factory()->create([
            'import_batch_id' => $batch->id, 'row_number' => 1, 'location_code' => '02',
            'category_code' => self::CATEGORY, 'subcategory_code' => self::SUBCATEGORY,
            'sequence_no' => '261', 'asset_year' => 2020, 'room_raw_value' => 'LAB KOMP',
            'matched_room_id' => $room->id, 'room_match_method' => 'alias', 'validation_status' => 'warning',
            'validation_messages' => [['code' => 'room_unmapped', 'severity' => 'warning', 'field' => 'room_id', 'message' => 'legacy']],
        ]);
        DB::table('import_batches')->where('id', $batch->id)->update(['imported_at' => '2026-09-09 01:49:44']);

        Sanctum::actingAs($admin);
        $this->getJson("/api/imports/{$batch->id}")->assertOk()
            ->assertJsonPath('data.imported_by', null)
            ->assertJsonPath('data.imported_at', Carbon::parse('2026-09-09 01:49:44')->toIso8601String());
        $this->getJson("/api/imports/{$batch->id}/rows")->assertOk()
            ->assertJsonPath('data.0.room_match_method', 'alias')
            ->assertJsonPath('data.0.room_mapping_resolution', null)
            ->assertJsonPath('data.0.promoted_by', null)
            ->assertJsonPath('data.0.validation_status', 'warning');
        $checks = collect($this->getJson("/api/imports/{$batch->id}/report")->assertOk()->json('data.consistency'))->keyBy('check');
        $this->assertTrue($checks['room methods: exact_name + alias + manual + manual_alias + none == total rows']['ok']);

        // nothing about the legacy row was rewritten
        $fresh = $row->fresh();
        $this->assertSame(['alias', 'warning', null], [$fresh->room_match_method, $fresh->validation_status, $fresh->room_mapping_resolution_id]);
    }

    public function test_room_match_method_column_accepts_exactly_the_five_documented_values(): void
    {
        $batch = ImportBatch::factory()->create();
        foreach (['exact_name', 'alias', 'manual', 'manual_alias', 'none'] as $i => $method) {
            ImportRow::factory()->create(['import_batch_id' => $batch->id, 'row_number' => $i + 1, 'room_match_method' => $method]);
        }
        $this->assertSame(5, ImportRow::where('import_batch_id', $batch->id)->count());

        $this->expectException(QueryException::class);
        ImportRow::factory()->create(['import_batch_id' => $batch->id, 'row_number' => 99, 'room_match_method' => 'fuzzy']);
    }

    /* ================================================================== K. authorization unchanged */

    public function test_authorization_boundaries_are_unchanged(): void
    {
        $owner = $this->unitAdmin('02');
        $colleague = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']);
        $foreign = $this->unitAdmin('03');
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->upload($owner, [$this->rowFor('02', '271', 'LAB KOMP'), $this->rowFor('02', '272', 'PERPUS')]);

        // reads are location-scoped since R9.4-07 (D2): the colocated colleague may view
        // the batch (ownership is audit metadata only); the foreign unit may not (404)
        Sanctum::actingAs($colleague);
        $this->getJson("/api/imports/{$batchId}")->assertOk();
        $this->getJson("/api/imports/{$batchId}/rows")->assertOk();
        Sanctum::actingAs($foreign);
        $this->getJson("/api/imports/{$batchId}")->assertNotFound();

        // location-scoped mapping: foreign unit cannot see the batch's groups (404 since
        // R9.4-07 D2, was 200 []) and is refused; colleague may map
        $this->getJson("/api/imports/{$batchId}/room-mappings")->assertNotFound();
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertForbidden();
        Sanctum::actingAs($colleague);
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertOk();
        $this->assertSame($colleague->id, $this->row($batchId, '271')->roomMappingResolution->resolved_by);

        // viewer: no import access at all
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));
        $this->getJson("/api/imports/{$batchId}/room-mappings")->assertForbidden();
        $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();

        // location-scoped promotion: foreign refused, colleague allowed
        Sanctum::actingAs($foreign);
        $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();
        Sanctum::actingAs($colleague);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 2);
        $this->assertSame($colleague->id, ImportBatch::find($batchId)->imported_by);

        // global roles unaffected
        foreach ([UserRole::Admin, UserRole::SuperAdmin, UserRole::Operator] as $role) {
            Sanctum::actingAs($this->globalUser($role));
            $this->getJson("/api/imports/{$batchId}")->assertOk();
        }
    }
}
