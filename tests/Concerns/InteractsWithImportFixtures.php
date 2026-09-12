<?php

namespace Tests\Concerns;

use App\Import\Excel\SheetScanner;
use App\Services\Import\ImportTemplateService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds minimal, real `.xlsx` files that {@see SheetScanner}
 * actually accepts — same structural contract {@see ImportTemplateService}
 * documents (KODE BARANG metadata row drives category detection; "No. Urut" header
 * anchor; data starts at headerRow+2). Used by the Tahap 6.1 HTTP feature tests to
 * exercise a REAL upload -> stage -> promote round trip, not a mocked one.
 */
trait InteractsWithImportFixtures
{
    /**
     * @param  list<array<string,string>>  $rows  each row's cells keyed by column letter (B..T)
     */
    protected function makeImportUpload(string $categoryCode, array $rows, string $filename = 'test-import.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DATA');

        // KODE BARANG metadata row — required, drives category-column-map selection.
        $sheet->setCellValue('A4', $categoryCode);
        $sheet->setCellValue('B4', 'KODE BARANG');

        // Header anchor row + the mandatory (content-irrelevant) row after it.
        $headerRow = 6;
        $sheet->setCellValue("A{$headerRow}", 'No. Urut');
        $sheet->setCellValue("B{$headerRow}", 'NOMOR ASET');

        $dataStart = $headerRow + 2;
        foreach ($rows as $i => $cells) {
            $r = $dataStart + $i;
            $sheet->setCellValue("A{$r}", $i + 1);
            foreach ($cells as $col => $value) {
                $sheet->setCellValue("{$col}{$r}", $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import-test-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** One data row's cells for scope (location 01 / category 02 / subcategory 001). */
    protected function validImportRowCells(array $overrides = []): array
    {
        return array_merge([
            'B' => '01', 'C' => '02', 'D' => '001', 'E' => '001', 'F' => '2020',
            'G' => 'Test Brand', 'H' => 'SN-1', 'I' => 'Kayu', 'K' => '1', 'L' => 'v',
            'O' => 'KEUANGAN',
        ], $overrides);
    }

    /** A workbook that is a real file but not a valid xlsx structure at all. */
    protected function makeMalformedUpload(string $filename = 'malformed.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-bad-').'.xlsx';
        file_put_contents($path, 'this is not a spreadsheet');

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** A workbook with NO recognisable header row at all — "missing required headers". */
    protected function makeNoHeaderUpload(string $filename = 'no-header.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Just some random text');
        $sheet->setCellValue('B1', 'Nothing importable here');

        $path = tempnam(sys_get_temp_dir(), 'import-noheader-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
