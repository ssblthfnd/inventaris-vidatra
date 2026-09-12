<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\Import\ImportTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;
use Tests\Unit\Import\ImportTemplateServiceTest;

/**
 * Tahap 6.1 — `GET /api/imports/template`.
 *
 * {@see ImportTemplateService} only ever WRITES a fresh
 * in-memory workbook from read-only master-data queries; these tests prove that
 * contract at the HTTP layer (real Excel response, zero DB mutation), not the
 * workbook's internal layout — that is covered directly against the service in
 * {@see ImportTemplateServiceTest}.
 */
class ImportTemplateTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/imports/template?category=02')->assertStatus(401);
    }

    public function test_viewer_is_forbidden(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/imports/template?category=02')->assertStatus(403);
    }

    public function test_missing_category_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/imports/template')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_unknown_category_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/imports/template?category=99')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_category_01_is_rejected_no_column_map(): void
    {
        // '01' TANAH DAN BANGUNAN exists in the categories master but has no
        // CategoryColumnMap entry — the template must refuse it rather than guess.
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/imports/template?category=01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_operator_can_download_template_for_each_known_category(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->scope('01', '06', '001');
        Sanctum::actingAs($this->operator());

        foreach (['02', '03', '06'] as $category) {
            $response = $this->get("/api/imports/template?category={$category}")->assertOk();

            $response->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString($category, $response->headers->get('Content-Disposition'));
            // .xlsx is a zip container — real content always starts with the ZIP magic bytes.
            $this->assertStringStartsWith("PK\x03\x04", $response->getContent());
        }
    }

    public function test_admin_can_download_template(): void
    {
        $this->scope('01', '02', '001');
        Sanctum::actingAs($this->admin());

        $this->get('/api/imports/template?category=02')->assertOk();
    }

    public function test_template_download_does_not_mutate_the_database(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->scope('01', '06', '001');
        Sanctum::actingAs($this->operator());

        $before = [
            'assets' => Asset::count(),
            'import_batches' => ImportBatch::count(),
            'import_rows' => ImportRow::count(),
        ];

        $this->get('/api/imports/template?category=02')->assertOk();
        $this->get('/api/imports/template?category=03')->assertOk();
        $this->get('/api/imports/template?category=06')->assertOk();

        $after = [
            'assets' => Asset::count(),
            'import_batches' => ImportBatch::count(),
            'import_rows' => ImportRow::count(),
        ];

        $this->assertSame($before, $after);
    }
}
