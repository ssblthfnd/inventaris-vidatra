<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Models\Asset;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MutationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;
use Tests\Unit\Asset\AssetExportServiceTest;

/**
 * Tahap 6.2 — `GET /api/assets/export`. This endpoint reuses the EXACT filter
 * vocabulary and query-building trait `GET /api/assets` uses
 * ({@see AssetIndexRequest}, {@see FiltersAssets}) —
 * these tests prove the HTTP contract (auth, filter application, response
 * headers, DB safety), not the workbook's internal structure — that is
 * {@see AssetExportServiceTest}'s job.
 */
class AssetExportTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private function saveResponseAsWorkbook(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'export-test-').'.xlsx';
        file_put_contents($path, $content);
        $spreadsheet = IOFactory::load($path);
        unlink($path);

        return $spreadsheet;
    }

    /* ------------------------------------------------------------------ authorization */

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/assets/export')->assertStatus(401);
    }

    public function test_viewer_is_forbidden(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/assets/export')->assertStatus(403);
    }

    public function test_inactive_operator_is_forbidden(): void
    {
        Sanctum::actingAs($this->operator(active: false));

        $this->getJson('/api/assets/export')->assertStatus(403);
    }

    public function test_operator_can_export(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $response = $this->get('/api/assets/export')->assertOk();

        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent());
    }

    public function test_admin_can_export(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->admin());

        $this->get('/api/assets/export')->assertOk();
    }

    /* ------------------------------------------------------------------ filter reuse */

    public function test_export_with_no_filter_includes_every_asset(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        Category::query()->where('code', '02')->update(['name' => 'MEUBELAIR']);
        Category::query()->where('code', '03')->update(['name' => 'ELEKTRONIK']);
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('002', 2020, [], '01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '03', '001');
        Sanctum::actingAs($this->operator());

        $response = $this->get('/api/assets/export')->assertOk();
        $spreadsheet = $this->saveResponseAsWorkbook($response->getContent());

        $this->assertSame(['02 MEUBELAIR', '03 ELEKTRONIK'], array_map(
            fn ($s) => $s->getTitle(),
            iterator_to_array($spreadsheet->getWorksheetIterator())
        ));
        $this->assertSame(2, $spreadsheet->getSheetByName('02 MEUBELAIR')->getHighestDataRow() - 7);
        $this->assertSame(1, $spreadsheet->getSheetByName('03 ELEKTRONIK')->getHighestDataRow() - 7);
    }

    public function test_export_respects_category_filter_exactly_like_the_inventory_endpoint(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        Category::query()->where('code', '02')->update(['name' => 'MEUBELAIR']);
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '03', '001');
        Sanctum::actingAs($this->operator());

        $response = $this->get('/api/assets/export?category_code[]=02')->assertOk();
        $spreadsheet = $this->saveResponseAsWorkbook($response->getContent());

        $titles = array_map(fn ($s) => $s->getTitle(), iterator_to_array($spreadsheet->getWorksheetIterator()));
        $this->assertSame(['02 MEUBELAIR'], $titles);
    }

    public function test_export_respects_the_search_filter(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, ['brand_model' => 'Canon Printer'], '01', '02', '001');
        $this->existingAsset('002', 2020, ['brand_model' => 'Modera Table'], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $response = $this->get('/api/assets/export?q=canon')->assertOk();
        $spreadsheet = $this->saveResponseAsWorkbook($response->getContent());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(1, $sheet->getHighestDataRow() - 7);
        $this->assertSame('Canon Printer', $sheet->getCell('G8')->getValue());
    }

    public function test_export_respects_room_filter(): void
    {
        $roomA = $this->room('01', ['name' => 'Ruangan A']);
        $roomB = $this->room('01', ['name' => 'Ruangan B']);
        $this->existingAsset('001', 2020, ['room_id' => $roomA->id], '01', '02', '001');
        $this->existingAsset('002', 2020, ['room_id' => $roomB->id], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $response = $this->get("/api/assets/export?room_id[]={$roomA->id}")->assertOk();
        $spreadsheet = $this->saveResponseAsWorkbook($response->getContent());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(1, $sheet->getHighestDataRow() - 7);
        $this->assertSame('Ruangan A', $sheet->getCell('O8')->getValue());
    }

    public function test_export_never_returns_soft_deleted_assets(): void
    {
        $this->scope('01', '02', '001');
        $active = $this->existingAsset('001', 2020, [], '01', '02', '001');
        $trashed = $this->existingAsset('002', 2020, [], '01', '02', '001');
        $trashed->delete();
        Sanctum::actingAs($this->operator());

        $response = $this->get('/api/assets/export')->assertOk();
        $spreadsheet = $this->saveResponseAsWorkbook($response->getContent());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(1, $sheet->getHighestDataRow() - 7);
        $this->assertSame($active->sequence_no, $sheet->getCell('E8')->getValue());
    }

    public function test_invalid_filter_is_rejected_exactly_like_the_inventory_endpoint(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/assets/export?category_code[]=99')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_code');
    }

    /* ------------------------------------------------------------------ read-only / DB safety */

    public function test_export_never_mutates_the_database(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $before = [
            'assets' => Asset::count(),
            'import_batches' => ImportBatch::count(),
            'import_rows' => ImportRow::count(),
            'mutation_logs' => MutationLog::count(),
        ];

        $this->get('/api/assets/export')->assertOk();
        $this->get('/api/assets/export?category_code[]=02')->assertOk();

        $after = [
            'assets' => Asset::count(),
            'import_batches' => ImportBatch::count(),
            'import_rows' => ImportRow::count(),
            'mutation_logs' => MutationLog::count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_source_excel_files_on_disk_are_never_touched(): void
    {
        $path = base_path('data/excel/Inventaris Meubelair.xlsx');
        $before = filemtime($path);

        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());
        $this->get('/api/assets/export')->assertOk();

        clearstatcache(true, $path);
        $this->assertSame($before, filemtime($path));
    }

    /**
     * Tahap 6.9 R8.2 (P3-3) — the `tempnam()`'d workbook is removed on the normal
     * success path (guaranteed via `finally`, not just a bare `unlink()` call at
     * the end of the method — see AssetExportController). This proves the
     * success-path half of that guarantee end-to-end over real HTTP.
     */
    public function test_no_leftover_temp_file_after_a_successful_export(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'asset-export-*') ?: [];
        $this->get('/api/assets/export')->assertOk();
        $after = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'asset-export-*') ?: [];

        $this->assertSame($before, $after, 'A temp export file was left behind after a successful request.');
    }
}
