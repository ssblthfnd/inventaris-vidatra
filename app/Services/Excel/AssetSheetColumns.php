<?php

namespace App\Services\Excel;

use App\Import\Excel\SheetScanner;
use App\Import\Parsing\CategoryColumnMap;
use App\Import\Parsing\RowParser;
use App\Services\Asset\AssetExportService;
use App\Services\Import\ImportTemplateService;

/**
 * The single authoritative definition of the asset spreadsheet's column layout —
 * used by BOTH {@see ImportTemplateService} (Tahap 6.1, a
 * blank fill-in template) and {@see AssetExportService}
 * (Tahap 6.2, a data-filled export), so the two can never drift apart on what
 * column means what.
 *
 * Column letters and labels are reverse-engineered from the real source
 * workbooks (`data/excel/Inventaris {Meubelair,Elektronik,Alat Kebersihan}.xlsx`)
 * and from {@see CategoryColumnMap}'s own docblock — never
 * invented. See that class for which column holds which parsed field per
 * category; this class only adds the human-readable label text.
 */
final class AssetSheetColumns
{
    /** Columns identical across every category, in the exact order/letters the
     *  real importer reads them ({@see RowParser}). */
    public const COMMON_HEADERS = [
        'A' => 'No. Urut',
        'B' => 'Lokasi (kode 2 digit)',
        'C' => 'Kategori (kode 2 digit)',
        'D' => 'Subkategori (kode 3 digit)',
        'E' => 'No. Urut / Sequence Aset',
        'F' => 'Tahun',
        'G' => 'Merk / Model',
        'H' => 'No. Seri Pabrik',
        'I' => 'Bahan',
        'K' => 'Jumlah Barang',
        'L' => 'Keadaan Barang',
        'O' => 'Ruangan',
        'P' => 'Ket. Mutasi dll',
    ];

    /**
     * Tahap 2 — Sumber Aset. Column U holds the asset-source category in BOTH the
     * template and the export, for every category (after the last historical
     * category-specific column, T). The importer recognises a workbook as having
     * this column by the header text in U ({@see SheetScanner::$hasSourceColumn}),
     * so historical workbooks — nothing in U — keep importing unchanged.
     */
    public const SOURCE_COL = 'U';

    public const SOURCE_HEADER = 'SUMBER';

    public const SOURCE_SUBHEADER = 'BOSDA / BOSP / BOSTK / Yayasan / Lainnya / Belum diberi keterangan';

    /**
     * Tahap 2 — export only: the free-text `funding_source` ("Keterangan Sumber")
     * in column V. The importer does not read V (the template has no such column);
     * re-importing an export therefore never writes `funding_source`.
     */
    public const SOURCE_NOTE_COL = 'V';

    public const SOURCE_NOTE_HEADER = 'KETERANGAN SUMBER';

    public const CONDITION_SUBHEADERS = [
        'L' => 'Baik (B)',
        'M' => 'Kurang Baik (KB)',
        'N' => 'Rusak Berat (RB)',
    ];

    /**
     * Category-specific column roles (label + optional sub-label), mirroring
     * {@see CategoryColumnMap::forCategory()}'s own docblock
     * exactly.
     *
     * @return array<string, array{label: string, sub?: string}>
     */
    public static function categorySpecificHeaders(string $categoryCode): array
    {
        return match ($categoryCode) {
            '02' => [
                'Q' => ['label' => 'KETERANGAN'],
                'R' => ['label' => 'TANGGAL PEMBELIAN'],
            ],
            '03' => [
                'Q' => ['label' => 'JENIS'],
                'R' => ['label' => 'KETERANGAN'],
                'S' => ['label' => 'STATUS', 'sub' => 'Isi "Dihapus" / "Di Junk" jika write-off'],
                'T' => ['label' => 'TANGGAL', 'sub' => 'Tanggal write-off (jika ada)'],
            ],
            '06' => [
                'Q' => ['label' => 'KETERANGAN'],
                'R' => ['label' => 'TANGGAL PEMBELIAN'],
            ],
            default => [
                'Q' => ['label' => 'KETERANGAN'],
            ],
        };
    }
}
