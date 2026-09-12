<?php

namespace App\Import\Parsing;

/**
 * Per-category column roles, derived from cell-by-cell inspection of the three actual
 * workbooks (not assumed from schema — Tahap 4 §2). The header LABEL of columns Q/R/S/T
 * differs per file, so the role is pinned by category code.
 *
 *   Column layout common to all three sheets:
 *     A No.Urut(counter)  B loc  C cat  D subcat  E sequence_no  F asset_year
 *     G Merk  H No.Seri  I Bahan  J Tahun(number)  K Jumlah  L/M/N Keadaan(B/KB/RB)
 *     O Ruangan  P "Ket. Mutasi dll"
 *
 *   Category-specific:
 *     02 MEUBELAIR       Q "KETERANGAN" -> notes    R "TANGGAL PEMBELIAN" (mislabeled; free text) -> notes
 *     03 ELEKTRONIK      Q "JENIS" -> detail_type   R "KETERANGAN" -> notes
 *                        S "STATUS / DI HAPUS (JUNK)" -> is_written_off   T "TANGGAL" -> written_off_on
 *     06 ALAT KEBERSIHAN Q "KETERANGAN" -> capacity_note   R "TANGGAL PEMBELIAN" -> purchase_date
 */
final class CategoryColumnMap
{
    /** The only categories with a per-column mapping above — also the single source
     *  of truth for which categories Tahap 6.1's template generator may produce a
     *  workbook for (see App\Services\Import\ImportTemplateService). */
    public const KNOWN_CATEGORIES = ['02', '03', '06'];

    /**
     * @return array{
     *   detail_type_col: ?string,
     *   capacity_note_col: ?string,
     *   purchase_date_col: ?string,
     *   notes_cols: list<string>,
     *   writeoff_status_col: ?string,
     *   writeoff_date_col: ?string,
     * }
     */
    public static function forCategory(string $categoryCode): array
    {
        return match ($categoryCode) {
            '02' => [
                'detail_type_col' => null,
                'capacity_note_col' => null,
                'purchase_date_col' => null,
                'notes_cols' => ['Q', 'R', 'P'],
                'writeoff_status_col' => null,
                'writeoff_date_col' => null,
            ],
            '03' => [
                'detail_type_col' => 'Q',
                'capacity_note_col' => null,
                'purchase_date_col' => null,
                'notes_cols' => ['R', 'P'],
                'writeoff_status_col' => 'S',
                'writeoff_date_col' => 'T',
            ],
            '06' => [
                'detail_type_col' => null,
                'capacity_note_col' => 'Q',
                'purchase_date_col' => 'R',
                'notes_cols' => ['P'],
                'writeoff_status_col' => null,
                'writeoff_date_col' => null,
            ],
            default => [
                // Unknown category: keep only the columns that are identical across all sheets.
                'detail_type_col' => null,
                'capacity_note_col' => null,
                'purchase_date_col' => null,
                'notes_cols' => ['Q', 'R', 'P'],
                'writeoff_status_col' => null,
                'writeoff_date_col' => null,
            ],
        };
    }

    public static function isKnownCategory(string $categoryCode): bool
    {
        return in_array($categoryCode, self::KNOWN_CATEGORIES, true);
    }
}
