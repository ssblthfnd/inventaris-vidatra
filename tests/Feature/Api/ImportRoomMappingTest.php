<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\Room;
use App\Models\RoomAlias;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.9 R9.2 — Import Room Mapping Resolution.
 *
 * `GET /api/imports/{batch}/room-mappings` and
 * `POST /api/imports/{batch}/room-mappings/resolve`. Every test hits real
 * HTTP endpoints and stages real `.xlsx` uploads via the actual pipeline
 * (`InteractsWithImportFixtures`), never a hand-rolled shortcut around
 * `ImportManager`/`RoomMappingResolver`.
 */
class ImportRoomMappingTest extends TestCase
{
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    private const CATEGORY = '02';

    private const SUBCATEGORY = '001';

    /* ================================================================== fixtures */

    private function ensureLocation(string $code, bool $active = true): Location
    {
        return Location::query()->firstOrCreate(
            ['code' => $code],
            ['name' => "Lokasi {$code}", 'is_active' => $active],
        );
    }

    private function ensureMasterData(): void
    {
        $category = Category::query()->firstOrCreate(
            ['code' => self::CATEGORY],
            ['name' => 'Kategori 02', 'is_active' => true],
        );
        Subcategory::query()->firstOrCreate(
            ['category_code' => $category->code, 'code' => self::SUBCATEGORY],
            ['name' => 'Sub 001', 'is_active' => true],
        );
    }

    private function globalUser(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function unitAdmin(?string $locationCode): User
    {
        if ($locationCode !== null) {
            $this->ensureLocation($locationCode);
        }

        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    private function roomIn(string $locationCode, array $overrides = []): Room
    {
        $this->ensureLocation($locationCode);

        return Room::factory()->create(array_merge(['location_code' => $locationCode], $overrides));
    }

    /** One data row's cells for a given location/sequence/room raw value; category/subcategory fixed. */
    private function rowFor(string $locationCode, string $sequenceNo, string $roomRawValue, array $overrides = []): array
    {
        return $this->validImportRowCells(array_merge([
            'B' => $locationCode, 'C' => self::CATEGORY, 'D' => self::SUBCATEGORY, 'E' => $sequenceNo,
            'O' => $roomRawValue,
        ], $overrides));
    }

    private function upload(array $rows): TestResponse
    {
        $this->ensureMasterData();
        $file = $this->makeImportUpload(self::CATEGORY, $rows);

        return $this->postJson('/api/imports', ['file' => $file]);
    }

    /** Stages a batch with `$count` rows all sharing one unrecognised room raw value in `$locationCode`. */
    private function stageUnmapped(User $actor, string $locationCode, string $rawValue, int $count = 1, string $seqPrefix = '9'): int
    {
        $this->ensureLocation($locationCode);
        Sanctum::actingAs($actor);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $this->rowFor($locationCode, $seqPrefix.$i, $rawValue);
        }
        $response = $this->upload($rows)->assertCreated();

        return $response->json('data.id');
    }

    private function listMappings(int $batchId): TestResponse
    {
        return $this->getJson("/api/imports/{$batchId}/room-mappings");
    }

    private function resolve(int $batchId, array $payload): TestResponse
    {
        return $this->postJson("/api/imports/{$batchId}/room-mappings/resolve", $payload);
    }

    /* ================================================================== A. room grouping */

    public function test_same_raw_value_in_different_locations_are_two_distinct_groups(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $this->ensureLocation('02');
        $this->ensureLocation('04');
        Sanctum::actingAs($operator);
        $this->upload([
            $this->rowFor('02', '601', 'LAB KOMPUTER'),
            $this->rowFor('04', '602', 'LAB KOMPUTER'),
        ])->assertCreated();
        $batchId = ImportBatch::latest('id')->first()->id;

        $groups = $this->listMappings($batchId)->assertOk()->json('data');

        $this->assertCount(2, $groups);
        $locations = array_column($groups, 'location_code');
        $this->assertContains('02', $locations);
        $this->assertContains('04', $locations);
    }

    public function test_same_normalized_value_within_one_location_is_one_group(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $this->ensureLocation('02');
        Sanctum::actingAs($operator);
        $this->upload([
            $this->rowFor('02', '611', 'LAB KOMPUTER'),
            $this->rowFor('02', '612', 'LAB KOMPUTER'),
            $this->rowFor('02', '613', 'LAB KOMPUTER'),
        ])->assertCreated();
        $batchId = ImportBatch::latest('id')->first()->id;

        $groups = $this->listMappings($batchId)->assertOk()->json('data');

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['affected_rows']);
    }

    public function test_case_and_spacing_variants_group_together_via_normalization(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $this->ensureLocation('02');
        Sanctum::actingAs($operator);
        $this->upload([
            $this->rowFor('02', '621', 'lab komputer'),
            $this->rowFor('02', '622', 'Lab  Komputer'), // double space
            $this->rowFor('02', '623', ' LAB KOMPUTER '), // leading/trailing space
        ])->assertCreated();
        $batchId = ImportBatch::latest('id')->first()->id;

        $groups = $this->listMappings($batchId)->assertOk()->json('data');

        $this->assertCount(1, $groups);
        $this->assertSame(3, $groups[0]['affected_rows']);
        $this->assertSame('LAB KOMPUTER', $groups[0]['match_key']);
    }

    public function test_distinct_raw_values_are_distinct_groups_even_in_the_same_location(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $this->ensureLocation('02');
        Sanctum::actingAs($operator);
        $this->upload([
            $this->rowFor('02', '631', 'LAB KOMPUTER'),
            $this->rowFor('02', '632', 'LABKOM'),
            $this->rowFor('02', '633', 'RUANG BARU'),
        ])->assertCreated();
        $batchId = ImportBatch::latest('id')->first()->id;

        $groups = $this->listMappings($batchId)->assertOk()->json('data');

        $this->assertCount(3, $groups);
    }

    public function test_already_mapped_rooms_never_appear_as_unmapped_groups(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', ['name' => 'Gudang']);
        Sanctum::actingAs($operator);
        $this->upload([
            $this->rowFor('02', '641', 'GUDANG'), // exact-name match, resolves at staging time
        ])->assertCreated();
        $batchId = ImportBatch::latest('id')->first()->id;

        $groups = $this->listMappings($batchId)->assertOk()->json('data');

        $this->assertCount(0, $groups);
    }

    /* ================================================================== B/D. resolve to existing room, import-only */

    public function test_resolve_to_existing_room_import_only_updates_staged_rows_without_creating_alias(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $target = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER', 3);

        $response = $this->resolve($batchId, [
            'location_code' => '02',
            'raw_value' => 'LAB KOMPUTER',
            'room_id' => $target->id,
            'save_as_alias' => false,
        ])->assertOk();

        $response->assertJsonPath('data.updated_rows', 3);
        $response->assertJsonPath('data.alias_created', false);
        $this->assertSame(3, ImportRow::where('import_batch_id', $batchId)->where('matched_room_id', $target->id)->count());
        $this->assertSame(0, RoomAlias::count());
        // raw value preserved verbatim on the rows
        $this->assertSame('LAB KOMPUTER', ImportRow::where('import_batch_id', $batchId)->first()->room_raw_value);
    }

    public function test_target_room_must_be_in_the_same_location_as_the_group(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $wrongLocationRoom = $this->roomIn('04', ['name' => 'Laboratorium Komputer Utama']);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');

        $this->resolve($batchId, [
            'location_code' => '02',
            'raw_value' => 'LAB KOMPUTER',
            'room_id' => $wrongLocationRoom->id,
            'save_as_alias' => false,
        ])->assertStatus(422);

        $this->assertSame(0, ImportRow::whereNotNull('matched_room_id')->count());
    }

    public function test_inactive_target_room_is_rejected(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $inactive = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama', 'is_active' => false]);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');

        $this->resolve($batchId, [
            'location_code' => '02',
            'raw_value' => 'LAB KOMPUTER',
            'room_id' => $inactive->id,
            'save_as_alias' => false,
        ])->assertStatus(422);
    }

    public function test_nonexistent_target_room_is_rejected(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');

        $this->resolve($batchId, [
            'location_code' => '02',
            'raw_value' => 'LAB KOMPUTER',
            'room_id' => 999999,
            'save_as_alias' => false,
        ])->assertStatus(422);
    }

    public function test_resolve_only_affects_rows_matching_that_normalized_group_not_other_groups(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $target = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $this->ensureLocation('02');
        Sanctum::actingAs($operator);
        $this->upload([
            $this->rowFor('02', '651', 'LAB KOMPUTER'),
            $this->rowFor('02', '652', 'RUANG BARU'),
        ])->assertCreated();
        $batchId = ImportBatch::latest('id')->first()->id;

        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $target->id, 'save_as_alias' => false,
        ])->assertOk()->assertJsonPath('data.updated_rows', 1);

        $this->assertSame(1, ImportRow::whereNotNull('matched_room_id')->count());
        $this->assertNull(ImportRow::where('sequence_no', '652')->first()->matched_room_id);
    }

    /* ================================================================== E. permanent alias */

    public function test_save_as_alias_creates_alias_and_sets_created_by(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $target = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER', 2);

        $response = $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $target->id, 'save_as_alias' => true,
        ])->assertOk();

        $response->assertJsonPath('data.alias_created', true);
        $this->assertSame(2, ImportRow::where('matched_room_id', $target->id)->count());

        $alias = RoomAlias::where('location_code', '02')->where('match_key', 'LAB KOMPUTER')->first();
        $this->assertNotNull($alias);
        $this->assertSame($target->id, $alias->room_id);
        $this->assertSame($operator->id, $alias->created_by);
        $this->assertSame('manual', $alias->source);
        $this->assertSame('LAB KOMPUTER', $alias->raw_value);
    }

    public function test_alias_created_by_this_action_resolves_a_later_import_automatically(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $target = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');
        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $target->id, 'save_as_alias' => true,
        ])->assertOk();

        // a FRESH upload, after the alias exists — RoomMatcher should resolve it at staging time
        Sanctum::actingAs($operator);
        $this->upload([$this->rowFor('02', '699', 'LAB KOMPUTER')])->assertCreated();
        $newBatchId = ImportBatch::latest('id')->first()->id;
        $row = ImportRow::where('import_batch_id', $newBatchId)->first();

        $this->assertSame($target->id, $row->matched_room_id);
        $this->assertSame('alias', $row->room_match_method);
        // resolved via alias -> no room_unmapped warning this time
        $this->assertSame('valid', $row->validation_status);
    }

    public function test_duplicate_alias_for_the_same_group_is_rejected_not_overwritten(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $roomA = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $roomB = $this->roomIn('02', ['name' => 'Ruang Serbaguna']);
        RoomAlias::factory()->create([
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'match_key' => 'LAB KOMPUTER',
            'room_id' => $roomA->id, 'source' => 'manual',
        ]);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');

        // staged row already auto-resolved via the pre-existing alias at staging time,
        // so there is nothing left unmapped to resolve — confirm that directly first.
        $this->assertSame(0, ImportRow::where('import_batch_id', $batchId)->whereNull('matched_room_id')->count());

        // now attempt to point the SAME group at a DIFFERENT room while save_as_alias=true
        // (simulating a client racing/ignoring the already-resolved state) — must be
        // rejected, never silently overwrite the existing alias.
        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $roomB->id, 'save_as_alias' => true,
        ])->assertStatus(422);

        $this->assertSame(1, RoomAlias::where('location_code', '02')->where('match_key', 'LAB KOMPUTER')->count());
        $this->assertSame($roomA->id, RoomAlias::where('location_code', '02')->where('match_key', 'LAB KOMPUTER')->first()->room_id);
    }

    public function test_resolving_to_the_same_room_an_alias_already_points_to_is_a_safe_noop_not_an_error(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        // stage BEFORE the alias exists, so the row stays unmapped
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');
        RoomAlias::factory()->create([
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'match_key' => 'LAB KOMPUTER',
            'room_id' => $room->id, 'source' => 'manual',
        ]);

        $response = $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $room->id, 'save_as_alias' => true,
        ])->assertOk();

        $response->assertJsonPath('data.alias_created', false);
        $response->assertJsonPath('data.alias_already_existed', true);
        $response->assertJsonPath('data.updated_rows', 1);
        $this->assertSame(1, RoomAlias::where('location_code', '02')->where('match_key', 'LAB KOMPUTER')->count());
    }

    public function test_alias_scoped_to_one_location_does_not_resolve_the_same_raw_value_in_another_location(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $room02 = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $this->resolve(
            $this->stageUnmapped($operator, '02', 'LAB KOMPUTER'),
            ['location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $room02->id, 'save_as_alias' => true],
        )->assertOk();

        // location 04 staging the SAME raw value must still show up unmapped —
        // the alias created for 02 must never leak into 04's matching.
        $batchId04 = $this->stageUnmapped($operator, '04', 'LAB KOMPUTER');
        $groups = $this->listMappings($batchId04)->assertOk()->json('data');
        $this->assertCount(1, $groups);
        $this->assertSame('04', $groups[0]['location_code']);
    }

    /* ================================================================== F. authorization */

    public function test_unit_admin_sees_only_own_location_unmapped_groups(): void
    {
        $global = $this->globalUser(UserRole::Operator);
        $this->ensureLocation('02');
        $this->ensureLocation('04');
        Sanctum::actingAs($global);
        $this->upload([
            $this->rowFor('02', '701', 'LAB KOMPUTER'),
            $this->rowFor('04', '702', 'RUANG LAIN'),
        ])->assertCreated();
        $batchId = ImportBatch::latest('id')->first()->id;

        Sanctum::actingAs($this->unitAdmin('02'));
        $groups = $this->listMappings($batchId)->assertOk()->json('data');

        $this->assertCount(1, $groups);
        $this->assertSame('02', $groups[0]['location_code']);
    }

    public function test_unit_admin_cannot_resolve_mapping_outside_own_location(): void
    {
        $global = $this->globalUser(UserRole::Operator);
        $room04 = $this->roomIn('04', ['name' => 'Ruang Cadangan Lain']);
        $batchId = $this->stageUnmapped($global, '04', 'RUANG LAIN');

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->resolve($batchId, [
            'location_code' => '04', 'raw_value' => 'RUANG LAIN', 'room_id' => $room04->id, 'save_as_alias' => false,
        ])->assertStatus(403);

        $this->assertSame(0, ImportRow::whereNotNull('matched_room_id')->count());
    }

    public function test_colocated_unit_admin_can_resolve_a_colleagues_staged_batch(): void
    {
        $uploader = $this->unitAdmin('02');
        $room = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $batchId = $this->stageUnmapped($uploader, '02', 'LAB KOMPUTER', 2);

        $colleague = $this->unitAdmin('02');
        Sanctum::actingAs($colleague);
        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $room->id, 'save_as_alias' => false,
        ])->assertOk()->assertJsonPath('data.updated_rows', 2);
    }

    public function test_unit_admin_can_create_and_use_a_room_in_own_location_end_to_end(): void
    {
        $admin = $this->unitAdmin('02');
        $this->ensureLocation('02');
        $batchId = $this->stageUnmapped($admin, '02', 'LAB KOMPUTER');

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/rooms', [
            'location_code' => '02', 'name' => 'Laboratorium Komputer Utama',
        ])->assertCreated();
        $roomId = $created->json('data.id');

        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $roomId, 'save_as_alias' => true,
        ])->assertOk()->assertJsonPath('data.alias_created', true);

        $this->assertSame($admin->id, RoomAlias::where('location_code', '02')->first()->created_by);
    }

    public function test_viewer_is_forbidden(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');

        Sanctum::actingAs($this->globalUser(UserRole::Viewer));
        $this->listMappings($batchId)->assertStatus(403);
        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => 1, 'save_as_alias' => false,
        ])->assertStatus(403);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        // auth:sanctum runs before route-model binding, so no real batch is
        // needed — an unauthenticated request never even reaches that step.
        $this->getJson('/api/imports/1/room-mappings')->assertStatus(401);
        $this->postJson('/api/imports/1/room-mappings/resolve', [])->assertStatus(401);
    }

    public function test_super_admin_and_admin_can_resolve_any_location(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $batchId = $this->stageUnmapped($operator, '03', 'RUANG UJI');
        $room = $this->roomIn('03', ['name' => 'Ruangan Pengujian']);

        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));
        $this->resolve($batchId, [
            'location_code' => '03', 'raw_value' => 'RUANG UJI', 'room_id' => $room->id, 'save_as_alias' => false,
        ])->assertOk()->assertJsonPath('data.updated_rows', 1);
    }

    /* ================================================================== G. promotion */

    public function test_mapped_rows_are_promoted_with_the_resolved_room(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $target = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');

        Sanctum::actingAs($operator);
        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $target->id, 'save_as_alias' => false,
        ])->assertOk();
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $asset = \App\Models\Asset::where('location_code', '02')->where('sequence_no', '90')->first();
        $this->assertNotNull($asset);
        $this->assertSame($target->id, $asset->room_id);
    }

    public function test_unresolved_warning_rows_can_still_be_promoted_with_null_room(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');

        Sanctum::actingAs($operator);
        // deliberately do NOT resolve — "Tetap Import" path
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $asset = \App\Models\Asset::where('location_code', '02')->where('sequence_no', '90')->first();
        $this->assertNotNull($asset);
        $this->assertNull($asset->room_id);
        $this->assertSame('LAB KOMPUTER', $asset->room_raw_value);
    }

    public function test_already_promoted_rows_are_excluded_from_unmapped_groups(): void
    {
        $operator = $this->globalUser(UserRole::Operator);
        $batchId = $this->stageUnmapped($operator, '02', 'LAB KOMPUTER');
        Sanctum::actingAs($operator);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $groups = $this->listMappings($batchId)->assertOk()->json('data');

        $this->assertCount(0, $groups);
    }

    /* ================================================================== H. security */

    public function test_nonexistent_batch_id_is_404(): void
    {
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->getJson('/api/imports/999999/room-mappings')->assertStatus(404);
        $this->resolve(999999, [
            'location_code' => '02', 'raw_value' => 'X', 'room_id' => 1, 'save_as_alias' => false,
        ])->assertStatus(404);
    }

    public function test_unit_admin_cannot_use_a_cross_location_room_id_even_if_group_location_matches_own_scope(): void
    {
        // group is in the unit_admin's OWN location (02), but the room_id
        // submitted belongs to a DIFFERENT location (04) — must be rejected,
        // not silently mapped/no-op, and must never leak whether room 04-owned
        // id exists via a different status code.
        $admin = $this->unitAdmin('02');
        $foreignRoom = $this->roomIn('04', ['name' => 'Ruang Asing']);
        $batchId = $this->stageUnmapped($admin, '02', 'LAB KOMPUTER');

        Sanctum::actingAs($admin);
        $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'LAB KOMPUTER', 'room_id' => $foreignRoom->id, 'save_as_alias' => false,
        ])->assertStatus(422);

        $this->assertSame(0, ImportRow::whereNotNull('matched_room_id')->count());
    }

    public function test_location_code_claimed_in_payload_cannot_override_actual_scope_check(): void
    {
        // A unit_admin for 02 cannot resolve a group that is REALLY in location
        // 04 just by claiming location_code=02 in the payload — the group must
        // still exist for that exact (batch, location_code, raw_value) tuple,
        // and 02 is genuinely within scope so the request reaches the room/
        // location match check, which then finds nothing to update.
        $global = $this->globalUser(UserRole::Operator);
        $room02 = $this->roomIn('02', ['name' => 'Laboratorium Komputer Utama']);
        $batchId = $this->stageUnmapped($global, '04', 'RUANG LAIN 04');

        Sanctum::actingAs($this->unitAdmin('02'));
        $response = $this->resolve($batchId, [
            'location_code' => '02', 'raw_value' => 'RUANG LAIN 04', 'room_id' => $room02->id, 'save_as_alias' => false,
        ])->assertOk();

        // scope check for '02' passes (it's the actor's own location), but
        // there is genuinely no unresolved row for (batch, '02', that raw
        // value) since the staged row is actually location '04' — nothing
        // updated, no cross-location leakage.
        $response->assertJsonPath('data.updated_rows', 0);
        $this->assertSame(0, ImportRow::whereNotNull('matched_room_id')->count());
    }
}
