<?php

namespace Tests\Unit\Import;

use App\Import\Excel\ScannedRow;
use App\Import\Parsing\ParsedRow;
use App\Import\Parsing\RowParser;
use PHPUnit\Framework\TestCase;

/**
 * Tahap 2 — RowParser's SUMBER (column U) handling. Pure, no DB.
 */
class RowParserSourceTest extends TestCase
{
    private function parse(array $extra, bool $hasSourceColumn): ParsedRow
    {
        $cells = array_merge(['B' => '01', 'C' => '02', 'D' => '001', 'E' => '001', 'F' => '2020', 'L' => 'v'], $extra);

        return (new RowParser)->parse(new ScannedRow(9, ScannedRow::TYPE_DATA, $cells, []), '02', $hasSourceColumn);
    }

    /** @return list<string> */
    private function sourceNotes(ParsedRow $row): array
    {
        return array_values(array_filter(
            array_column($row->parseNotes, 'code'),
            fn (string $code) => str_starts_with($code, 'asset_source'),
        ));
    }

    public function test_without_the_column_the_row_gets_belum_diberi_keterangan_and_no_message(): void
    {
        $row = $this->parse(['U' => 'BOSDA'], hasSourceColumn: false); // U is not read in legacy layout

        $this->assertSame('belum_diberi_keterangan', $row->assetSource);
        $this->assertSame(ParsedRow::SOURCE_COLUMN_ABSENT, $row->assetSourceStatus);
        $this->assertNull($row->assetSourceRaw);
        $this->assertSame([], $this->sourceNotes($row));
    }

    public function test_a_blank_cell_is_a_warning_with_belum_diberi_keterangan(): void
    {
        foreach (['', '   ', '-'] as $blank) {
            $row = $this->parse(['U' => $blank], hasSourceColumn: true);

            $this->assertSame('belum_diberi_keterangan', $row->assetSource);
            $this->assertSame(ParsedRow::SOURCE_MISSING, $row->assetSourceStatus);
            $this->assertSame(['asset_source_missing'], $this->sourceNotes($row));
            $this->assertSame('warning', $row->parseNotes[array_key_last($row->parseNotes)]['severity']);
        }
    }

    public function test_a_recognised_value_is_normalised_with_no_message(): void
    {
        $row = $this->parse(['U' => '  bostk '], hasSourceColumn: true);

        $this->assertSame('bostk', $row->assetSource);
        $this->assertSame('bostk', $row->assetSourceRaw);
        $this->assertSame(ParsedRow::SOURCE_RECOGNIZED, $row->assetSourceStatus);
        $this->assertSame([], $this->sourceNotes($row));
    }

    public function test_an_unrecognised_value_keeps_the_raw_text_and_no_category(): void
    {
        $row = $this->parse(['U' => 'Yayasan  Lama'], hasSourceColumn: true);

        $this->assertNull($row->assetSource, 'never coerced into a category, never Yayasan');
        $this->assertSame('Yayasan Lama', $row->assetSourceRaw);
        $this->assertSame(ParsedRow::SOURCE_UNRECOGNIZED, $row->assetSourceStatus);
        $this->assertSame(['asset_source_unrecognized'], $this->sourceNotes($row));
        $this->assertSame('asset_source', $row->toArray()['parse_notes'][0]['field'] ?? null);
        $this->assertArrayHasKey('asset_source_status', $row->toArray());
    }
}
