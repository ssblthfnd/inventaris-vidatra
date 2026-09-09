<?php

namespace App\Import\Parsing;

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Deterministic value helpers for the Excel import pipeline.
 *
 * NO fuzzy matching, NO guessing (Tahap 4 §45). Every method here is a pure function:
 * same input -> same output.
 */
final class ValueNormalizer
{
    /**
     * Room match key — schema_design.md §2.5:
     *   UPPER( TRIM( collapse_internal_whitespace( raw ) ) )
     *
     * Only case + whitespace are normalized. Punctuation (".", "&", "/") is preserved
     * because it can change meaning ("R. RAPAT" vs "R.RAPAT" are distinct aliases in
     * mapping_ruangan.md).
     */
    public static function roomMatchKey(?string $raw): string
    {
        $raw = (string) $raw;
        $raw = str_replace("\u{00A0}", ' ', $raw);           // NBSP -> space
        $raw = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
        return mb_strtoupper($raw, 'UTF-8');
    }

    /**
     * Whether an L/M/N "Keadaan Barang" cell carries a check mark.
     * Observed marks in the workbooks: "v", "V". Dash / empty = not marked.
     * A recognised mark set is used so an unexpected value is reported, not silently
     * treated as a mark (Tahap 4 §18).
     */
    public static function isConditionMark(?string $cell): bool
    {
        $t = mb_strtolower(trim((string) $cell), 'UTF-8');

        return in_array($t, ['v', '√', '✓', 'x', 'ya', 'ok'], true);
    }

    /** Whether an L/M/N cell is an explicit "not this condition" marker or blank. */
    public static function isConditionBlank(?string $cell): bool
    {
        $t = trim((string) $cell);

        return $t === '' || in_array($t, ['-', '–', '—', '0'], true);
    }

    /**
     * Numeric prefix of a sequence string, for MAX() comparison ONLY
     * (Tahap 4 §8 — never used as the stored value).
     *   "005A" -> 5 ; "0017B" -> 17 ; "001" -> 1 ; "" / "A" -> null
     */
    public static function sequenceNumericPrefix(?string $sequence): ?int
    {
        if (preg_match('/^\s*0*(\d+)/', (string) $sequence, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Parse a purchase / write-off date cell into 'Y-m-d', or null if it cannot be
     * parsed safely (Tahap 4 §19 — never invent a date).
     *
     * Handles: real Excel dates already converted by PhpSpreadsheet, bare Excel serial
     * numbers, ISO / d-m-Y strings, and Indonesian long-form ("30 Mei 2023").
     *
     * @return array{date: ?string, recognised: bool}  recognised=false => caller should warn
     */
    public static function parseDate(mixed $value): array
    {
        if ($value === null || trim((string) $value) === '') {
            return ['date' => null, 'recognised' => true];
        }

        // Numeric -> Excel serial date (guard against year-like small numbers, Tahap 4 §19)
        if (is_numeric($value)) {
            $num = (float) $value;
            if ($num >= 20000 && $num <= 80000) { // ~1954..2119
                try {
                    return ['date' => ExcelDate::excelToDateTimeObject($num)->format('Y-m-d'), 'recognised' => true];
                } catch (\Throwable) {
                    return ['date' => null, 'recognised' => false];
                }
            }

            return ['date' => null, 'recognised' => false]; // bare number that is not a plausible serial
        }

        $s = trim((string) $value);

        // ISO / common numeric separators
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $fmt, $s);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return ['date' => $dt->format('Y-m-d'), 'recognised' => true];
            }
        }

        // Indonesian long form: "30 Mei 2023", "1 Agustus 2019"
        $months = [
            'januari' => 1, 'februari' => 2, 'pebruari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8, 'september' => 9,
            'oktober' => 10, 'november' => 11, 'nopember' => 11, 'desember' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7,
            'agt' => 8, 'ags' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'des' => 12,
        ];
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/u', $s, $m) === 1) {
            $mon = $months[mb_strtolower($m[2], 'UTF-8')] ?? null;
            if ($mon !== null) {
                $d = (int) $m[1];
                $y = (int) $m[3];
                if (checkdate($mon, $d, $y)) {
                    return ['date' => sprintf('%04d-%02d-%02d', $y, $mon, $d), 'recognised' => true];
                }
            }
        }

        return ['date' => null, 'recognised' => false];
    }

    /** Trim + collapse a free-text cell; empty string becomes null. */
    public static function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return $t === '' || $t === '-' ? null : $t;
    }
}
