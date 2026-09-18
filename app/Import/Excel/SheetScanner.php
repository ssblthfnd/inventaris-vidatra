<?php

namespace App\Import\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use RuntimeException;

/**
 * Loads an actual `.xlsx` inventory workbook and yields its rows in a structured form.
 *
 * Nothing about the layout is assumed from the schema document (Tahap 4 §2): the
 * metadata rows (LOKASI / KODE BARANG) and the header row ("No. Urut" / "NOMOR ASET")
 * are DETECTED, because the three workbooks put the header on different rows
 * (Meubelair/Elektronik row 12, Alat Kebersihan row 9).
 *
 * File handling (Tahap 4 §43): the file must exist, end in `.xlsx`, and be identified
 * by PhpSpreadsheet as an Xlsx document. Formulas are evaluated by PhpSpreadsheet's
 * own calculation engine while reading (never as PHP code) via `getCalculatedValue()`
 * — the size/ratio/row guards below (Tahap 6.9 R8.2, P3-4/P3-5) bound the cost of
 * that evaluation and of the full-workbook parse for an untrusted upload, without
 * changing how any legitimate workbook is read. `Kartu Inventaris Ruangan.xlsx`
 * fails structure detection here and cannot be imported.
 */
final class SheetScanner
{
    private const LAST_COL = 'V'; // read A..V; data never extends past column T

    // Real source workbooks run ~250-400 data rows (see docs/schema_design.md /
    // project history) — this is a wide safety margin, not a realistic ceiling,
    // meant only to bound the cost of the per-cell formula-calculation loop below
    // against a crafted workbook that claims an enormous highest-row (P3-4).
    private const MAX_DATA_ROWS = 5000;

    // `.xlsx` is a zip container; PhpSpreadsheet fully decompresses it before this
    // class sees a single cell. These two guards run BEFORE that decompression,
    // using PHP's built-in ZipArchive (already a hard dependency of PhpSpreadsheet's
    // Xlsx reader — no new package) to reject an obvious zip-bomb-style upload
    // cheaply (P3-5). Genuine `.xlsx` files compress modestly (their content is
    // already-compact XML); both thresholds are set with wide headroom above that.
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024; // 100MB vs. the 10MB compressed upload cap (StoreImportRequest)

    private const MAX_COMPRESSION_RATIO = 100; // uncompressed:compressed

    public string $sheetName = '';
    public ?string $locationCode = null;
    public ?string $categoryCode = null;
    public ?string $categoryLabel = null;
    public ?string $locationLabel = null;
    public int $headerRow = 0;
    public int $firstDataRow = 0;
    public int $highestRow = 0;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return \Generator<int,ScannedRow>
     */
    public function rows(): \Generator
    {
        $this->assertReadableXlsx();

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(false); // need formatting to read merged block headers
            $spreadsheet = $reader->load($this->path);
        } catch (ReaderException $e) {
            throw new RuntimeException("Cannot read workbook [{$this->path}]: {$e->getMessage()}", previous: $e);
        }

        if ($spreadsheet->getSheetCount() !== 1) {
            // The three inventory workbooks each have exactly one sheet.
            // Fall through with the active sheet but record the anomaly via caller-visible state.
        }

        $sheet = $spreadsheet->getActiveSheet();
        $this->sheetName = $sheet->getTitle();
        $this->highestRow = $sheet->getHighestDataRow();

        if ($this->highestRow > self::MAX_DATA_ROWS) {
            $spreadsheet->disconnectWorksheets();

            throw new RuntimeException(
                "Workbook [{$this->path}] reports {$this->highestRow} rows, exceeding the maximum of "
                .self::MAX_DATA_ROWS.' supported rows.'
            );
        }

        $lastColIndex = Coordinate::columnIndexFromString(self::LAST_COL);

        // --- pass 1: locate metadata + header row -------------------------------------
        for ($r = 1; $r <= min($this->highestRow, 40); $r++) {
            $a = $this->calc($sheet, 'A', $r);
            $b = $this->calc($sheet, 'B', $r);
            $g = $this->calc($sheet, 'G', $r);
            $bl = mb_strtoupper($b);

            if ($this->locationCode === null && str_contains($bl, 'LOKASI') && preg_match('/^\d{1,2}$/', $a) === 1) {
                $this->locationCode = str_pad($a, 2, '0', STR_PAD_LEFT);
                $this->locationLabel = $g !== '' ? $g : null;
            }
            if ($this->categoryCode === null && str_contains($bl, 'KODE BARANG') && preg_match('/^\d{1,2}$/', $a) === 1) {
                $this->categoryCode = str_pad($a, 2, '0', STR_PAD_LEFT);
                $this->categoryLabel = $g !== '' ? $g : null;
            }
            if ($this->headerRow === 0
                && (mb_strtoupper($a) === 'NO. URUT' || str_contains(mb_strtoupper($b), 'NOMOR ASET'))) {
                $this->headerRow = $r;
            }
        }

        if ($this->headerRow === 0) {
            throw new RuntimeException(
                "Workbook [{$this->path}] does not look like an inventory sheet "
                . '(no "No. Urut" / "NOMOR ASET" header row found).'
            );
        }

        // Header spans 2 rows (row N = labels, row N+1 = Baik/Kurang Baik/Rusak Berat).
        $this->firstDataRow = $this->headerRow + 2;

        // --- pass 2: yield data + block-header rows -----------------------------------
        $currentBlockCode = null;
        $currentBlockName = null;

        for ($r = $this->firstDataRow; $r <= $this->highestRow; $r++) {
            $cells = [];
            $formulas = [];
            for ($c = 1; $c <= $lastColIndex; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $value = $this->calc($sheet, $col, $r);
                if ($value !== '') {
                    $cells[$col] = $value;
                }
                $raw = $sheet->getCell($col . $r)->getValue();
                if (is_string($raw) && str_starts_with($raw, '=')) {
                    $formulas[$col] = $raw;
                }
            }

            $a = $cells['A'] ?? '';
            $b = $cells['B'] ?? '';
            $e = $cells['E'] ?? '';
            $o = $cells['O'] ?? '';

            $looksLikeData = $e !== ''
                || (($cells['B'] ?? '') !== '' && ($cells['C'] ?? '') !== '')
                || ($o !== '' && preg_match('/^0*\d{1,3}$/', $a) !== 1);

            $looksLikeBlockHeader = ! $looksLikeData
                && $b !== ''
                && preg_match('/^0*\d{1,3}$/', $a) === 1
                && ($cells['E'] ?? '') === ''
                && ($cells['F'] ?? '') === ''
                && ($cells['O'] ?? '') === '';

            if ($looksLikeBlockHeader) {
                $currentBlockCode = str_pad($a, 3, '0', STR_PAD_LEFT);
                $currentBlockName = $b;
                yield new ScannedRow($r, ScannedRow::TYPE_BLOCK_HEADER, $cells, $formulas, $currentBlockCode, $currentBlockName);
                continue;
            }

            if ($looksLikeData) {
                yield new ScannedRow($r, ScannedRow::TYPE_DATA, $cells, $formulas, $currentBlockCode, $currentBlockName);
                continue;
            }

            if ($cells !== []) {
                yield new ScannedRow($r, ScannedRow::TYPE_SKIPPED, $cells, $formulas, $currentBlockCode, $currentBlockName);
            }
        }

        $spreadsheet->disconnectWorksheets();
    }

    private function calc(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $col, int $row): string
    {
        try {
            $v = $sheet->getCell($col . $row)->getCalculatedValue();
        } catch (\Throwable) {
            $v = $sheet->getCell($col . $row)->getOldCalculatedValue();
        }
        if ($v === null || is_bool($v)) {
            return $v === null ? '' : ($v ? '1' : '0');
        }

        return trim((string) $v);
    }

    private function assertReadableXlsx(): void
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("File not found: {$this->path}");
        }
        if (strtolower(pathinfo($this->path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException("Only .xlsx files are accepted: {$this->path}");
        }
        $this->assertSafeZipContainer();
        try {
            $identified = IOFactory::identify($this->path);
        } catch (\Throwable $e) {
            throw new RuntimeException("Not a readable spreadsheet: {$this->path} ({$e->getMessage()})", previous: $e);
        }
        if ($identified !== 'Xlsx') {
            throw new RuntimeException("File is identified as [{$identified}], not Xlsx: {$this->path}");
        }
    }

    /**
     * Cheap pre-check (P3-5) run BEFORE PhpSpreadsheet ever opens the file: reads
     * each zip entry's own recorded compressed/uncompressed size (no decompression
     * performed here) and rejects an implausible total size or compression ratio.
     * A file that isn't a valid zip at all is deliberately left alone — the normal
     * `IOFactory::identify()` rejection right after this method returns already
     * produces the correct "not a readable spreadsheet" error for that case.
     */
    private function assertSafeZipContainer(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            // ext-zip is a hard dependency of PhpSpreadsheet's own Xlsx reader;
            // if it were somehow missing, that reader will fail on its own below.
            return;
        }

        $zip = new \ZipArchive;
        if ($zip->open($this->path) !== true) {
            return;
        }

        $totalUncompressed = 0;
        $totalCompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $totalUncompressed += (int) $stat['size'];
            $totalCompressed += (int) $stat['comp_size'];
        }
        $zip->close();

        if ($totalUncompressed > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException(
                "Workbook [{$this->path}] expands to over ".self::MAX_UNCOMPRESSED_BYTES.' bytes uncompressed and was rejected before parsing.'
            );
        }

        if ($totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > self::MAX_COMPRESSION_RATIO) {
            throw new RuntimeException(
                "Workbook [{$this->path}] has an abnormal compression ratio and was rejected before parsing."
            );
        }
    }
}
