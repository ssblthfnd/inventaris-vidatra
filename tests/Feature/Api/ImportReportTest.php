<?php

namespace Tests\Feature\Api;

use App\Import\ImportManager;
use App\Import\Reporting\ImportReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Concerns\InteractsWithImportFixtures;
use Tests\TestCase;

/**
 * Tahap 6.1 — `GET /api/imports/{batch}/report`. This endpoint is a direct,
 * unmodified read of {@see ImportReporter} — the SAME class
 * `php artisan inventory:report` uses. These tests prove the HTTP response's
 * numbers equal what the reporter itself returns, not a re-derived copy.
 */
class ImportReportTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use InteractsWithImportFixtures;
    use RefreshDatabase;

    public function test_report_matches_the_reporter_directly(): void
    {
        $this->scope('01', '02', '001');
        $upload = $this->makeImportUpload('02', [
            $this->validImportRowCells(['E' => '601']),
            $this->validImportRowCells(['E' => '602', 'O' => 'PLANET MARS']),
            $this->validImportRowCells(['E' => '']),
        ]);
        $batchId = app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];
        app(ImportManager::class)->promoteBatch($batchId);

        Sanctum::actingAs($this->operator());
        $response = $this->getJson("/api/imports/{$batchId}/report")->assertOk();

        $expected = new ImportReporter([$batchId]);
        $response->assertJsonPath('data.summary', $expected->promotionSummary());
        $this->assertSame($expected->dataQuality(), $response->json('data.data_quality'));
    }

    public function test_report_consistency_check_passes_for_a_clean_batch(): void
    {
        $this->scope('01', '02', '001');
        $upload = $this->makeImportUpload('02', [$this->validImportRowCells(['E' => '610'])]);
        $batchId = app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];
        app(ImportManager::class)->promoteBatch($batchId);

        Sanctum::actingAs($this->operator());
        $response = $this->getJson("/api/imports/{$batchId}/report")->assertOk();

        foreach ($response->json('data.consistency') as $check) {
            $this->assertTrue($check['ok'], $check['check'].' — '.$check['detail']);
        }
    }

    public function test_report_reflects_duplicates(): void
    {
        $this->scope('01', '02', '001');
        $upload = $this->makeImportUpload('02', [
            $this->validImportRowCells(['E' => '620']),
            $this->validImportRowCells(['E' => '620']),
        ]);
        $batchId = app(ImportManager::class)->stageFile($upload->getPathname())['batch_id'];

        Sanctum::actingAs($this->operator());
        $response = $this->getJson("/api/imports/{$batchId}/report")->assertOk();

        $this->assertCount(1, $response->json('data.duplicates.in_batch'));
    }
}
