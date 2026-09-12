<?php

namespace Tests\Feature\Api;

use App\Import\ImportManager;
use App\Import\Promotion\AssetPromoter;
use App\Models\Asset;
use App\Models\ImportRow;
use App\Models\MutationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.1 — `POST /api/imports/{batch}/promote`. This controller action is a
 * one-line call to {@see ImportManager::promoteBatch()} -> the EXISTING
 * {@see AssetPromoter} — every behaviour proven here already
 * belongs to that class, not to anything new. `php artisan inventory:promote` and
 * this HTTP endpoint promote through the exact same code path.
 */
class ImportPromoteTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    private function stage(array $rows, string $category = '02'): int
    {
        $this->scope('01', $category, '001');
        $this->room('01', ['name' => 'KEUANGAN']);
        $upload = $this->makeImportUpload($category, $rows);

        return app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];
    }

    public function test_promotion_uses_the_existing_promoter_and_creates_real_assets(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '501']),
            $this->validImportRowCells(['E' => '502']),
        ]);
        Sanctum::actingAs($this->operator());

        $response = $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $response->assertJsonPath('promotion.promoted', 2);
        $response->assertJsonPath('data.imported_rows', 2);
        $response->assertJsonPath('data.status', 'imported');
        $this->assertDatabaseHas('assets', ['sequence_no' => '501', 'asset_year' => 2020]);
        $this->assertDatabaseHas('assets', ['sequence_no' => '502', 'asset_year' => 2020]);
    }

    public function test_promoted_asset_is_linked_back_to_its_import_row(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '510'])]);
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $row = ImportRow::where('import_batch_id', $batchId)->first();
        $asset = Asset::where('sequence_no', '510')->first();

        $this->assertNotNull($asset);
        $this->assertSame($asset->id, $row->fresh()->promoted_asset_id);
        $this->assertSame($row->id, $asset->import_row_id);
    }

    public function test_promotion_never_creates_mutation_logs(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '511'])]);
        Sanctum::actingAs($this->operator());
        $before = MutationLog::count();

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $this->assertSame($before, MutationLog::count());
    }

    public function test_error_rows_are_skipped_and_never_promoted(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '520']),
            $this->validImportRowCells(['E' => '']), // missing sequence -> error
        ]);
        Sanctum::actingAs($this->operator());

        $response = $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $response->assertJsonPath('promotion.promoted', 1);
        $this->assertSame(1, Asset::count());
    }

    public function test_repromoting_an_already_promoted_batch_is_idempotent(): void
    {
        $batchId = $this->stage([$this->validImportRowCells(['E' => '530'])]);
        Sanctum::actingAs($this->operator());
        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $second = $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $second->assertJsonPath('promotion.promoted', 0);
        $second->assertJsonPath('promotion.skipped_already', 1);
        $this->assertSame(1, Asset::count());
    }

    /**
     * Promotion atomicity is PER ROW, not per batch (each row is promoted inside
     * its own transaction — {@see AssetPromoter::promoteOne()}).
     * A single row failing its own DB constraint must not roll back — or block —
     * any other row in the same batch.
     */
    public function test_a_single_row_promotion_failure_does_not_affect_other_rows_in_the_batch(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '540']),
            $this->validImportRowCells(['E' => '541']),
        ]);

        // Corrupt row 541's staged payload so its own promotion violates a DB CHECK
        // constraint (asset_year out of range) without touching row 540 at all.
        $corrupt = ImportRow::where('import_batch_id', $batchId)->where('sequence_no', '541')->first();
        $payload = $corrupt->raw_payload;
        $payload['parsed']['asset_year'] = 3000;
        DB::table('import_rows')->where('id', $corrupt->id)->update(['raw_payload' => json_encode($payload)]);

        Sanctum::actingAs($this->operator());
        $response = $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $response->assertJsonPath('promotion.promoted', 1);
        $response->assertJsonPath('promotion.failed', 1);
        $this->assertDatabaseHas('assets', ['sequence_no' => '540']);
        $this->assertDatabaseMissing('assets', ['sequence_no' => '541']);
        $this->assertNull($corrupt->fresh()->promoted_asset_id);
    }

    public function test_duplicate_within_the_same_batch_promotes_only_the_first_occurrence(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '550']),
            $this->validImportRowCells(['E' => '550']),
        ]);
        Sanctum::actingAs($this->operator());

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $this->assertSame(1, Asset::where('sequence_no', '550')->count());
    }

    public function test_reimporting_an_existing_asset_never_creates_a_second_one(): void
    {
        $this->scope('01', '02', '001');
        $this->room('01', ['name' => 'KEUANGAN']);
        $existing = Asset::factory()->identity('01', '02', '001', '560', 2020)->create();
        // a second, unrelated row alongside the duplicate so the batch is not 100%
        // error (which would leave it in status "failed" and block promotion entirely
        // — a separate, already-covered rule, not what this test is about).
        $upload = $this->makeImportUpload('02', [
            $this->validImportRowCells(['E' => '560']),
            $this->validImportRowCells(['E' => '561']),
        ]);
        $batchId = app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];
        Sanctum::actingAs($this->operator());

        $rows = collect($this->getJson("/api/imports/{$batchId}/rows")->json('data'));
        $duplicateRow = $rows->firstWhere('sequence_no', '560');
        $this->assertSame('error', $duplicateRow['validation_status']);
        $this->assertSame($existing->id, $duplicateRow['duplicate_of_asset_id']);

        $this->postJson("/api/imports/{$batchId}/promote")->assertOk();

        $this->assertSame(1, Asset::where('sequence_no', '560')->where('asset_year', 2020)->count());
        $this->assertSame(1, Asset::where('sequence_no', '561')->count());
    }

    public function test_viewer_cannot_promote(): void
    {
        $batchId = $this->stage([$this->validImportRowCells()]);
        Sanctum::actingAs($this->viewer());

        $this->postJson("/api/imports/{$batchId}/promote")->assertStatus(403);
        $this->assertSame(0, Asset::count());
    }
}
