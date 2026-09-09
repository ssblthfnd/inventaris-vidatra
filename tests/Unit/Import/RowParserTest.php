<?php

namespace Tests\Unit\Import;

use App\Import\Excel\ScannedRow;
use App\Import\Parsing\RowParser;
use PHPUnit\Framework\TestCase;

/**
 * RowParser tests — pure (no DB). Verifies deterministic extraction and that raw values
 * are preserved (no correction, no normalization of sequence_no).
 */
class RowParserTest extends TestCase
{
    private function row(array $cells, ?string $blockCode = null): ScannedRow
    {
        return new ScannedRow(42, ScannedRow::TYPE_DATA, $cells, [], $blockCode);
    }

    public function test_sequence_no_is_kept_verbatim(): void
    {
        $p = (new RowParser())->parse($this->row([
            'B' => '01', 'C' => '03', 'D' => '001', 'E' => '0017A', 'F' => '2024',
        ]), '03');

        $this->assertSame('0017A', $p->sequenceNo);       // no strip, no pad, no int-cast
        $this->assertSame(2024, $p->assetYear);
        $this->assertSame('01.03.001.0017A.2024', $p->identityLabel());
    }

    public function test_codes_are_zero_padded_to_fixed_width(): void
    {
        $p = (new RowParser())->parse($this->row([
            'B' => '1', 'C' => '2', 'D' => '3', 'E' => '5', 'F' => '2020',
        ]), '02');

        $this->assertSame('01', $p->locationCode);
        $this->assertSame('02', $p->categoryCode);
        $this->assertSame('003', $p->subcategoryCode);
    }

    public function test_condition_from_lmn_marks(): void
    {
        $parser = new RowParser();

        $baik = $parser->parse($this->row(['E' => '1', 'F' => '2020', 'L' => 'v', 'M' => '-', 'N' => '-']), '02');
        $this->assertSame('baik', $baik->conditionParsed);

        $rusak = $parser->parse($this->row(['E' => '1', 'F' => '2020', 'L' => '-', 'M' => '-', 'N' => 'v']), '02');
        $this->assertSame('rusak_berat', $rusak->conditionParsed);

        $none = $parser->parse($this->row(['E' => '1', 'F' => '2020', 'L' => '', 'M' => '', 'N' => '']), '02');
        $this->assertNull($none->conditionParsed);
        $this->assertContains('condition_missing', array_column($none->parseNotes, 'code'));

        $ambiguous = $parser->parse($this->row(['E' => '1', 'F' => '2020', 'L' => 'v', 'M' => '-', 'N' => 'v']), '02');
        $this->assertNull($ambiguous->conditionParsed);
        $this->assertContains('condition_ambiguous', array_column($ambiguous->parseNotes, 'code'));
    }

    public function test_di_junk_sets_write_off_but_not_condition(): void
    {
        $p = (new RowParser())->parse($this->row([
            'E' => '1', 'F' => '2015', 'L' => 'v', 'S' => 'Di Junk', 'T' => '45548',
        ]), '03');

        $this->assertTrue($p->isWrittenOff);
        $this->assertSame('Di Junk', $p->writtenOffNote);
        $this->assertSame('2024-09-13', $p->writtenOffOn);
        $this->assertSame('baik', $p->conditionParsed); // physical condition unchanged (§17)
    }

    public function test_category_specific_columns(): void
    {
        $parser = new RowParser();

        // 03 ELEKTRONIK: Q -> detail_type, R -> notes
        $el = $parser->parse($this->row(['E' => '1', 'F' => '2020', 'Q' => 'Monitor', 'R' => 'HERI']), '03');
        $this->assertSame('Monitor', $el->detailType);
        $this->assertSame('HERI', $el->notes);
        $this->assertNull($el->capacityNote);

        // 06 ALAT KEBERSIHAN: Q -> capacity_note, R -> purchase_date
        $ak = $parser->parse($this->row(['E' => '1', 'F' => '2023', 'Q' => '12 liter', 'R' => '30 Mei 2023']), '06');
        $this->assertSame('12 liter', $ak->capacityNote);
        $this->assertSame('2023-05-30', $ak->purchaseDate);
        $this->assertNull($ak->detailType);

        // 02 MEUBELAIR: Q -> notes, no detail_type / capacity / purchase_date
        $mb = $parser->parse($this->row(['E' => '1', 'F' => '2016', 'Q' => 'Meja Kabid Umum']), '02');
        $this->assertSame('Meja Kabid Umum', $mb->notes);
        $this->assertNull($mb->purchaseDate);
        $this->assertNull($mb->detailType);
    }

    public function test_year_falls_back_to_column_j_then_errors(): void
    {
        $parser = new RowParser();

        $fallback = $parser->parse($this->row(['E' => '1', 'F' => '', 'J' => '2018']), '02');
        $this->assertSame(2018, $fallback->assetYear);

        $missing = $parser->parse($this->row(['E' => '1', 'F' => '', 'J' => '']), '02');
        $this->assertNull($missing->assetYear);
        $this->assertContains('asset_year_missing', array_column($missing->parseNotes, 'code'));
    }
}
