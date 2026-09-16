<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Import\ImportManager;
use App\Import\Promotion\AssetPromoter;
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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Stage 6.9 R6 — import location scope. Every test hits a real HTTP
 * endpoint and uses a real `.xlsx` workbook ({@see InteractsWithImportFixtures}),
 * exercising the actual staging/promotion pipeline — never a shortcut around
 * {@see ImportManager} / {@see AssetPromoter}.
 *
 * R6 is exclusively about WHERE (location scope) for the `assets.import`
 * ability — it does not redesign duplicate detection, does not add
 * export/master-data/room-alias/user scope, and does not touch the
 * IDENTICAL-vs-CONFLICT duplicate model. See the R6 report for what was
 * deliberately left alone.
 */
class Stage69ImportScopeTest extends TestCase
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

    private function seedUnitLocations(): void
    {
        $this->ensureLocation('02');
        $this->ensureLocation('03');
        $this->ensureLocation('04');
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
        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    /** One data row's cells for a given location + sequence, category/subcategory fixed. */
    private function rowFor(string $locationCode, string $sequenceNo, array $overrides = []): array
    {
        return $this->validImportRowCells(array_merge([
            'B' => $locationCode, 'C' => self::CATEGORY, 'D' => self::SUBCATEGORY, 'E' => $sequenceNo,
        ], $overrides));
    }

    private function upload(array $rows): TestResponse
    {
        $this->ensureMasterData();
        $file = $this->makeImportUpload(self::CATEGORY, $rows);

        return $this->postJson('/api/imports', ['file' => $file]);
    }

    private function latestBatchFor(User $uploader): ImportBatch
    {
        return ImportBatch::where('uploaded_by', $uploader->id)->latest('id')->firstOrFail();
    }

    /* ================================================================== A. permission */

    public function test_unit_admin_has_assets_import_and_can_upload(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->upload([$this->rowFor('02', '501')])->assertCreated();
    }

    public function test_viewer_cannot_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->upload([$this->rowFor('02', '502')])->assertStatus(403);
    }

    public function test_operator_retains_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->upload([$this->rowFor('02', '503'), $this->rowFor('03', '504')])->assertCreated();
    }

    public function test_legacy_admin_retains_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->upload([$this->rowFor('02', '505'), $this->rowFor('03', '506')])->assertCreated();
    }

    public function test_super_admin_can_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->upload([$this->rowFor('02', '507'), $this->rowFor('03', '508')])->assertCreated();
    }

    /* ================================================================== B. single-location import */

    public function test_unit_admin_imports_own_location_successfully(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $response = $this->upload([$this->rowFor('02', '510')])->assertCreated();
        $batchId = $response->json('data.id');

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();
        $this->assertSame(1, Asset::where('location_code', '02')->where('sequence_no', '510')->count());
    }

    public function test_unit_admin_cannot_import_another_location(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([$this->rowFor('03', '511')])->assertStatus(403);

        $batch = $this->latestBatchFor($actor);
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, Asset::where('sequence_no', '511')->count());
    }

    public function test_request_parameter_cannot_override_excel_resolved_location(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->ensureMasterData();
        $file = $this->makeImportUpload(self::CATEGORY, [$this->rowFor('03', '512')]);

        // A location_code form field alongside the file is not part of the
        // upload contract at all (StoreImportRequest only reads `file`) —
        // it must have zero effect on the actual, Excel-resolved location.
        $this->postJson('/api/imports', ['file' => $file, 'location_code' => '02'])
            ->assertStatus(403);

        $batch = $this->latestBatchFor($actor);
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, Asset::where('sequence_no', '512')->count());
    }

    /* ================================================================== C. mixed imports */

    public function test_own_plus_foreign_location_entirely_rejected(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([
            $this->rowFor('02', '520'),
            $this->rowFor('02', '521'),
            $this->rowFor('03', '522'),
            $this->rowFor('02', '523'),
        ])->assertStatus(403);

        $batch = $this->latestBatchFor($actor);
        $this->assertSame('failed', $batch->status);
        $this->assertSame(0, Asset::whereIn('sequence_no', ['520', '521', '522', '523'])->count());
    }

    public function test_foreign_plus_own_plus_foreign_entirely_rejected(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([
            $this->rowFor('03', '530'),
            $this->rowFor('02', '531'),
            $this->rowFor('04', '532'),
        ])->assertStatus(403);

        $this->assertSame(0, Asset::whereIn('sequence_no', ['530', '531', '532'])->count());
    }

    public function test_rejected_mixed_import_cannot_later_be_promoted(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([$this->rowFor('02', '540'), $this->rowFor('03', '541')])->assertStatus(403);
        $batch = $this->latestBatchFor($actor);

        $this->postJson("/api/imports/{$batch->id}/promote")->assertStatus(422);
        $this->assertSame(0, Asset::whereIn('sequence_no', ['540', '541'])->count());
    }

    /* ================================================================== D. staging / promotion bypass */

    public function test_promotion_rechecks_current_actor_scope_for_a_batch_staged_by_a_global_actor(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));
        $response = $this->upload([$this->rowFor('02', '550'), $this->rowFor('03', '551')])->assertCreated();
        $batchId = $response->json('data.id');

        // A DIFFERENT actor (unit_admin, 02) now tries to promote a batch
        // they did NOT stage, that contains a row (03) outside their scope.
        Sanctum::actingAs($this->unitAdmin('02'));
        $this->postJson("/api/imports/{$batchId}/promote")->assertStatus(403);

        $this->assertSame(0, Asset::whereIn('sequence_no', ['550', '551'])->count());
    }

    public function test_different_unit_admin_cannot_promote_an_import_outside_their_scope(): void
    {
        $this->seedUnitLocations();
        $uploader = $this->unitAdmin('02');
        Sanctum::actingAs($uploader);
        $response = $this->upload([$this->rowFor('02', '560')])->assertCreated();
        $batchId = $response->json('data.id');

        Sanctum::actingAs($this->unitAdmin('03'));
        $this->postJson("/api/imports/{$batchId}/promote")->assertStatus(403);
        $this->assertSame(0, Asset::where('sequence_no', '560')->count());
    }

    public function test_colocated_unit_admin_can_promote_a_colleagues_own_location_batch(): void
    {
        $this->seedUnitLocations();
        $uploader = $this->unitAdmin('02');
        Sanctum::actingAs($uploader);
        $response = $this->upload([$this->rowFor('02', '561')])->assertCreated();
        $batchId = $response->json('data.id');

        // A DIFFERENT unit_admin who happens to share the SAME location (02)
        // is legitimately allowed to promote it — multiple unit_admins per
        // unit is an explicitly supported scenario since R1.
        Sanctum::actingAs($this->unitAdmin('02'));
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();
        $this->assertSame(1, Asset::where('sequence_no', '561')->count());
    }

    public function test_unit_admin_cannot_view_another_unit_admins_batch(): void
    {
        $this->seedUnitLocations();
        $uploader = $this->unitAdmin('02');
        Sanctum::actingAs($uploader);
        $response = $this->upload([$this->rowFor('02', '562')])->assertCreated();
        $batchId = $response->json('data.id');

        Sanctum::actingAs($this->unitAdmin('03'));
        $this->getJson("/api/imports/{$batchId}")->assertStatus(404);
        $this->getJson("/api/imports/{$batchId}/rows")->assertStatus(404);
        $this->getJson("/api/imports/{$batchId}/report")->assertStatus(404);
    }

    public function test_unit_admin_cannot_list_import_history(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/imports')->assertStatus(403);
    }

    /* ================================================================== E. location tampering */

    public function test_excel_location_tampering_is_rejected_not_rewritten(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([$this->rowFor('03', '570')])->assertStatus(403);

        // Never silently rewritten to the actor's own location.
        $this->assertSame(0, Asset::where('location_code', '02')->where('sequence_no', '570')->count());
        $this->assertSame(0, Asset::where('sequence_no', '570')->count());
    }

    public function test_room_matching_stays_scoped_to_the_rows_own_location_no_bypass(): void
    {
        $this->seedUnitLocations();
        $this->ensureMasterData();
        $foreignRoom = Room::factory()->create(['location_code' => '03', 'name' => 'RUANG KHUSUS 03']);
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        // location=02 (in scope) but the room name only exists in location 03
        // — RoomMatcher scopes its own lookup by the row's location, so this
        // can only ever produce "unmapped" (warning), never a cross-location
        // room match; the row stays promotable, IN location 02.
        $this->upload([$this->rowFor('02', '571', ['O' => $foreignRoom->name])])->assertCreated();

        $batch = $this->latestBatchFor($actor);
        $this->assertSame('validated', $batch->status);
        $this->postJson("/api/imports/{$batch->id}/promote")->assertOk();

        $asset = Asset::where('sequence_no', '571')->firstOrFail();
        $this->assertSame('02', $asset->location_code);
        $this->assertNull($asset->room_id);
    }

    public function test_room_from_own_location_cannot_rescue_an_out_of_scope_row(): void
    {
        $this->seedUnitLocations();
        $this->ensureMasterData();
        $ownRoom = Room::factory()->create(['location_code' => '02', 'name' => 'RUANG SAYA']);
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        // location=03 (out of scope) even though the room name matches one
        // of the actor's OWN rooms — the location field alone governs scope.
        $this->upload([$this->rowFor('03', '572', ['O' => $ownRoom->name])])->assertStatus(403);
        $this->assertSame(0, Asset::where('sequence_no', '572')->count());
    }

    /* ================================================================== F. unknown location */

    public function test_unresolved_location_is_not_silently_assigned_to_actor_scope(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        // Row 1: genuinely in-scope. Row 2: blank location (column B empty)
        // — an existing, unrelated validation error, not a scope violation.
        $this->upload([
            $this->rowFor('02', '580'),
            $this->rowFor('02', '581', ['B' => '']),
        ])->assertCreated();

        $batch = $this->latestBatchFor($actor);
        // NOT scope-rejected — the blank-location row was never a candidate
        // for "out of scope" in the first place, it's simply invalid.
        $this->assertSame('validated', $batch->status);

        $blankRow = ImportRow::where('import_batch_id', $batch->id)->where('sequence_no', '581')->first();
        $this->assertSame('error', $blankRow->validation_status);
    }

    public function test_unknown_location_row_never_becomes_promotable_for_unit_admin(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $response = $this->upload([
            $this->rowFor('02', '582'),
            $this->rowFor('02', '583', ['B' => '']),
        ])->assertCreated();
        $batchId = $response->json('data.id');

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $this->assertSame(1, Asset::where('sequence_no', '582')->count());
        $this->assertSame(0, Asset::where('sequence_no', '583')->count());
    }

    /* ================================================================== G. duplicate safety */

    public function test_in_scope_duplicate_preserves_existing_duplicate_behaviour(): void
    {
        $this->seedUnitLocations();
        $this->ensureMasterData();
        $existing = Asset::factory()->identity('02', self::CATEGORY, self::SUBCATEGORY, '590', 2020)->create();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([$this->rowFor('02', '590', ['F' => '2020'])])->assertCreated();

        $batch = $this->latestBatchFor($actor);
        $row = ImportRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('error', $row->validation_status);
        $this->assertSame($existing->id, $row->duplicate_of_asset_id);
    }

    public function test_soft_deleted_duplicate_remains_protected_for_unit_admin(): void
    {
        $this->seedUnitLocations();
        $this->ensureMasterData();
        $existing = Asset::factory()->identity('02', self::CATEGORY, self::SUBCATEGORY, '591', 2020)->create();
        $existing->delete();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([$this->rowFor('02', '591', ['F' => '2020'])])->assertCreated();

        $batch = $this->latestBatchFor($actor);
        $row = ImportRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('error', $row->validation_status);
        $this->assertSame($existing->id, $row->duplicate_of_asset_id);
    }

    public function test_cross_scope_duplicate_does_not_leak_internal_asset_identity(): void
    {
        $this->seedUnitLocations();
        $this->ensureMasterData();
        $foreignExisting = Asset::factory()->identity('03', self::CATEGORY, self::SUBCATEGORY, '592', 2020)->create();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);

        $this->upload([
            $this->rowFor('02', '593'),
            $this->rowFor('03', '592', ['F' => '2020']), // collides with $foreignExisting, but out of scope
        ])->assertStatus(403);

        $batch = $this->latestBatchFor($actor);
        $foreignRow = ImportRow::where('import_batch_id', $batch->id)->where('sequence_no', '592')->firstOrFail();

        // Redacted: no cross-unit asset id exposed, and the message text
        // itself no longer embeds it.
        $this->assertNull($foreignRow->duplicate_of_asset_id);
        $messages = $foreignRow->validation_messages;
        $this->assertStringNotContainsString((string) $foreignExisting->id, json_encode($messages));
        $this->assertTrue(collect($messages)->contains(fn ($m) => $m['code'] === 'location_out_of_scope'));
    }

    /* ================================================================== H. atomicity */

    public function test_mixed_location_import_promotes_zero_assets(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);
        $countBefore = Asset::count();

        $this->upload([$this->rowFor('02', '600'), $this->rowFor('03', '601')])->assertStatus(403);
        $batch = $this->latestBatchFor($actor);
        $this->postJson("/api/imports/{$batch->id}/promote")->assertStatus(422);

        $this->assertSame($countBefore, Asset::count());
    }

    public function test_no_mutation_logs_created_for_a_rejected_import(): void
    {
        $this->seedUnitLocations();
        $actor = $this->unitAdmin('02');
        Sanctum::actingAs($actor);
        $mutationsBefore = MutationLog::count();

        $this->upload([$this->rowFor('02', '602'), $this->rowFor('03', '603')])->assertStatus(403);

        $this->assertSame($mutationsBefore, MutationLog::count());
    }

    public function test_no_partial_promotion_when_a_different_actor_scope_check_fails(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $response = $this->upload([
            $this->rowFor('02', '610'),
            $this->rowFor('03', '611'),
            $this->rowFor('04', '612'),
        ])->assertCreated();
        $batchId = $response->json('data.id');

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->postJson("/api/imports/{$batchId}/promote")->assertStatus(403);

        $this->assertSame(0, Asset::whereIn('sequence_no', ['610', '611', '612'])->count());
    }

    /* ================================================================== I. regression */

    public function test_global_operator_import_workflow_unchanged(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $response = $this->upload([$this->rowFor('02', '620'), $this->rowFor('03', '621')])->assertCreated();
        $batchId = $response->json('data.id');

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();
        $this->assertSame(2, Asset::whereIn('sequence_no', ['620', '621'])->count());
    }

    public function test_super_admin_import_round_trip_works(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $response = $this->upload([$this->rowFor('02', '622')])->assertCreated();
        $this->postJson("/api/imports/{$response->json('data.id')}/promote")->assertOk();
        $this->assertSame(1, Asset::where('sequence_no', '622')->count());
    }

    public function test_legacy_admin_import_round_trip_works(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $response = $this->upload([$this->rowFor('02', '623')])->assertCreated();
        $this->postJson("/api/imports/{$response->json('data.id')}/promote")->assertOk();
        $this->assertSame(1, Asset::where('sequence_no', '623')->count());
    }

    public function test_viewer_still_blocked_from_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->upload([$this->rowFor('02', '624')])->assertStatus(403);
    }

    /* ================================================================== K. fail-safe */

    public function test_invalid_unit_admin_state_never_becomes_global_on_import(): void
    {
        $this->seedUnitLocations();
        $this->ensureLocation('01');
        // Bypasses app-level validation on purpose — simulates a corrupt/legacy row.
        $corrupt = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '01']);
        Sanctum::actingAs($corrupt);

        $this->upload([$this->rowFor('02', '630')])->assertStatus(403);
        $this->assertSame(0, Asset::where('sequence_no', '630')->count());
    }

    /* ================================================================== J. ability boundaries (not broadened by import) */

    public function test_unit_admin_still_cannot_export_after_gaining_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/assets/export')->assertStatus(403);
    }

    public function test_unit_admin_still_cannot_manage_rooms_after_gaining_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'New Room'])->assertStatus(403);
    }
}
