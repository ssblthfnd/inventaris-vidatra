<?php

namespace App\Import\Parsing;

use App\Import\Excel\ScannedRow;

/**
 * Turns one scanned DATA row into a {@see ParsedRow}. Deterministic; performs NO
 * correction of values (Tahap 4 §20) and NO fuzzy matching (§45). Anything doubtful is
 * recorded as a parse note (severity warning/error) for the validator to act on.
 */
final class RowParser
{
    private const WRITE_OFF_PATTERN = '/\b(di\s*junk|junk|di\s*hapus|dihapus|hapus|dispose)\b/i';

    public function parse(ScannedRow $row, string $batchCategoryCode): ParsedRow
    {
        $map = CategoryColumnMap::forCategory($batchCategoryCode);
        $notes = [];

        $c = fn (string $col): string => $row->cell($col);

        $locationCode = $this->code($c('B'), 2);
        $categoryCode = $this->code($c('C'), 2);

        // subcategory: column D per row; fall back to the current block header if D is blank
        $subcategoryCode = $this->code($c('D'), 3);
        if ($subcategoryCode === null && $row->blockSubcategoryCode !== null) {
            $subcategoryCode = $row->blockSubcategoryCode;
            $notes[] = $this->note('subcategory_from_block_header', 'warning', 'subcategory_code',
                "column D empty; used block header [{$row->blockSubcategoryCode}]");
        } elseif ($subcategoryCode !== null
            && $row->blockSubcategoryCode !== null
            && $subcategoryCode !== $row->blockSubcategoryCode) {
            $notes[] = $this->note('subcategory_block_mismatch', 'warning', 'subcategory_code',
                "row subcategory [{$subcategoryCode}] differs from block header [{$row->blockSubcategoryCode}]");
        }

        // sequence_no — stored EXACTLY as read (Tahap 4 §7/§8): trim only, no padding/casting
        $sequenceNo = $c('E') !== '' ? $c('E') : null;

        // asset_year — column F (usually =J{row}); fall back to column J
        [$assetYear, $yearNote] = $this->parseYear($c('F'), $c('J'));
        if ($yearNote !== null) {
            $notes[] = $yearNote;
        }

        $roomRaw = $c('O') !== '' ? $c('O') : null;

        // condition — L/M/N check marks (Baik / Kurang Baik / Rusak Berat)
        [$conditionRaw, $conditionParsed, $condNote] = $this->parseCondition($c('L'), $c('M'), $c('N'));
        if ($condNote !== null) {
            $notes[] = $condNote;
        }

        // business write-off (Elektronik "Di Junk") — distinct from physical condition (§17)
        $isWrittenOff = false;
        $writtenOffOn = null;
        $writtenOffNote = null;
        if ($map['writeoff_status_col'] !== null) {
            $statusRaw = $c($map['writeoff_status_col']);
            if ($statusRaw !== '' && preg_match(self::WRITE_OFF_PATTERN, $statusRaw) === 1) {
                $isWrittenOff = true;
                $writtenOffNote = $statusRaw;
                if ($map['writeoff_date_col'] !== null) {
                    $rawT = $c($map['writeoff_date_col']);
                    $parsed = ValueNormalizer::parseDate($rawT === '' ? null : $rawT);
                    $writtenOffOn = $parsed['date'];
                    if (! $parsed['recognised']) {
                        $notes[] = $this->note('written_off_date_unparseable', 'warning', 'written_off_on',
                            "write-off date cell not recognised: [{$rawT}]");
                    }
                }
            }
        }

        // descriptive fields — raw values preserved (§20)
        $brandModel = ValueNormalizer::cleanText($c('G'));
        $serialNo = ValueNormalizer::cleanText($c('H'));
        $material = ValueNormalizer::cleanText($c('I'));
        $detailType = $map['detail_type_col'] ? ValueNormalizer::cleanText($c($map['detail_type_col'])) : null;
        $capacityNote = $map['capacity_note_col'] ? ValueNormalizer::cleanText($c($map['capacity_note_col'])) : null;

        $purchaseDate = null;
        if ($map['purchase_date_col'] !== null) {
            $rawPd = $c($map['purchase_date_col']);
            $parsed = ValueNormalizer::parseDate($rawPd === '' ? null : $rawPd);
            $purchaseDate = $parsed['date'];
            if (! $parsed['recognised']) {
                $notes[] = $this->note('purchase_date_unparseable', 'warning', 'purchase_date',
                    "purchase date cell not recognised: [{$rawPd}]");
            }
        }

        // quantity — must be 1 (§14/§22). Keep the read value; validator rejects != 1.
        $quantity = 1;
        $rawK = $c('K');
        if ($rawK !== '' && ! in_array($rawK, ['1', '1.0'], true) && is_numeric($rawK)) {
            $quantity = (int) $rawK;
        } elseif ($rawK !== '' && ! is_numeric($rawK)) {
            $notes[] = $this->note('quantity_non_numeric', 'warning', 'quantity',
                "quantity cell not numeric: [{$rawK}] — treated as 1");
        }

        $notesText = $this->composeNotes($row, $map['notes_cols']);

        return new ParsedRow(
            rowNumber: $row->rowNumber,
            locationCode: $locationCode,
            categoryCode: $categoryCode,
            subcategoryCode: $subcategoryCode,
            sequenceNo: $sequenceNo,
            assetYear: $assetYear,
            roomRawValue: $roomRaw,
            conditionRaw: $conditionRaw,
            conditionParsed: $conditionParsed,
            isWrittenOff: $isWrittenOff,
            writtenOffOn: $writtenOffOn,
            writtenOffNote: $writtenOffNote,
            brandModel: $brandModel,
            serialNo: $serialNo,
            material: $material,
            purchaseDate: $purchaseDate,
            fundingSource: null,
            detailType: $detailType,
            capacityNote: $capacityNote,
            quantity: $quantity,
            notes: $notesText,
            blockSubcategoryCode: $row->blockSubcategoryCode,
            parseNotes: $notes,
        );
    }

    private function code(string $raw, int $width): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $raw) === 1) {
            return str_pad($raw, $width, '0', STR_PAD_LEFT);
        }

        return $raw; // non-numeric — return as-is so the validator can flag it
    }

    /** @return array{0: ?int, 1: ?array} */
    private function parseYear(string $f, string $j): array
    {
        foreach ([$f, $j] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (preg_match('/^\d{4}(\.0+)?$/', $candidate) === 1) {
                return [(int) $candidate, null];
            }
            if (is_numeric($candidate)) {
                return [(int) $candidate, $this->note('asset_year_suspicious', 'warning', 'asset_year',
                    "year cell is a non-4-digit number: [{$candidate}]")];
            }

            return [null, $this->note('asset_year_unparseable', 'error', 'asset_year',
                "year cell not numeric: [{$candidate}]")];
        }

        return [null, $this->note('asset_year_missing', 'error', 'asset_year', 'no year value in column F or J')];
    }

    /** @return array{0: string, 1: ?string, 2: ?array} */
    private function parseCondition(string $l, string $m, string $n): array
    {
        // condition_raw column is VARCHAR(50) (schema_design.md §2.10)
        $raw = mb_substr(sprintf('B=[%s]|KB=[%s]|RB=[%s]', $l, $m, $n), 0, 50);

        $marks = [];
        $unrecognised = [];
        foreach (['baik' => $l, 'kurang_baik' => $m, 'rusak_berat' => $n] as $cond => $cell) {
            if (ValueNormalizer::isConditionMark($cell)) {
                $marks[] = $cond;
            } elseif (! ValueNormalizer::isConditionBlank($cell)) {
                $unrecognised[] = "$cond=[$cell]";
            }
        }

        if ($unrecognised !== []) {
            return [$raw, null, $this->note('condition_raw_unrecognized', 'warning', 'condition',
                'unrecognised Keadaan Barang value(s): ' . implode(', ', $unrecognised))];
        }
        if (count($marks) === 1) {
            return [$raw, $marks[0], null];
        }
        if ($marks === []) {
            return [$raw, null, $this->note('condition_missing', 'warning', 'condition',
                'no Keadaan Barang mark (B/KB/RB all blank)')];
        }

        return [$raw, null, $this->note('condition_ambiguous', 'warning', 'condition',
            'multiple Keadaan Barang marks: ' . implode(', ', $marks))];
    }

    /** @param list<string> $cols */
    private function composeNotes(ScannedRow $row, array $cols): ?string
    {
        $parts = [];
        foreach ($cols as $col) {
            $v = ValueNormalizer::cleanText($row->cell($col));
            if ($v === null) {
                continue;
            }
            $parts[] = $col === 'P' ? "mutasi: {$v}" : $v;
        }
        $text = implode(' | ', array_unique($parts));

        return $text === '' ? null : $text;
    }

    /** @return array{code:string,severity:string,field:?string,message:string} */
    private function note(string $code, string $severity, ?string $field, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'field' => $field, 'message' => $message];
    }
}
