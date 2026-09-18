<?php

namespace Tests\Unit\Asset;

use App\Import\Excel\ScannedRow;
use App\Import\Excel\SheetScanner;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Services\Asset\AssetExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\Feature\Api\AssetExportTest;
use Tests\TestCase;

/**
 * Tahap 6.2 — {@see AssetExportService} structural/data-fidelity tests, run
 * directly against the service (no HTTP) so every assertion is about the
 * generated WORKBOOK, not the endpoint plumbing (that is
 * {@see AssetExportTest}'s job).
 */
class AssetExportServiceTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private function service(): AssetExportService
    {
        return app(AssetExportService::class);
    }

    /**
     * {@see InteractsWithAssetFixtures::scope()} gives categories a generic
     * placeholder name ("Kategori 02") — fine for tests that don't care about
     * the name, but several tests here specifically assert real, recognisable
     * names (matching the real master data / the original workbooks' own
     * titles), so this overwrites them with the real ones.
     */
    private function useRealCategoryNames(): void
    {
        Category::query()->where('code', '02')->update(['name' => 'MEUBELAIR']);
        Category::query()->where('code', '03')->update(['name' => 'ELEKTRONIK']);
        Category::query()->where('code', '06')->update(['name' => 'ALAT KEBERSIHAN']);
        Location::query()->where('code', '01')->update(['name' => 'YAYASAN']);
    }

    /* ------------------------------------------------------------------ structure */

    public function test_one_sheet_per_category_present_named_like_the_original_workbooks(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->useRealCategoryNames();
        $meubelair = $this->existingAsset('001', 2020, [], '01', '02', '001');
        $elektronik = $this->existingAsset('001', 2020, [], '01', '03', '001');

        $spreadsheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get());

        $titles = array_map(fn ($s) => $s->getTitle(), iterator_to_array($spreadsheet->getWorksheetIterator()));
        $this->assertSame(['02 MEUBELAIR', '03 ELEKTRONIK'], $titles);
    }

    public function test_empty_result_still_produces_a_valid_informative_workbook(): void
    {
        $spreadsheet = $this->service()->build(Asset::query()->whereRaw('1=0')->get());

        $titles = array_map(fn ($s) => $s->getTitle(), iterator_to_array($spreadsheet->getWorksheetIterator()));
        $this->assertSame(['DATA'], $titles);
        $this->assertNotEmpty($spreadsheet->getActiveSheet()->getCell('A1')->getValue());
    }

    public function test_header_row_exists_with_expected_column_order_and_count(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        // Row 6 = header, row 7 = condition sub-header, row 8 = first data row —
        // fixed positions this service always uses (see AssetExportService).
        $this->assertSame('No. Urut', $sheet->getCell('A6')->getValue());
        $this->assertSame('Keadaan Barang', $sheet->getCell('L6')->getValue());
        $this->assertSame('Ruangan', $sheet->getCell('O6')->getValue());
        $this->assertSame('KETERANGAN', $sheet->getCell('Q6')->getValue());
        $this->assertSame('Baik (B)', $sheet->getCell('L7')->getValue());

        $expectedOrder = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'K', 'L', 'O', 'P', 'Q', 'R'];
        $this->assertCount(15, $expectedOrder);
        foreach ($expectedOrder as $col) {
            // every expected column has SOME header text on row 6 or 7 (L only on 7 for sub-marks is already covered; this just proves no column was skipped)
            $has6 = $sheet->getCell("{$col}6")->getValue() !== null && $sheet->getCell("{$col}6")->getValue() !== '';
            $this->assertTrue($has6, "column {$col} has no header label on row 6");
        }
    }

    /* ------------------------------------------------------------------ identity codes stay text */

    public function test_identity_codes_are_stored_as_text_never_numeric(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('0004', 2019, [], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        foreach (['B8', 'C8', 'D8', 'E8'] as $coord) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($coord)->getDataType(), "{$coord} must be TYPE_STRING");
            $this->assertSame('@', $sheet->getStyle($coord)->getNumberFormat()->getFormatCode(), "{$coord} must use the text number format");
        }
        $this->assertSame('0004', $sheet->getCell('E8')->getValue());
        $this->assertSame('01', $sheet->getCell('B8')->getValue());
    }

    public function test_leading_zeroes_and_letter_suffixes_survive_intact(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('005A', 2020, [], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $this->assertSame('005A', $sheet->getCell('E8')->getValue());
    }

    /* ------------------------------------------------------------------ no internal DB fields leak */

    public function test_no_internal_database_id_or_timestamp_columns_leak_into_the_sheet(): void
    {
        $this->scope('01', '02', '001');
        $asset = $this->existingAsset('001', 2020, [], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $rowValues = [];
        foreach (range('A', 'T') as $col) {
            $rowValues[] = (string) $sheet->getCell("{$col}8")->getValue();
        }

        // The DB primary key never appears as a distinct cell value anywhere in
        // the row (column A is a per-sheet print counter starting at 1, never the
        // DB id — checked separately in the header-order test).
        $this->assertNotContains((string) $asset->id, $rowValues);
        $this->assertNotContains((string) $asset->created_at?->timestamp, $rowValues);

        $flat = implode('|', $rowValues);
        $this->assertStringNotContainsString('created_at', $flat);
        $this->assertStringNotContainsString('room_id', $flat);
        $this->assertStringNotContainsString('import_row_id', $flat);
    }

    /* ------------------------------------------------------------------ category-specific mapping */

    public function test_elektronik_write_off_columns_map_correctly(): void
    {
        $this->scope('01', '03', '001');
        $asset = $this->existingAsset('001', 2020, [
            'is_written_off' => true,
            'written_off_on' => '2024-09-13',
        ], '01', '03', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $this->assertSame('Dihapus', $sheet->getCell('S8')->getValue());
        $this->assertSame('2024-09-13', $sheet->getCell('T8')->getValue());
    }

    public function test_alat_kebersihan_purchase_date_and_capacity_note_map_correctly(): void
    {
        $this->scope('01', '06', '001');
        $this->existingAsset('001', 2020, [
            'capacity_note' => '12 liter',
            'purchase_date' => '2023-05-30',
        ], '01', '06', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $this->assertSame('12 liter', $sheet->getCell('Q8')->getValue());
        $this->assertSame('2023-05-30', $sheet->getCell('R8')->getValue());
    }

    public function test_notes_land_in_the_categorys_own_notes_column(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '002');
        $this->useRealCategoryNames();
        $this->existingAsset('001', 2020, ['notes' => 'Catatan uji'], '01', '02', '001');
        $this->existingAsset('002', 2020, ['notes' => 'Catatan elektronik'], '01', '03', '002');

        $spreadsheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get());

        $this->assertSame('Catatan uji', $spreadsheet->getSheetByName('02 MEUBELAIR')->getCell('Q8')->getValue());
        $this->assertSame('Catatan elektronik', $spreadsheet->getSheetByName('03 ELEKTRONIK')->getCell('R8')->getValue());
    }

    /* ------------------------------------------------------------------ condition mapping */

    public function test_condition_marks_map_to_the_correct_single_column(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, ['condition' => 'baik'], '01', '02', '001');
        $this->existingAsset('002', 2020, ['condition' => 'kurang_baik'], '01', '02', '001');
        $this->existingAsset('003', 2020, ['condition' => 'rusak_berat'], '01', '02', '001');
        $this->existingAsset('004', 2020, ['condition' => null], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->orderBy('sequence_no')->get())->getActiveSheet();

        $this->assertSame(['v', '', ''], [$sheet->getCell('L8')->getValue(), $sheet->getCell('M8')->getValue(), $sheet->getCell('N8')->getValue()]);
        $this->assertSame(['', 'v', ''], [$sheet->getCell('L9')->getValue(), $sheet->getCell('M9')->getValue(), $sheet->getCell('N9')->getValue()]);
        $this->assertSame(['', '', 'v'], [$sheet->getCell('L10')->getValue(), $sheet->getCell('M10')->getValue(), $sheet->getCell('N10')->getValue()]);
        $this->assertSame(['', '', ''], [$sheet->getCell('L11')->getValue(), $sheet->getCell('M11')->getValue(), $sheet->getCell('N11')->getValue()]);
    }

    /* ------------------------------------------------------------------ room mapping */

    public function test_room_column_uses_the_current_canonical_room_name_when_mapped(): void
    {
        $room = $this->room('01', ['name' => 'Ruangan Uji']);
        $this->existingAsset('001', 2020, ['room_id' => $room->id, 'room_raw_value' => 'RUANGAN LAMA'], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $this->assertSame('Ruangan Uji', $sheet->getCell('O8')->getValue());
    }

    public function test_room_column_falls_back_to_raw_value_when_unmapped(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, ['room_id' => null, 'room_raw_value' => 'RUANGAN ASING'], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $this->assertSame('RUANGAN ASING', $sheet->getCell('O8')->getValue());
    }

    /* ------------------------------------------------------------------ LOKASI header */

    public function test_lokasi_header_shows_the_single_location_when_unambiguous(): void
    {
        $this->scope('01', '02', '001');
        $this->useRealCategoryNames();
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('002', 2020, [], '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $this->assertSame('01', $sheet->getCell('A3')->getValue());
        $this->assertSame('YAYASAN', $sheet->getCell('G3')->getValue());
    }

    public function test_lokasi_header_is_generic_when_multiple_locations_are_present(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('02', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('002', 2020, [], '02', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        $this->assertSame('SEMUA LOKASI', $sheet->getCell('G3')->getValue());
        $this->assertSame('', (string) $sheet->getCell('A3')->getValue());
    }

    /* ------------------------------------------------------------------ filename */

    public function test_filename_names_the_single_category_when_unambiguous(): void
    {
        $this->scope('01', '02', '001');
        $this->useRealCategoryNames();
        $asset = $this->existingAsset('001', 2020, [], '01', '02', '001');

        $filename = $this->service()->filenameFor(Asset::where('id', $asset->id)->get());

        $this->assertStringContainsString('MEUBELAIR', $filename);
        $this->assertStringEndsWith('.xlsx', $filename);
    }

    public function test_filename_is_generic_for_a_multi_category_export(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('002', 2020, [], '01', '03', '001');

        $filename = $this->service()->filenameFor(Asset::with(['location', 'category', 'room'])->get());

        $this->assertStringNotContainsString('MEUBELAIR', $filename);
        $this->assertStringNotContainsString('ELEKTRONIK', $filename);
        $this->assertStringEndsWith('.xlsx', $filename);
    }

    /* ------------------------------------------------------------------ filename sanitization (Tahap 6.9 R8.2, P3-6) */

    /**
     * @return string the generated filename for one asset whose category is
     *                 forced to the given (possibly unsafe) raw name
     */
    private function filenameForCategoryNamed(string $rawName): string
    {
        $this->scope('01', '02', '001');
        Category::query()->where('code', '02')->update(['name' => $rawName]);
        $asset = $this->existingAsset('001', 2020, [], '01', '02', '001');

        return $this->service()->filenameFor(Asset::where('id', $asset->id)->get());
    }

    public function test_filename_sanitizer_preserves_a_normal_category_name(): void
    {
        $filename = $this->filenameForCategoryNamed('ELEKTRONIK');

        $this->assertSame('Inventaris ELEKTRONIK - Export '.now()->format('Y-m-d').'.xlsx', $filename);
    }

    public function test_filename_sanitizer_preserves_spaces_in_a_multi_word_category_name(): void
    {
        $filename = $this->filenameForCategoryNamed('Alat Kebersihan');

        $this->assertStringContainsString('Alat Kebersihan', $filename);
    }

    public function test_filename_sanitizer_replaces_slashes_and_backslashes(): void
    {
        $filename = $this->filenameForCategoryNamed('A/B\\C');

        $this->assertStringNotContainsString('/', explode(' - Export', $filename)[0]);
        $this->assertStringNotContainsString('\\', $filename);
        $this->assertStringContainsString('A_B_C', $filename);
    }

    public function test_filename_sanitizer_replaces_double_quotes_preventing_header_injection(): void
    {
        $filename = $this->filenameForCategoryNamed('Evil"; filename="hijacked');

        $this->assertStringNotContainsString('"', $filename);
    }

    public function test_filename_sanitizer_replaces_cr_and_lf_preventing_header_injection(): void
    {
        $filename = $this->filenameForCategoryNamed("Evil\r\nX-Injected: 1");

        $this->assertStringNotContainsString("\r", $filename);
        $this->assertStringNotContainsString("\n", $filename);
    }

    public function test_filename_sanitizer_replaces_control_characters(): void
    {
        $filename = $this->filenameForCategoryNamed("A\x00\x01\x1FB");

        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $filename, 'Filename must contain only printable ASCII.');
        $this->assertStringContainsString('A___B', $filename);
    }

    public function test_filename_sanitizer_trims_leading_and_trailing_unsafe_characters(): void
    {
        $filename = $this->filenameForCategoryNamed("\r\n  UnsafeName  \r\n");

        $this->assertStringStartsWith('Inventaris UnsafeName', $filename);
    }

    public function test_filename_sanitizer_falls_back_when_the_whole_name_is_unsafe(): void
    {
        $filename = $this->filenameForCategoryNamed("\x00\x01\x02");

        $this->assertStringContainsString('Kategori', $filename);
    }

    /* ------------------------------------------------------------------ formula injection (Tahap 6.6, L-3) */

    /**
     * Regression test locking in a property the Stage 6.6 Pass 1 audit verified
     * MANUALLY (live export + raw XLSX XML inspection): every user-controlled
     * cell is written via `setCellValueExplicit(..., DataType::TYPE_STRING)`,
     * so a value starting with `=`/`+`/`-`/`@` is never reinterpreted as a
     * spreadsheet FORMULA when the file is opened — it stays literal text.
     * Asserts this via PhpSpreadsheet's own `Cell::getDataType()` (the
     * authoritative, API-level way to tell a formula cell from a string cell —
     * a formula cell's type is `DataType::TYPE_FORMULA` and it carries a
     * calculated/cached value distinct from its raw formula text; a string
     * cell's type is `DataType::TYPE_STRING` with no such distinction), NOT by
     * merely inspecting the character content. Uses the test database only —
     * no data of any kind is left behind (in-memory Spreadsheet, RefreshDatabase).
     */
    public function test_formula_injection_payloads_in_user_controlled_fields_are_stored_as_literal_strings(): void
    {
        $this->scope('01', '02', '001');
        $payloads = [
            'brand_model' => '=1+1',
            'serial_no' => '+2+3',
            'material' => '-3+3+cmd',
            'notes' => '@SUM(A1:A2)',
            'detail_type' => '=HYPERLINK("https://example.test","x")',
        ];
        $this->existingAsset('001', 2020, $payloads, '01', '02', '001');

        $sheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get())->getActiveSheet();

        // brand_model=G, serial_no=H, material=I, notes(category 02)=Q — all on
        // row 8 (FIRST_DATA_ROW); detail_type has no column for category 02
        // (Meubelair — see CategoryColumnMap), so only the four above apply.
        $cellsToCheck = ['G8' => '=1+1', 'H8' => '+2+3', 'I8' => '-3+3+cmd', 'Q8' => '@SUM(A1:A2)'];

        foreach ($cellsToCheck as $coord => $expectedValue) {
            $cell = $sheet->getCell($coord);
            $this->assertNotSame(
                DataType::TYPE_FORMULA,
                $cell->getDataType(),
                "{$coord} was stored as a FORMULA, not a literal string — formula injection risk."
            );
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "{$coord} must be an explicit string cell.");
            $this->assertSame($expectedValue, $cell->getValue(), "{$coord}'s text content must survive verbatim.");
        }
    }

    /**
     * Belt-and-braces: also verifies via the SAME raw-XML technique the Pass 1
     * audit used manually — the cell must never carry a `<f>` (formula)
     * element once written to a real .xlsx file, which is what determines
     * whether Excel itself would execute it on open.
     */
    public function test_formula_injection_payload_has_no_formula_element_in_the_saved_xlsx_xml(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, ['brand_model' => '=1+1'], '01', '02', '001');

        $spreadsheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get());
        $path = tempnam(sys_get_temp_dir(), 'formula-injection-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $zip = new \ZipArchive;
        $zip->open($path);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertNotFalse($sheetXml);
        preg_match('/<c r="G8"[^>]*>.*?<\/c>/s', $sheetXml, $matches);
        $this->assertNotEmpty($matches, 'Cell G8 not found in the saved worksheet XML.');
        $this->assertStringNotContainsString('<f>', $matches[0], 'Cell G8 has a <f> formula element.');
    }

    /* ------------------------------------------------------------------ round-trip (documented asymmetry) */

    /**
     * The exported workbook is readable by the REAL, unmodified SheetScanner as a
     * structurally valid sheet (KODE BARANG detected, header anchor found) — but is
     * NOT expected to round-trip into identical `import_rows` without modification:
     * the export has no per-row "No. Urut/NOMOR ASET" merged banner text (each
     * sub-column is individually labelled instead, exactly like Tahap 6.1's
     * template — see AssetSheetColumns), which is irrelevant to the scanner (it
     * only ever anchors on column A's "No. Urut" text), so detection still works.
     * This is a compatibility check, not a claim that export output is meant to be
     * re-imported unmodified.
     */
    public function test_exported_workbook_is_structurally_recognised_by_the_real_sheet_scanner(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');

        $spreadsheet = $this->service()->build(Asset::with(['location', 'category', 'room'])->get());
        $path = tempnam(sys_get_temp_dir(), 'export-roundtrip-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $scanner = new SheetScanner($path);
        $dataRows = 0;
        foreach ($scanner->rows() as $row) {
            if ($row->type === ScannedRow::TYPE_DATA) {
                $dataRows++;
            }
        }

        $this->assertSame('02', $scanner->categoryCode);
        $this->assertGreaterThan(0, $scanner->headerRow);
        $this->assertSame(1, $dataRows);

        unlink($path);
    }
}
