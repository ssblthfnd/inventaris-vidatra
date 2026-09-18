<?php

namespace Tests\Unit\Import;

use App\Import\Excel\SheetScanner;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Tahap 6.9 R8.2 (P3-4/P3-5) — resource-exhaustion hardening in
 * {@see SheetScanner}: a row-count ceiling (bounds the cost of the per-cell
 * formula-calculation loop) and a zip-container size/ratio guard (rejects an
 * obviously zip-bomb-shaped `.xlsx` before PhpSpreadsheet ever decompresses
 * it). Both are pure unit tests against the scanner directly — no HTTP, no DB
 * — since neither guard's behavior depends on the rest of the import pipeline.
 */
class SheetScannerHardeningTest extends TestCase
{
    /**
     * A workbook whose single populated cell sits far past MAX_DATA_ROWS —
     * PhpSpreadsheet's own `getHighestDataRow()` reports that row as the
     * highest, without needing to actually populate every row in between.
     */
    public function test_a_workbook_reporting_more_rows_than_the_maximum_is_rejected(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A4', '02');
        $sheet->setCellValue('B4', 'KODE BARANG');
        $sheet->setCellValue('A6', 'No. Urut');
        $sheet->setCellValue('B6', 'NOMOR ASET');
        $sheet->setCellValue('A6000', 'far past the row cap');

        $path = tempnam(sys_get_temp_dir(), 'sheetscanner-toolarge-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $scanner = new SheetScanner($path);

            $this->expectException(RuntimeException::class);
            foreach ($scanner->rows() as $row) {
                // draining the generator is what actually triggers the guard
            }
        } finally {
            unlink($path);
        }
    }

    /**
     * A normal, small workbook (well under the row cap) is completely
     * unaffected by the guard — proves the fix doesn't change legitimate
     * import behavior.
     */
    public function test_a_normal_small_workbook_is_not_affected_by_the_row_cap(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A4', '02');
        $sheet->setCellValue('B4', 'KODE BARANG');
        $sheet->setCellValue('A6', 'No. Urut');
        $sheet->setCellValue('B6', 'NOMOR ASET');
        $sheet->setCellValue('A8', 1);
        $sheet->setCellValue('B8', '01');
        $sheet->setCellValue('C8', '02');
        $sheet->setCellValue('E8', '001');

        $path = tempnam(sys_get_temp_dir(), 'sheetscanner-normal-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $scanner = new SheetScanner($path);
            $rows = iterator_to_array($scanner->rows());

            $this->assertNotEmpty($rows);
            $this->assertSame('02', $scanner->categoryCode);
        } finally {
            unlink($path);
        }
    }

    /**
     * Builds a real `.xlsx` (zip) whose single entry is a long, highly
     * repetitive string — deflate compresses this to a tiny fraction of its
     * real size, giving a genuine (not simulated) compression ratio well past
     * MAX_COMPRESSION_RATIO, the same shape a real zip-bomb payload takes.
     * 20MB is enough to trip the RATIO guard specifically while staying far
     * under the 100MB total-size guard, isolating which check fired.
     */
    public function test_a_workbook_with_an_extreme_compression_ratio_is_rejected_before_parsing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sheetscanner-zipbomb-').'.xlsx';

        $zip = new \ZipArchive;
        $opened = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Failed to open the test zip fixture.');
        $zip->addFromString('bomb.bin', str_repeat('A', 20 * 1024 * 1024));
        $zip->close();

        try {
            $scanner = new SheetScanner($path);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/compression ratio/');
            foreach ($scanner->rows() as $row) {
                // draining the generator triggers assertReadableXlsx()
            }
        } finally {
            unlink($path);
        }
    }

    /**
     * The malformed-file rejection path (an existing, already-tested behavior
     * — see ImportUploadTest::test_malformed_xlsx_is_rejected_cleanly at the
     * HTTP layer) must still work unchanged: a file that isn't a valid zip at
     * all is left alone by the new zip-safety guard (it returns early rather
     * than throwing) and is still rejected — just as before this change — by
     * the pre-existing `IOFactory::identify()` check right after it, whatever
     * exact (non-Xlsx) type PhpSpreadsheet's own heuristics assign it.
     */
    public function test_a_non_zip_file_is_still_rejected_cleanly_not_by_the_new_guard(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sheetscanner-notzip-').'.xlsx';
        file_put_contents($path, 'this is not a zip file at all');

        try {
            $scanner = new SheetScanner($path);

            $this->expectException(RuntimeException::class);
            foreach ($scanner->rows() as $row) {
                //
            }
        } finally {
            unlink($path);
        }
    }
}
