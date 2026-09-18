<?php

namespace Tests\Feature\Api;

use App\Import\ImportManager;
use App\Import\Promotion\AssetPromoter;
use App\Models\Asset;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.1 — `POST /api/imports`: upload + stage ONLY. The single rule this whole
 * file exists to prove: uploading NEVER creates a final asset — only
 * {@see AssetPromoter} (a separate, explicit step) does that.
 * Everything else (parsing, validation, duplicate detection) is exercised through
 * the real {@see ImportManager}, never re-implemented here.
 */
class ImportUploadTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    public function test_valid_upload_creates_an_import_batch(): void
    {
        $this->scope('01', '02', '001');
        $this->room('01', ['name' => 'KEUANGAN']); // so the default row's O=KEUANGAN maps to a real room
        Sanctum::actingAs($this->operator());
        $upload = $this->makeImportUpload('02', [$this->validImportRowCells()], 'Inventaris Meubelair.xlsx');

        $response = $this->post('/api/imports', ['file' => $upload])->assertCreated();

        $batchId = $response->json('data.id');
        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'source_filename' => 'Inventaris Meubelair.xlsx',
            'category_code' => '02',
            'total_rows' => 1,
            'valid_rows' => 1,
        ]);
        $response->assertJsonPath('data.status', 'validated');
    }

    public function test_upload_stages_rows_without_creating_final_assets(): void
    {
        $this->scope('01', '02', '001');
        Sanctum::actingAs($this->operator());
        $upload = $this->makeImportUpload('02', [
            $this->validImportRowCells(['E' => '001']),
            $this->validImportRowCells(['E' => '002']),
        ]);

        $assetsBefore = Asset::count();
        $this->post('/api/imports', ['file' => $upload])->assertCreated();

        $this->assertSame($assetsBefore, Asset::count());
        $this->assertSame(2, ImportRow::count());
        $this->assertSame(0, ImportRow::whereNotNull('promoted_asset_id')->count());
    }

    public function test_invalid_file_extension_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $upload = UploadedFile::fake()->create('not-excel.txt', 10, 'text/plain');

        $this->post('/api/imports', ['file' => $upload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ImportBatch::count());
    }

    public function test_missing_file_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->post('/api/imports', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_malformed_xlsx_is_rejected_cleanly(): void
    {
        Sanctum::actingAs($this->operator());
        $upload = $this->makeMalformedUpload();

        $response = $this->post('/api/imports', ['file' => $upload]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
        // no server internals (paths, stack traces) leak into the error message
        $this->assertStringNotContainsString(sys_get_temp_dir(), (string) $response->json('errors.file.0'));
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_missing_required_headers_is_rejected_cleanly(): void
    {
        Sanctum::actingAs($this->operator());
        $upload = $this->makeNoHeaderUpload();

        $this->post('/api/imports', ['file' => $upload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ImportBatch::count());
    }

    public function test_empty_template_with_zero_data_rows_is_rejected_cleanly(): void
    {
        Sanctum::actingAs($this->operator());
        $upload = $this->makeImportUpload('02', []); // header only, matches the real template

        $this->post('/api/imports', ['file' => $upload])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ImportBatch::count());
    }

    public function test_viewer_cannot_upload(): void
    {
        Sanctum::actingAs($this->viewer());
        $upload = $this->makeImportUpload('02', [$this->validImportRowCells()]);

        $this->post('/api/imports', ['file' => $upload])->assertStatus(403);
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_uploaded_file_is_stored_privately_not_publicly(): void
    {
        $this->scope('01', '02', '001');
        Sanctum::actingAs($this->operator());
        $upload = $this->makeImportUpload('02', [$this->validImportRowCells()]);

        Storage::fake('local');
        $this->post('/api/imports', ['file' => $upload])->assertCreated();

        Storage::disk('local')->assertExists(
            collect(Storage::disk('local')->allFiles('imports'))->first()
        );
    }

    /**
     * Tahap 6.9 R8.2 (P3-3) — regression lock: the pre-existing RuntimeException
     * cleanup branch in ImportController::store() (nothing worth auditing when
     * staging never produced a batch) must still remove the per-upload directory
     * exactly as before, unaffected by the new sibling catch(\Throwable) branch
     * added alongside it for non-RuntimeException failures.
     */
    public function test_malformed_upload_directory_is_removed_not_left_behind(): void
    {
        Sanctum::actingAs($this->operator());
        $upload = $this->makeMalformedUpload();

        Storage::fake('local');
        $this->post('/api/imports', ['file' => $upload])->assertStatus(422);

        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }
}
