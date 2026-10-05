<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Import\ImportManager;
use App\Models\Asset;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportRoomMappingResolution;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use App\Policies\ImportBatchPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.9 R9.4-07 (D2) — location scope is the one authorization boundary for
 * every `imports/{batch}` operation; the uploader is audit metadata only.
 *
 * A unit_admin reaches a batch only when every located row is in its own
 * location (and at least one is) — {@see ImportBatchPolicy::batchWithinScope()},
 * the rule R9.4-08 introduced for promotion. Reads (show / rows / report /
 * room-mappings) answer an out-of-scope batch exactly like a missing one (404);
 * resolve and promote keep their 403 and write nothing. Global actors are
 * unchanged; viewers still have no import access at all.
 */
class Stage695ImportBatchScopeTest extends TestCase
{
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    private const CATEGORY = '02';

    private const SUBCATEGORY = '001';

    private const READS = ['', '/rows', '/report', '/room-mappings'];

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

    private function rowFor(?string $locationCode, string $sequenceNo, string $roomRawValue = 'RUANG X', array $overrides = []): array
    {
        return $this->validImportRowCells(array_merge([
            'B' => $locationCode ?? '', 'C' => self::CATEGORY, 'D' => self::SUBCATEGORY, 'E' => $sequenceNo,
            'O' => $roomRawValue,
        ], $overrides));
    }

    /** Stages through the real pipeline without a scoped actor (any location mix is possible), recording `$uploader`. */
    private function stage(array $rows, ?User $uploader = null): int
    {
        $this->ensureMasterData();
        $upload = $this->makeImportUpload(self::CATEGORY, $rows);

        return app(ImportManager::class)->stageFile($upload->getPathname(), $uploader?->id)['batch_id'];
    }

    private function resolve(int $batchId, string $locationCode, string $rawValue, int $roomId): TestResponse
    {
        return $this->postJson("/api/imports/{$batchId}/room-mappings/resolve", [
            'location_code' => $locationCode, 'raw_value' => $rawValue, 'room_id' => $roomId, 'save_as_alias' => false,
        ]);
    }

    private function assertReads(int $batchId, int $status): void
    {
        foreach (self::READS as $suffix) {
            $this->getJson("/api/imports/{$batchId}{$suffix}")->assertStatus($status);
        }
    }

    /** @return array<string, mixed> every staged row's mutable state, to prove nothing was written */
    private function rowState(int $batchId): array
    {
        return DB::table('import_rows')->where('import_batch_id', $batchId)->orderBy('id')
            ->get(['id', 'matched_room_id', 'room_match_method', 'room_mapping_resolution_id', 'validation_status', 'validation_messages', 'promoted_asset_id', 'promoted_by'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /* ================================================================== same location */

    public function test_same_location_unit_admin_can_use_every_endpoint_on_a_colleagues_batch(): void
    {
        $owner = $this->unitAdmin('02');
        $colleague = $this->unitAdmin('02');
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        Sanctum::actingAs($owner);
        $batchId = $this->postJson('/api/imports', ['file' => $this->makeImportUpload(self::CATEGORY, [
            $this->rowFor('02', '101', 'LAB KOMP'),
            $this->rowFor('02', '102', 'LAB KOMP'),
        ])])->assertCreated()->json('data.id');

        Sanctum::actingAs($colleague);
        $this->getJson("/api/imports/{$batchId}")->assertOk()
            ->assertJsonPath('data.id', $batchId)
            ->assertJsonPath('data.uploaded_by.id', $owner->id); // shown as audit metadata, not authority
        $this->getJson("/api/imports/{$batchId}/rows")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/imports/{$batchId}/report")->assertOk()->assertJsonPath('data.summary.total', 2);
        $this->getJson("/api/imports/{$batchId}/room-mappings")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.affected_rows', 2);

        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertOk()->assertJsonPath('data.updated_rows', 2);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 2);

        $this->assertSame($colleague->id, ImportRoomMappingResolution::sole()->resolved_by);
        $this->assertSame($colleague->id, ImportBatch::find($batchId)->imported_by);
        $this->assertSame(2, Asset::where('location_code', '02')->where('room_id', $room->id)->count());
    }

    public function test_batch_staged_by_a_global_user_is_reachable_for_the_same_location_unit_admin(): void
    {
        $batchId = $this->stage([$this->rowFor('02', '111')], $this->user(UserRole::Operator));

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->assertReads($batchId, 200);
    }

    /** Rows whose location could not be parsed carry nothing to check; the located rest decides. */
    public function test_rows_without_a_location_do_not_block_an_otherwise_in_scope_batch(): void
    {
        $batchId = $this->stage([$this->rowFor('02', '121'), $this->rowFor(null, '122')]);
        $this->assertSame(1, ImportRow::where('import_batch_id', $batchId)->whereNull('location_code')->count());

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->assertReads($batchId, 200);
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 1);
    }

    /* ================================================================== ownership is metadata */

    public function test_the_recorded_uploader_never_changes_who_may_reach_the_batch(): void
    {
        $actor = $this->unitAdmin('02');
        $foreignAdmin = $this->unitAdmin('03');
        $batchId = $this->stage([$this->rowFor('02', '131')]);

        foreach ([null, $actor->id, $this->unitAdmin('02')->id, $foreignAdmin->id, $this->user(UserRole::Admin)->id] as $uploader) {
            DB::table('import_batches')->where('id', $batchId)->update(['uploaded_by' => $uploader]);

            Sanctum::actingAs($actor);
            $this->assertReads($batchId, 200);

            // even when recorded as the uploader, an actor outside the batch's location
            // (e.g. reassigned to another unit since) cannot reach it
            Sanctum::actingAs($foreignAdmin);
            $this->assertReads($batchId, 404);
        }
    }

    /* ================================================================== foreign location */

    public function test_foreign_location_batch_is_invisible_and_untouchable(): void
    {
        $room = $this->roomIn('03', 'Laboratorium Komputer');
        $batchId = $this->stage([$this->rowFor('03', '141', 'LAB KOMP')], $this->unitAdmin('03'));
        $before = $this->rowState($batchId);
        $batchBefore = (array) DB::table('import_batches')->find($batchId);

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->assertReads($batchId, 404);
        $this->resolve($batchId, '03', 'LAB KOMP', $room->id)->assertForbidden();
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertForbidden(); // claiming own location doesn't help
        $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();

        $this->assertSame($before, $this->rowState($batchId));
        $this->assertEquals($batchBefore, (array) DB::table('import_batches')->find($batchId));
        $this->assertSame(0, ImportRoomMappingResolution::count());
        $this->assertSame(0, Asset::count());
    }

    /**
     * No-leak: an out-of-scope batch reads exactly like one that does not exist —
     * same status and the same body as rendered in production (debug off; with
     * debug on, stack traces differ by nature). Before D2 the policy's bare
     * abort(404) answered with an empty message while a missing id said "No query
     * results for model ...", which told the two apart.
     */
    public function test_denied_reads_are_indistinguishable_from_a_missing_batch(): void
    {
        config(['app.debug' => false]);
        $batchId = $this->stage([$this->rowFor('03', '151')], $this->user(UserRole::Operator));

        $missingId = $batchId + 1000;
        $notFound = fn (int $id): array => ['message' => 'No query results for model ['.ImportBatch::class."] {$id}"];

        Sanctum::actingAs($this->unitAdmin('02'));
        foreach (self::READS as $suffix) {
            $missing = $this->getJson("/api/imports/{$missingId}{$suffix}")->assertNotFound();
            $denied = $this->getJson("/api/imports/{$batchId}{$suffix}")->assertNotFound();

            $this->assertSame($notFound($missingId), $missing->json(), "missing-batch body for '{$suffix}'");
            $this->assertSame($notFound($batchId), $denied->json(), "denied body differs from a missing one for '{$suffix}'");
            $this->assertStringNotContainsString('test-import.xlsx', $denied->getContent());
        }
    }

    /* ================================================================== mixed location */

    public function test_mixed_location_batch_is_out_of_reach_for_unit_admins_of_either_location(): void
    {
        $room02 = $this->roomIn('02', 'Laboratorium Komputer');
        $room03 = $this->roomIn('03', 'Laboratorium Komputer');
        $batchId = $this->stage([
            $this->rowFor('02', '161', 'LAB KOMP'),
            $this->rowFor('03', '162', 'LAB KOMP'),
        ], $this->user(UserRole::Operator));
        $before = $this->rowState($batchId);

        foreach (['02' => $room02, '03' => $room03] as $code => $ownRoom) {
            Sanctum::actingAs($this->unitAdmin($code));
            $this->assertReads($batchId, 404);
            // its OWN location's group inside the mixed batch is not resolvable either
            $this->resolve($batchId, $code, 'LAB KOMP', $ownRoom->id)->assertForbidden();
            $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();
        }

        $this->assertSame($before, $this->rowState($batchId));
        $this->assertSame(0, ImportRoomMappingResolution::count());
        $this->assertSame(0, Asset::count());
    }

    /** The foreign row decides even when it is only an error row (same as R9.4-08 promotion). */
    public function test_mixed_batch_stays_out_of_reach_when_the_foreign_row_is_an_error(): void
    {
        $batchId = $this->stage([
            $this->rowFor('02', '171'),
            $this->rowFor('03', '', 'RUANG X'), // empty sequence -> error row, still located in 03
        ]);
        $this->assertSame('error', ImportRow::where('import_batch_id', $batchId)->where('location_code', '03')->value('validation_status'));

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->assertReads($batchId, 404);
        $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();
    }

    /* ================================================================== no location */

    public function test_batch_without_any_located_row_is_out_of_reach_for_unit_admin_even_its_uploader(): void
    {
        $uploader = $this->unitAdmin('02');
        Sanctum::actingAs($uploader);
        $response = $this->postJson('/api/imports', ['file' => $this->makeImportUpload(self::CATEGORY, [
            $this->rowFor(null, '181'),
        ])])->assertCreated(); // staging accepts it (nothing out of scope) — every row is an error
        $batchId = $response->json('data.id');
        $this->assertSame(0, ImportRow::where('import_batch_id', $batchId)->whereNotNull('location_code')->count());

        $this->assertReads($batchId, 404);
        $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();

        foreach ([UserRole::SuperAdmin, UserRole::Operator, UserRole::Admin] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->assertReads($batchId, 200);
        }
    }

    /* ================================================================== global actors */

    public function test_global_actors_reach_every_kind_of_batch(): void
    {
        $batches = [
            'own' => $this->stage([$this->rowFor('02', '191')]),
            'foreign' => $this->stage([$this->rowFor('03', '192')]),
            'mixed' => $this->stage([$this->rowFor('02', '193'), $this->rowFor('04', '194')]),
            'no-location' => $this->stage([$this->rowFor(null, '195')]),
        ];

        foreach ([UserRole::SuperAdmin, UserRole::Operator, UserRole::Admin] as $role) {
            Sanctum::actingAs($this->user($role));
            foreach ($batches as $batchId) {
                $this->assertReads($batchId, 200);
            }
        }
    }

    public function test_global_actors_can_still_map_and_promote_a_mixed_batch(): void
    {
        $room03 = $this->roomIn('03', 'Laboratorium Komputer');
        $batchId = $this->stage([
            $this->rowFor('02', '201', 'LAB KOMP'),
            $this->rowFor('03', '202', 'LAB KOMP'),
        ]);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->getJson("/api/imports/{$batchId}/room-mappings")->assertOk()->assertJsonCount(2, 'data');
        $this->resolve($batchId, '03', 'LAB KOMP', $room03->id)->assertOk()->assertJsonPath('data.updated_rows', 1);

        Sanctum::actingAs($this->user(UserRole::SuperAdmin));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk()->assertJsonPath('promotion.promoted', 2);
    }

    /* ================================================================== viewer */

    public function test_viewer_still_has_no_import_access(): void
    {
        $room = $this->roomIn('02', 'Laboratorium Komputer');
        $batchId = $this->stage([$this->rowFor('02', '211', 'LAB KOMP')]);

        Sanctum::actingAs($this->user(UserRole::Viewer));
        $this->assertReads($batchId, 403);
        $this->resolve($batchId, '02', 'LAB KOMP', $room->id)->assertForbidden();
        $this->postJson("/api/imports/{$batchId}/promote")->assertForbidden();
        $this->getJson('/api/imports')->assertForbidden();
    }

    /** Unchanged: the cross-batch history list stays global-only. */
    public function test_unit_admin_still_cannot_list_import_history(): void
    {
        $this->stage([$this->rowFor('02', '221')]);

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->getJson('/api/imports')->assertForbidden();
    }
}
