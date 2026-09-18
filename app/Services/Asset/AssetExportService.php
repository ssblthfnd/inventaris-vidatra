<?php

namespace App\Services\Asset;

use App\Http\Controllers\Api\AssetExportController;
use App\Import\Parsing\CategoryColumnMap;
use App\Import\Parsing\RowParser;
use App\Models\Asset;
use App\Models\Category;
use App\Services\Excel\AssetSheetColumns;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds the Tahap 6.2 inventory export workbook — reproducing the STRUCTURE of the
 * real source workbooks (`data/excel/Inventaris {Meubelair,Elektronik,Alat
 * Kebersihan}.xlsx`: title row, LOKASI / KODE BARANG metadata rows, the same
 * two-row column header per category via {@see AssetSheetColumns}) filled with
 * CURRENT data straight from the database — never a copy of an old workbook, never
 * stale rows.
 *
 * One sheet per category present in the given asset collection (a filtered export
 * that happens to match only one category naturally produces a single-sheet
 * workbook; "export all" naturally produces one sheet per category, e.g.
 * "02 MEUBELAIR" / "03 ELEKTRONIK" / "06 ALAT KEBERSIHAN" — matching the real
 * sheet titles) — see the class docblock in {@see AssetExportController}
 * for why this beats either forcing every category into one flat table or writing
 * three separate files for a single "export all" click.
 *
 * Deliberate, disclosed differences from the historical workbooks (see Tahap 6.2
 * final report for the full reasoning):
 *  - No "JENIS BARANG" subcategory legend block (rows 5-10ish in the originals):
 *    that legend's exact column arrangement is hand-laid-out per file and not
 *    derivable from any rule — reproducing it precisely would mean hard-coding a
 *    layout instead of generating one, which is the same problem this whole
 *    export exists to avoid at the DATA level. It carries no identity information
 *    (a row's own subcategory code already lives in column D).
 *  - Columns B/C/D/E/F are plain values, not formulas referencing the metadata
 *    rows — a filtered/mixed export has no single implied location, and
 *    formula-driven cells would silently show wrong values the moment a workbook
 *    like that is filtered by more than one location.
 *  - Column P ("Ket. Mutasi dll") is always blank: the importer already merges P
 *    into `assets.notes` alongside the category-specific notes column at import
 *    time ({@see RowParser::composeNotes()}), so the two can
 *    no longer be told apart. `notes` is written back into the category's own
 *    notes column (Q for 02/06, R for 03) instead of guessing a split.
 */
final class AssetExportService
{
    private const HEADER_FILL_RGB = 'D9D9D9';

    private const HEADER_ROW = 6;

    private const SUBHEADER_ROW = 7;

    private const FIRST_DATA_ROW = 8;

    /**
     * @param  Collection<int, Asset>  $assets  already filtered/authorized/ordered by the caller
     */
    public function build(Collection $assets): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $byCategory = $assets->groupBy('category_code');
        $categoryCodes = $byCategory->keys()->sort()->values()->all();

        foreach ($categoryCodes as $categoryCode) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($categoryCode));
            $this->buildCategorySheet($sheet, (string) $categoryCode, $byCategory->get($categoryCode));
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $empty = $spreadsheet->createSheet();
            $empty->setTitle('DATA');
            $empty->setCellValue('A1', 'Tidak ada data aset yang sesuai dengan filter yang dipilih.');
            $empty->getStyle('A1')->getFont()->setBold(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * A human-recognisable download filename — never the fixed historical
     * filenames (`Inventaris Meubelair.xlsx` etc.) so an exported file is never
     * mistaken for the original source workbook it structurally matches.
     *
     * @param  Collection<int, Asset>  $assets
     */
    public function filenameFor(Collection $assets): string
    {
        $date = now()->format('Y-m-d');
        $categoryCodes = $assets->pluck('category_code')->unique()->values();

        if ($categoryCodes->count() === 1) {
            $category = Category::query()->find($categoryCodes->first());
            $label = $category !== null
                ? $this->sanitizeFilenameSegment($category->name)
                : $categoryCodes->first();

            return "Inventaris {$label} - Export {$date}.xlsx";
        }

        return "Inventaris - Export {$date}.xlsx";
    }

    /**
     * Defense-in-depth filename sanitizer (Tahap 6.9 R8.2, P3-6) — `Category::name`
     * is only ever writable via `can:admin`-gated `categories.manage`, so this is
     * not closing a reachable exploit for a lower-privileged role today, but the
     * `Content-Disposition` header this feeds is still built by simple string
     * interpolation, so an admin-set name containing a `"`, a path separator, or a
     * raw CR/LF should never be able to break out of the quoted filename or inject
     * an extra header line. Same whitelist philosophy as
     * {@see \App\Services\Label\AssetLabelPdfService::individualFilename()}, widened
     * to also allow a literal space — unlike a generated `asset_code`, a category
     * name is free text meant to stay human-readable in the downloaded filename
     * (e.g. "Alat Kebersihan"), so collapsing every space to `_` would needlessly
     * mangle it. No Unicode normalization: plain byte-level whitelist is enough to
     * neutralize the characters that actually matter here (quotes, slashes,
     * control characters including CR/LF).
     */
    private function sanitizeFilenameSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $value) ?? $value;
        $safe = trim($safe, " _");

        return $safe !== '' ? $safe : 'Kategori';
    }

    private function sheetTitle(string $categoryCode): string
    {
        $category = Category::query()->find($categoryCode);
        $name = $category?->name ?? 'KATEGORI';
        $title = "{$categoryCode} {$name}";

        // Excel worksheet titles: max 31 chars, no : \ / ? * [ ]
        $title = preg_replace('/[:\\\\\/?*\[\]]/', ' ', $title) ?? $title;

        return mb_substr($title, 0, 31);
    }

    /**
     * @param  Collection<int, Asset>  $assets  every row for this one category, already ordered
     */
    private function buildCategorySheet(Worksheet $sheet, string $categoryCode, Collection $assets): void
    {
        $category = Category::query()->find($categoryCode);
        $categoryName = $category?->name ?? $categoryCode;
        $specific = AssetSheetColumns::categorySpecificHeaders($categoryCode);

        $sheet->setCellValue('A1', 'DAFTAR '.mb_strtoupper($categoryName));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // --- LOKASI row (row 3) — only a single, real location code when every row
        // in this sheet shares one; otherwise this is a mixed-location export and
        // showing one location's name would misrepresent the data.
        $locationCodes = $assets->pluck('location_code')->unique();
        $sheet->setCellValue('B3', 'LOKASI');
        $sheet->setCellValue('F3', ':');
        if ($locationCodes->count() === 1) {
            $location = $assets->first()->location;
            $sheet->setCellValue('A3', $locationCodes->first());
            $sheet->setCellValue('G3', $location?->name ?? $locationCodes->first());
        } else {
            $sheet->setCellValue('G3', 'SEMUA LOKASI');
        }
        $sheet->getStyle('B3')->getFont()->setBold(true);

        // --- KODE BARANG row (row 4) — this sheet is always exactly one category.
        $sheet->setCellValue('A4', $categoryCode);
        $sheet->setCellValue('B4', 'KODE BARANG');
        $sheet->setCellValue('F4', ':');
        $sheet->setCellValue('G4', $categoryName);
        $sheet->getStyle('B4')->getFont()->setBold(true);

        $this->writeHeader($sheet, $specific);
        $this->writeRows($sheet, $categoryCode, $specific, $assets);
        $this->applyColumnWidths($sheet, $specific);

        $sheet->freezePane('A'.self::FIRST_DATA_ROW);
    }

    /** @param array<string, array{label: string, sub?: string}> $specific */
    private function writeHeader(Worksheet $sheet, array $specific): void
    {
        foreach (AssetSheetColumns::COMMON_HEADERS as $col => $label) {
            $sheet->setCellValue("{$col}".self::HEADER_ROW, $label);
        }
        foreach ($specific as $col => $meta) {
            $sheet->setCellValue("{$col}".self::HEADER_ROW, $meta['label']);
        }
        foreach (AssetSheetColumns::CONDITION_SUBHEADERS as $col => $label) {
            $sheet->setCellValue("{$col}".self::SUBHEADER_ROW, $label);
        }
        foreach ($specific as $col => $meta) {
            if (isset($meta['sub'])) {
                $sheet->setCellValue("{$col}".self::SUBHEADER_ROW, $meta['sub']);
            }
        }

        $lastCol = array_key_last($specific) ?? 'P';
        $range = 'A'.self::HEADER_ROW.":{$lastCol}".self::SUBHEADER_ROW;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL_RGB);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /**
     * @param  array<string, array{label: string, sub?: string}>  $specific
     * @param  Collection<int, Asset>  $assets
     */
    private function writeRows(Worksheet $sheet, string $categoryCode, array $specific, Collection $assets): void
    {
        $map = CategoryColumnMap::forCategory($categoryCode);
        $row = self::FIRST_DATA_ROW;
        $index = 1;

        foreach ($assets as $asset) {
            // Identity codes are TEXT, always — never let Excel reinterpret a
            // zero-padded code ("01", "001", "0004") as a number.
            $sheet->setCellValueExplicit("A{$row}", $index, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("B{$row}", $asset->location_code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", $asset->category_code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", $asset->subcategory_code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", $asset->sequence_no, DataType::TYPE_STRING);
            $sheet->getStyle("B{$row}:E{$row}")->getNumberFormat()->setFormatCode('@');

            $sheet->setCellValueExplicit("F{$row}", $asset->asset_year, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("G{$row}", (string) ($asset->brand_model ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$row}", (string) ($asset->serial_no ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$row}", (string) ($asset->material ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("K{$row}", 1, DataType::TYPE_NUMERIC);

            foreach (['L' => 'baik', 'M' => 'kurang_baik', 'N' => 'rusak_berat'] as $col => $value) {
                $mark = $asset->condition?->value === $value ? 'v' : '';
                $sheet->setCellValueExplicit("{$col}{$row}", $mark, DataType::TYPE_STRING);
            }

            $roomLabel = $asset->room?->name ?? $asset->room_raw_value ?? '';
            $sheet->setCellValueExplicit("O{$row}", $roomLabel, DataType::TYPE_STRING);
            // P (Ket. Mutasi dll) intentionally blank — see class docblock.

            $this->writeCategorySpecificColumns($sheet, $row, $categoryCode, $map, $asset);

            $sheet->getStyle("A{$row}:".(array_key_last($specific) ?? 'P')."{$row}")
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$row}:".(array_key_last($specific) ?? 'P')."{$row}")
                ->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("A{$row}:E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("F{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("K{$row}:N{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            if ($index % 2 === 0) {
                $lastCol = array_key_last($specific) ?? 'P';
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
            }

            $row++;
            $index++;
        }
    }

    /**
     * @param  array{detail_type_col: ?string, capacity_note_col: ?string, purchase_date_col: ?string,
     *                notes_cols: list<string>, writeoff_status_col: ?string, writeoff_date_col: ?string}  $map
     */
    private function writeCategorySpecificColumns(Worksheet $sheet, int $row, string $categoryCode, array $map, Asset $asset): void
    {
        $notes = (string) ($asset->notes ?? '');

        if ($map['detail_type_col'] !== null) {
            $sheet->setCellValueExplicit("{$map['detail_type_col']}{$row}", (string) ($asset->detail_type ?? ''), DataType::TYPE_STRING);
        }
        if ($map['capacity_note_col'] !== null) {
            $sheet->setCellValueExplicit("{$map['capacity_note_col']}{$row}", (string) ($asset->capacity_note ?? ''), DataType::TYPE_STRING);
        }
        if ($map['purchase_date_col'] !== null) {
            $sheet->setCellValueExplicit("{$map['purchase_date_col']}{$row}", $asset->purchase_date?->toDateString() ?? '', DataType::TYPE_STRING);
        }
        if ($map['writeoff_status_col'] !== null) {
            $sheet->setCellValueExplicit("{$map['writeoff_status_col']}{$row}", $asset->is_written_off ? 'Dihapus' : '', DataType::TYPE_STRING);
        }
        if ($map['writeoff_date_col'] !== null) {
            $sheet->setCellValueExplicit("{$map['writeoff_date_col']}{$row}", $asset->written_off_on?->toDateString() ?? '', DataType::TYPE_STRING);
        }

        // `notes` lands in whichever column this category's ORIGINAL notes column
        // was (Q for 02/06, R for 03) — see CategoryColumnMap's own docblock.
        $notesCol = match ($categoryCode) {
            '03' => 'R',
            default => 'Q',
        };
        if ($notes !== '') {
            $sheet->setCellValueExplicit("{$notesCol}{$row}", $notes, DataType::TYPE_STRING);
        }
    }

    /** @param array<string, array{label: string, sub?: string}> $specific */
    private function applyColumnWidths(Worksheet $sheet, array $specific): void
    {
        foreach (array_keys(AssetSheetColumns::COMMON_HEADERS + $specific) as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(8);
        $sheet->getColumnDimension('C')->setWidth(8);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('O')->setWidth(22);
        $sheet->getColumnDimension('P')->setWidth(20);
    }
}
