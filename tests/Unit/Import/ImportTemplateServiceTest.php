<?php

namespace Tests\Unit\Import;

use App\Import\Excel\ScannedRow;
use App\Import\Excel\SheetScanner;
use App\Import\ImportManager;
use App\Services\Import\ImportTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.1 — proves the generated template is actually compatible with the REAL,
 * unmodified {@see SheetScanner} — not just "looks right". Every workbook here is
 * built by {@see ImportTemplateService} and then re-opened through the exact class
 * `php artisan inventory:import` uses, closing the loop end-to-end.
 */
class ImportTemplateServiceTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The service reads the category row from master data to label the sheet —
        // seed all three known categories once so every test method below can call
        // generate() directly without repeating this.
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->scope('01', '06', '001');
    }

    /** @return array<string, array{0: string}> */
    public static function knownCategories(): array
    {
        return [
            '02 MEUBELAIR' => ['02'],
            '03 ELEKTRONIK' => ['03'],
            '06 ALAT KEBERSIHAN' => ['06'],
        ];
    }

    public function test_unknown_category_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ImportTemplateService::class)->generate('99');
    }

    public function test_category_without_a_column_map_is_rejected_even_though_it_exists_in_master_data(): void
    {
        // '01' TANAH DAN BANGUNAN is a real category but CategoryColumnMap has no
        // per-column mapping for it — the template must refuse rather than guess.
        $this->expectException(InvalidArgumentException::class);

        app(ImportTemplateService::class)->generate('01');
    }

    #[DataProvider('knownCategories')]
    public function test_generated_workbook_has_the_three_expected_sheets_with_data_active(string $category): void
    {
        $spreadsheet = app(ImportTemplateService::class)->generate($category);

        $titles = array_map(fn ($s) => $s->getTitle(), iterator_to_array($spreadsheet->getWorksheetIterator()));
        $this->assertSame(['DATA', 'PANDUAN', 'REFERENSI'], $titles);
        $this->assertSame('DATA', $spreadsheet->getActiveSheet()->getTitle());
    }

    #[DataProvider('knownCategories')]
    public function test_the_real_sheet_scanner_detects_the_correct_category_and_header_row(string $category): void
    {
        $path = $this->saveTemplate($category);
        $scanner = new SheetScanner($path);
        foreach ($scanner->rows() as $_) {
            // fully drain the generator so every scanner property is populated
        }

        $this->assertSame($category, $scanner->categoryCode);
        $this->assertGreaterThan(0, $scanner->headerRow);
        $this->assertSame($scanner->headerRow + 2, $scanner->firstDataRow);
    }

    #[DataProvider('knownCategories')]
    public function test_empty_template_has_no_data_rows_by_design(string $category): void
    {
        // DATA intentionally ships with headers only — no example row that could be
        // mistaken for real inventory (see the service's own class docblock).
        $path = $this->saveTemplate($category);
        $scanner = new SheetScanner($path);

        $dataRows = 0;
        foreach ($scanner->rows() as $row) {
            if ($row->type === ScannedRow::TYPE_DATA) {
                $dataRows++;
            }
        }

        $this->assertSame(0, $dataRows);
    }

    /**
     * A real data row typed in below the template's header, using the exact
     * documented column positions, must be parsed with the batch's own category —
     * proof the KODE BARANG row the template writes is read back correctly by
     * {@see ImportManager::stageFile()} (not just by SheetScanner alone).
     */
    #[DataProvider('knownCategories')]
    public function test_a_filled_row_below_the_template_header_stages_successfully(string $category): void
    {
        $this->scope('01', $category, '001');
        $path = $this->saveTemplate($category);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('DATA');
        $row = 8; // headerRow(6) + 2
        $cells = ['B' => '01', 'C' => $category, 'D' => '001', 'E' => '777', 'F' => '2021', 'K' => '1', 'L' => 'v', 'O' => 'KEUANGAN'];
        foreach ($cells as $col => $value) {
            $sheet->setCellValue("{$col}{$row}", $value);
        }
        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $summary = app(ImportManager::class)->stageFile($path);

        $this->assertSame(1, $summary['total']);
        $this->assertSame($category, $summary['category_code']);
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $summary['batch_id'],
            'category_code' => $category,
            'subcategory_code' => '001',
            'sequence_no' => '777',
            'asset_year' => 2021,
        ]);
    }

    private function saveTemplate(string $category): string
    {
        $spreadsheet = app(ImportTemplateService::class)->generate($category);
        $path = tempnam(sys_get_temp_dir(), 'import-template-test-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
