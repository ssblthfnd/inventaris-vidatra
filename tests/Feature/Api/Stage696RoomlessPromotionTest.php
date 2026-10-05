<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Import\ImportManager;
use App\Import\Promotion\AssetPromoter;
use App\Models\Asset;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportRoomMappingResolution;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.9 R9.4-10 (D3) — promoting assets without a room stays allowed, but the
 * confirmation must say how many. `GET /api/imports/{batch}` and the promote
 * response carry `data.roomless_pending_count`: the assets the NEXT promotion
 * would create with no room ({@see AssetPromoter::roomlessPendingCount()}),
 * decided exactly like the promotion (pending, valid/warning, not a final-state
 * duplicate, `matched_room_id` NULL) and only for a batch the actor may reach.
 */
class Stage696RoomlessPromotionTest extends TestCase
{
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    private const CATEGORY = '02';

    private const SUBCATEGORY = '001';

    /* ================================================================== fixtures */

    private function ensureMasterData(): void
    {
        foreach (['01', '02', '03', '04'] as $code) {
            Location::query()->firstOrCreate(['code' => $code], ['name' => "Lokasi {$code}", 'is_active' => true]);
        }
        Category::query()->firstOrCreate(['code' => self::CATEGORY], ['name' => 'Kategori 02', 'is_active' => true]);
        Subcategory::query()->firstOrCreate(
            ['category_code' => self::CATEGORY, 'code' => self::SUBCATEGORY],
            ['name' => 'Sub 001', 'is_active' => true],
        );
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function unitAdmin(string $locationCode): User
    {
        $this->ensureMasterData();

        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    private function roomIn(string $locationCode, string $name): Room
    {
        $this->ensureMasterData();

        return Room::factory()->create(['location_code' => $locationCode, 'name' => $name]);
    }

    private function rowFor(?string $locationCode, string $sequenceNo, string $roomRawValue, array $overrides = []): array
    {
        return $this->validImportRowCells(array_merge([
            'B' => $locationCode ?? '', 'C' => self::CATEGORY, 'D' => self::SUBCATEGORY, 'E' => $sequenceNo,
            'O' => $roomRawValue,
        ], $overrides));
    }

    private function stage(array $rows, ?User $uploader = null): int
    {
        $this->ensureMasterData();
        $upload = $this->makeImportUpload(self::CATEGORY, $rows);

        return app(ImportManager::class)->stageFile($upload->getPathname(), $uploader?->id)['batch_id'];
    }

    private function roomlessCount(int $batchId): int
    {
        return $this->getJson("/api/imports/{$batchId}")->assertOk()->json('data.roomless_pending_count');
    }

    /* ================================================================== counts */

    public function test_batch_where_every_pending_row_has_a_room_reports_zero_and_promotes_as_before(): void
    {
        $room = $this->roomIn('02', 'Ruang Guru');
        $batchId = $this->stage([$this->rowFor('02', '101', 'Ruang Guru'), $this->rowFor('02', '102', 'Ruang Guru')]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame(0, $this->roomlessCount($batchId));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 2)
            ->assertJsonPath('data.roomless_pending_count', 0);
        $this->assertSame(2, Asset::where('room_id', $room->id)->count());
    }

    public function test_mixed_room_and_roomless_batch_counts_only_the_roomless_and_still_promotes(): void
    {
        $guru = $this->roomIn('02', 'Ruang Guru');
        $tu = $this->roomIn('02', 'Ruang TU');
        $batchId = $this->stage([
            $this->rowFor('02', '111', 'Ruang Guru'),
            $this->rowFor('02', '112', 'Ruang TU'),
            $this->rowFor('02', '113', 'LAB KOMP'),
            $this->rowFor('02', '114', 'LAB KOMP'),
            $this->rowFor('02', '115', 'GUDANG BELAKANG'),
        ]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame(3, $this->roomlessCount($batchId));

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 5)
            ->assertJsonPath('promotion.failed', 0)
            ->assertJsonPath('data.roomless_pending_count', 0);

        $this->assertSame(3, Asset::whereIn('sequence_no', ['113', '114', '115'])->whereNull('room_id')->count());
        $this->assertSame($guru->id, Asset::where('sequence_no', '111')->value('room_id'));
        $this->assertSame($tu->id, Asset::where('sequence_no', '112')->value('room_id'));
        // the roomless ones keep their raw value for later review
        $this->assertSame('LAB KOMP', Asset::where('sequence_no', '113')->value('room_raw_value'));
    }

    public function test_fully_roomless_batch_counts_every_pending_row_and_still_promotes(): void
    {
        $batchId = $this->stage([
            $this->rowFor('02', '121', 'LAB KOMP'),
            $this->rowFor('02', '122', 'PERPUS'),
            $this->rowFor('02', '123', ''), // blank room value: also roomless
        ]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame(3, $this->roomlessCount($batchId));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 3);
        $this->assertSame(3, Asset::whereNull('room_id')->count());
    }

    /**
     * Already-promoted rows never count. A batch is only left half-promoted when some
     * rows failed; here three rows fail on an inactive room, and the room is then
     * removed at DB level (FK ON DELETE SET NULL — there is no delete endpoint), which
     * leaves exactly "2 promoted + 3 pending without a room".
     */
    public function test_already_promoted_rows_are_excluded_from_the_count(): void
    {
        $room = $this->roomIn('02', 'Gudang Uji');
        $batchId = $this->stage([
            $this->rowFor('02', '131', 'LAB KOMP'),
            $this->rowFor('02', '132', 'LAB KOMP'),
            $this->rowFor('02', '133', 'Gudang Uji'),
            $this->rowFor('02', '134', 'Gudang Uji'),
            $this->rowFor('02', '135', 'Gudang Uji'),
        ]);
        $room->update(['is_active' => false]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame(2, $this->roomlessCount($batchId));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 2)
            ->assertJsonPath('promotion.failed', 3)
            ->assertJsonPath('data.roomless_pending_count', 0); // 2 promoted roomless + 3 with a room

        DB::table('rooms')->where('id', $room->id)->delete();
        $this->assertSame(3, ImportRow::where('import_batch_id', $batchId)->whereNull('promoted_asset_id')->whereNull('matched_room_id')->count());

        $this->assertSame(3, $this->roomlessCount($batchId)); // not 5
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 3);
        $this->assertSame(5, Asset::whereNull('room_id')->count());
    }

    public function test_rows_that_would_not_become_assets_are_excluded(): void
    {
        $inactive = $this->roomIn('02', 'Gudang Uji');
        $other = $this->stage([$this->rowFor('02', '144', 'LAB KOMP')]);
        $batchId = $this->stage([
            $this->rowFor('02', '141', 'LAB KOMP'),           // counted
            $this->rowFor('02', '', 'LAB KOMP'),              // error (no sequence): never promoted
            $this->rowFor('02', '143', 'LAB KOMP', ['K' => '2']), // error (quantity 2)
            $this->rowFor('02', '144', 'LAB KOMP'),           // will be a final-state duplicate of $other
            $this->rowFor('02', '145', 'Gudang Uji'),         // has a (soon inactive) room: fails, never roomless
        ]);
        $inactive->update(['is_active' => false]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame(2, $this->roomlessCount($batchId)); // 141 + 144 while 144 is still free

        $this->postJson("/api/imports/{$other}/promote")->assertOk()->assertJsonPath('promotion.promoted', 1);
        $this->assertSame(1, $this->roomlessCount($batchId)); // 144 would now fail as a duplicate

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 1)
            ->assertJsonPath('promotion.failed', 2); // duplicate + inactive room
        $this->assertSame(1, Asset::where('sequence_no', '141')->whereNull('room_id')->count());
    }

    public function test_resolving_a_room_mapping_lowers_the_count_by_exactly_the_resolved_rows(): void
    {
        $lab = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->stage([
            $this->rowFor('02', '151', 'LAB KOMP'),
            $this->rowFor('02', '152', 'Lab  Komp'),
            $this->rowFor('02', '153', 'PERPUS'),
        ]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame(3, $this->roomlessCount($batchId));

        $this->postJson("/api/imports/{$batchId}/room-mappings/resolve", [
            'location_code' => '02', 'raw_value' => 'LAB KOMP', 'room_id' => $lab->id, 'save_as_alias' => false,
        ])->assertOk()->assertJsonPath('data.updated_rows', 2);

        $this->assertSame(1, $this->roomlessCount($batchId));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 3);
        $this->assertSame(2, Asset::where('room_id', $lab->id)->count());
        $this->assertSame('153', Asset::whereNull('room_id')->sole()->sequence_no);
    }

    /* ================================================================== scope */

    public function test_unit_admin_gets_the_count_for_an_in_scope_batch_only(): void
    {
        $actor = $this->unitAdmin('02');
        $own = $this->stage([$this->rowFor('02', '161', 'LAB KOMP'), $this->rowFor('02', '162', 'LAB KOMP')], $this->unitAdmin('02'));
        $foreign = $this->stage([$this->rowFor('03', '163', 'LAB KOMP')], $actor); // even when recorded as uploader
        $mixed = $this->stage([$this->rowFor('02', '164', 'LAB KOMP'), $this->rowFor('03', '165', 'LAB KOMP')]);
        $noLocation = $this->stage([$this->rowFor(null, '166', 'LAB KOMP')], $actor);

        Sanctum::actingAs($actor);
        $this->assertSame(2, $this->roomlessCount($own));

        foreach ([$foreign, $mixed, $noLocation] as $batchId) {
            $show = $this->getJson("/api/imports/{$batchId}")->assertNotFound();
            $this->assertStringNotContainsString('roomless_pending_count', $show->getContent());
            $promote = $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();
            $this->assertStringNotContainsString('roomless_pending_count', $promote->getContent());

            // the service itself refuses too, independent of the controller
            try {
                app(AssetPromoter::class)->roomlessPendingCount($batchId, $actor);
                $this->fail("count was returned for out-of-scope batch {$batchId}");
            } catch (AuthorizationException) {
                // expected
            }
        }
        $this->assertSame(0, Asset::count());
    }

    public function test_global_actors_count_the_whole_batch_including_mixed_locations(): void
    {
        $batchId = $this->stage([
            $this->rowFor('02', '171', 'LAB KOMP'),
            $this->rowFor('03', '172', 'LAB KOMP'),
            $this->rowFor('04', '173', 'LAB KOMP'),
        ]);

        foreach ([UserRole::Operator, UserRole::SuperAdmin, UserRole::Admin] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->assertSame(3, $this->roomlessCount($batchId));
        }

        $this->assertSame(3, app(AssetPromoter::class)->roomlessPendingCount($batchId)); // CLI path: unrestricted
    }

    public function test_batch_list_does_not_carry_the_count(): void
    {
        $this->stage([$this->rowFor('02', '181', 'LAB KOMP')]);

        Sanctum::actingAs($this->user(UserRole::Admin));
        $this->getJson('/api/imports')->assertOk()->assertJsonMissingPath('data.0.roomless_pending_count');
    }

    /* ================================================================== idempotency + D1 */

    public function test_repeated_promotion_is_safe_and_reports_nothing_left_without_a_room(): void
    {
        $batchId = $this->stage([$this->rowFor('02', '191', 'LAB KOMP'), $this->rowFor('02', '192', 'LAB KOMP')]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 2);
        $this->assertSame(0, $this->roomlessCount($batchId));

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 0)
            ->assertJsonPath('promotion.skipped_already', 2)
            ->assertJsonPath('data.roomless_pending_count', 0);
        $this->assertSame(2, Asset::count());
    }

    public function test_d1_attribution_is_unchanged(): void
    {
        $lab = $this->roomIn('02', 'Laboratorium Komputer');
        $mapper = $this->unitAdmin('02');
        $promoter = $this->unitAdmin('02');
        $batchId = $this->stage([$this->rowFor('02', '201', 'LAB KOMP'), $this->rowFor('02', '202', 'PERPUS')], $this->unitAdmin('02'));

        Sanctum::actingAs($mapper);
        $this->postJson("/api/imports/{$batchId}/room-mappings/resolve", [
            'location_code' => '02', 'raw_value' => 'LAB KOMP', 'room_id' => $lab->id, 'save_as_alias' => false,
        ])->assertOk();

        Sanctum::actingAs($promoter);
        $this->assertSame(1, $this->roomlessCount($batchId)); // the preview writes nothing
        $this->assertNull(ImportBatch::find($batchId)->imported_at);

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()
            ->assertJsonPath('promotion.promoted', 2)
            ->assertJsonPath('data.imported_by.id', $promoter->id);

        $this->assertSame($mapper->id, ImportRoomMappingResolution::sole()->resolved_by);
        $this->assertSame($promoter->id, ImportBatch::find($batchId)->imported_by);
        $this->assertSame(2, ImportRow::where('import_batch_id', $batchId)->where('promoted_by', $promoter->id)->count());
        $this->assertSame('manual', ImportRow::where('import_batch_id', $batchId)->where('sequence_no', '201')->value('room_match_method'));
    }
}
