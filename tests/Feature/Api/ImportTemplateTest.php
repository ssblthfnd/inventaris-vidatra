<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\Import\ImportTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Concerns\MatchesTempnamFiles;
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
    use MatchesTempnamFiles;
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

    /**
     * Tahap 6.9 R8.2 (P3-3) — same guaranteed-cleanup contract as
     * AssetExportController's own test; see that file's comment.
     */
    public function test_no_leftover_temp_file_after_a_successful_download(): void
    {
        $this->scope('01', '02', '001');
        Sanctum::actingAs($this->operator());

        // the pattern the controller's tempnam(…, 'import-template-') really produces here
        $pattern = $this->tempnamGlob('import-template-');
        $before = glob($pattern) ?: [];
        $this->get('/api/imports/template?category=02')->assertOk();
        $after = glob($pattern) ?: [];

        // only files that APPEARED count (older leftovers of the same pattern may exist)
        $this->assertSame([], array_values(array_diff($after, $before)), 'A temp template file was left behind after a successful request.');
    }

    /* ------------------------------------------------------------------ REFERENSI room scope (R6 I6-01) */

    /**
     * Every "code|name" pair of the downloaded workbook's REFERENSI sheet (first two
     * columns), read back from the real response body.
     *
     * @return list<string>
     */
    private function referensiPairs(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl-scope-');
        @unlink($path); // tempnam()'s placeholder — only its unique name is used
        $path .= '.xlsx';

        try {
            file_put_contents($path, $xlsx);
            $spreadsheet = IOFactory::load($path);
            $rows = $spreadsheet->getSheetByName('REFERENSI')->toArray();
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        return array_map(fn (array $r): string => ($r[0] ?? '').'|'.($r[1] ?? ''), $rows);
    }

    private function roomsInEveryUnitLocation(): void
    {
        $this->scope('02', '02', '001');
        $this->scope('03', '02', '001');
        $this->scope('04', '02', '001');
        $this->room('02', ['name' => 'Ruang Unit Dua']);
        $this->room('03', ['name' => 'Ruang Unit Tiga']);
        $this->room('04', ['name' => 'Ruang Unit Empat']);
    }

    public function test_unit_admin_template_lists_only_its_own_locations_rooms(): void
    {
        $this->roomsInEveryUnitLocation();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']));

        $pairs = $this->referensiPairs($this->get('/api/imports/template?category=02')->assertOk()->getContent());

        $this->assertContains('02|Ruang Unit Dua', $pairs);
        $this->assertNotContains('03|Ruang Unit Tiga', $pairs);
        $this->assertNotContains('04|Ruang Unit Empat', $pairs);
        $this->assertEmpty(array_filter($pairs, fn (string $p): bool => str_contains($p, 'Ruang Unit Tiga') || str_contains($p, 'Ruang Unit Empat')));
        // the category's subcategories are listed exactly as before (not location-scoped)
        $this->assertContains('001|Subkategori 02-001', $pairs);
    }

    public function test_global_actor_template_still_lists_rooms_of_every_location(): void
    {
        $this->roomsInEveryUnitLocation();
        Sanctum::actingAs($this->admin());

        $pairs = $this->referensiPairs($this->get('/api/imports/template?category=02')->assertOk()->getContent());

        $this->assertContains('02|Ruang Unit Dua', $pairs);
        $this->assertContains('03|Ruang Unit Tiga', $pairs);
        $this->assertContains('04|Ruang Unit Empat', $pairs);
        $this->assertContains('001|Subkategori 02-001', $pairs);
    }
}
