<?php

namespace Tests\Concerns;

use App\Import\Excel\SheetScanner;
use App\Services\Import\ImportTemplateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds minimal, real `.xlsx` files that {@see SheetScanner}
 * actually accepts — same structural contract {@see ImportTemplateService}
 * documents (KODE BARANG metadata row drives category detection; "No. Urut" header
 * anchor; data starts at headerRow+2). Used by the Tahap 6.1 HTTP feature tests to
 * exercise a REAL upload -> stage -> promote round trip, not a mocked one.
 *
 * Filesystem isolation (Laravel runs `setUp{Trait}` / `tearDown{Trait}` for every
 * test using this trait): `POST /api/imports` stores the workbook on the `local`
 * disk (`imports/{uuid}/…`), so that disk is faked — nothing reaches the real
 * storage/app/private. The fixture files built here live in the system temp dir
 * and exactly those (no pattern, no directory) are removed after each test.
 */
trait InteractsWithImportFixtures
{
    /** @var list<string> temp files created by this trait in the current test */
    private array $importFixtureFiles = [];

    protected function setUpInteractsWithImportFixtures(): void
    {
        Storage::fake('local');
    }

    protected function tearDownInteractsWithImportFixtures(): void
    {
        foreach ($this->importFixtureFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->importFixtureFiles = [];
    }

    /** A fresh `….xlsx` path in the system temp dir; it and tempnam()'s own placeholder are cleaned up. */
    private function importFixturePath(string $prefix): string
    {
        $placeholder = tempnam(sys_get_temp_dir(), $prefix);
        $path = $placeholder.'.xlsx';
        array_push($this->importFixtureFiles, $placeholder, $path);

        return $path;
    }

    /**
     * @param  list<array<string,string>>  $rows  each row's cells keyed by column letter (B..U)
     * @param  bool  $withSourceColumn  Tahap 2 — write the `SUMBER` header in U (current
     *                                  template layout); false = historical layout
     */
    protected function makeImportUpload(string $categoryCode, array $rows, string $filename = 'test-import.xlsx', bool $withSourceColumn = false): UploadedFile
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
        if ($withSourceColumn) {
            $sheet->setCellValue("U{$headerRow}", 'SUMBER');
        }

        $dataStart = $headerRow + 2;
        foreach ($rows as $i => $cells) {
            $r = $dataStart + $i;
            $sheet->setCellValue("A{$r}", $i + 1);
            foreach ($cells as $col => $value) {
                $sheet->setCellValue("{$col}{$r}", $value);
            }
        }

        $path = $this->importFixturePath('import-test-');
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
        $path = $this->importFixturePath('import-bad-');
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

        $path = $this->importFixturePath('import-noheader-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
