<?php

namespace Tests\Feature\Api;

use App\Import\ImportManager;
use App\Import\Validation\RowValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.1 — preview summary (`GET /api/imports/{batch}`) and row-level listing
 * (`GET /api/imports/{batch}/rows`). Every count and status here is read straight
 * off `import_batches` / `import_rows` — the SAME columns
 * {@see ImportManager} and {@see RowValidator}
 * already write. Valid / warning / error semantics are exercised, never redefined.
 */
class ImportPreviewTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    private function stage(array $rows, string $category = '02'): int
    {
        $this->scope('01', $category, '001');
        $this->room('01', ['name' => 'KEUANGAN']); // so O=KEUANGAN rows map to a real room -> genuinely "valid"
        $upload = $this->makeImportUpload($category, $rows);

        return app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];
    }

    public function test_preview_summary_counts_match_the_batch_composition(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '001']),                 // valid
            $this->validImportRowCells(['E' => '002', 'O' => 'MARS']),  // warning: room unmapped
            $this->validImportRowCells(['E' => '', 'O' => 'KEUANGAN']), // error: missing sequence
        ]);
        Sanctum::actingAs($this->operator());

        $response = $this->getJson("/api/imports/{$batchId}")->assertOk();

        $response->assertJsonPath('data.total_rows', 3);
        $response->assertJsonPath('data.valid_rows', 1);
        $response->assertJsonPath('data.warning_rows', 1);
        $response->assertJsonPath('data.error_rows', 1);
        $response->assertJsonPath('data.imported_rows', 0);
        $response->assertJsonPath('data.status', 'validated');
    }

    public function test_warning_rows_are_still_flagged_promotable_by_the_existing_semantics(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '050', 'O' => 'PLANET MARS']),
        ]);
        Sanctum::actingAs($this->operator());

        $row = $this->getJson("/api/imports/{$batchId}/rows")->assertOk()->json('data.0');

        $this->assertSame('warning', $row['validation_status']);
        $this->assertContains('room_unmapped', array_column($row['validation_messages'], 'code'));
        $this->assertFalse($row['is_duplicate']);
    }

    public function test_error_rows_are_never_promotable(): void
    {
        // batch category '02' is seeded (valid), but the ROW's own column C points
        // at a category that was never created — invalid_category, regardless of
        // the batch's own KODE BARANG metadata.
        $batchId = $this->stage([
            $this->validImportRowCells(['C' => '99']),
        ], '02');
        Sanctum::actingAs($this->operator());

        $row = $this->getJson("/api/imports/{$batchId}/rows")->assertOk()->json('data.0');

        $this->assertSame('error', $row['validation_status']);
        $this->assertContains('invalid_category', array_column($row['validation_messages'], 'code'));
    }

    public function test_rows_can_be_filtered_by_status(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '001']),
            $this->validImportRowCells(['E' => '002', 'O' => 'MARS']),
            $this->validImportRowCells(['E' => '']),
        ]);
        Sanctum::actingAs($this->operator());

        $valid = $this->getJson("/api/imports/{$batchId}/rows?status=valid")->assertOk();
        $warning = $this->getJson("/api/imports/{$batchId}/rows?status=warning")->assertOk();
        $error = $this->getJson("/api/imports/{$batchId}/rows?status=error")->assertOk();

        $this->assertCount(1, $valid->json('data'));
        $this->assertCount(1, $warning->json('data'));
        $this->assertCount(1, $error->json('data'));
    }

    public function test_rows_can_be_filtered_by_duplicate_even_though_it_is_not_a_real_status_column(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '900']),
            $this->validImportRowCells(['E' => '900']), // duplicate identity within the same batch
        ]);
        Sanctum::actingAs($this->operator());

        $response = $this->getJson("/api/imports/{$batchId}/rows?status=duplicate")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('data.0.is_duplicate'));
        $this->assertSame('error', $response->json('data.0.validation_status'));
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $batchId = $this->stage([$this->validImportRowCells()]);
        Sanctum::actingAs($this->operator());

        $this->getJson("/api/imports/{$batchId}/rows?status=bogus")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_rows_are_paginated(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->validImportRowCells(['E' => str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }
        $batchId = $this->stage($rows);
        Sanctum::actingAs($this->operator());

        $response = $this->getJson("/api/imports/{$batchId}/rows?per_page=10")->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(25, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_rows_are_ordered_by_row_number(): void
    {
        $batchId = $this->stage([
            $this->validImportRowCells(['E' => '003']),
            $this->validImportRowCells(['E' => '001']),
            $this->validImportRowCells(['E' => '002']),
        ]);
        Sanctum::actingAs($this->operator());

        $sequences = $this->getJson("/api/imports/{$batchId}/rows")->json('data.*.row_number');

        $this->assertSame($sequences, collect($sequences)->sort()->values()->all());
    }
}
