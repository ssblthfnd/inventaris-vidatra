<?php

namespace Tests\Unit\Import;

use App\Import\Parsing\ValueNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function tests — no database, safe under the sqlite test connection.
 */
class ValueNormalizerTest extends TestCase
{
    public function test_room_match_key_normalizes_case_and_whitespace_only(): void
    {
        $this->assertSame('KETUA HARIAN', ValueNormalizer::roomMatchKey('Ketua Harian'));
        $this->assertSame('SARANA DAN HUM', ValueNormalizer::roomMatchKey('  SARANA   DAN  HUM '));
        // punctuation preserved (meaningful): "R. RAPAT" != "R.RAPAT"
        $this->assertNotSame(
            ValueNormalizer::roomMatchKey('R. RAPAT'),
            ValueNormalizer::roomMatchKey('R.RAPAT'),
        );
        // "&" preserved
        $this->assertSame('SARANA & HUMAS', ValueNormalizer::roomMatchKey('sarana & humas'));
    }

    public function test_condition_mark_detection(): void
    {
        $this->assertTrue(ValueNormalizer::isConditionMark('v'));
        $this->assertTrue(ValueNormalizer::isConditionMark('V'));
        $this->assertFalse(ValueNormalizer::isConditionMark('-'));
        $this->assertFalse(ValueNormalizer::isConditionMark(''));
        $this->assertTrue(ValueNormalizer::isConditionBlank('-'));
        $this->assertTrue(ValueNormalizer::isConditionBlank(''));
        $this->assertFalse(ValueNormalizer::isConditionBlank('v'));
    }

    public function test_sequence_numeric_prefix_ignores_suffix(): void
    {
        $this->assertSame(5, ValueNormalizer::sequenceNumericPrefix('005A'));
        $this->assertSame(5, ValueNormalizer::sequenceNumericPrefix('005B'));
        $this->assertSame(17, ValueNormalizer::sequenceNumericPrefix('0017B'));
        $this->assertSame(1, ValueNormalizer::sequenceNumericPrefix('0001'));
        $this->assertNull(ValueNormalizer::sequenceNumericPrefix(''));
        $this->assertNull(ValueNormalizer::sequenceNumericPrefix('A'));
    }

    public function test_parse_date_handles_serial_text_indonesian_and_blank(): void
    {
        $this->assertSame('2023-09-06', ValueNormalizer::parseDate(45175)['date']);
        $this->assertSame('2023-05-30', ValueNormalizer::parseDate('30 Mei 2023')['date']);
        $this->assertSame('2019-08-01', ValueNormalizer::parseDate('1 Agustus 2019')['date']);
        $this->assertSame('2020-02-15', ValueNormalizer::parseDate('2020-02-15')['date']);

        $blank = ValueNormalizer::parseDate('');
        $this->assertNull($blank['date']);
        $this->assertTrue($blank['recognised']);

        $garbage = ValueNormalizer::parseDate('Kabid');
        $this->assertNull($garbage['date']);
        $this->assertFalse($garbage['recognised']); // caller should warn, not invent a date
    }

    public function test_clean_text_trims_and_nullifies_dash(): void
    {
        $this->assertNull(ValueNormalizer::cleanText('-'));
        $this->assertNull(ValueNormalizer::cleanText('   '));
        $this->assertNull(ValueNormalizer::cleanText(null));
        $this->assertSame('MEJA KERJA', ValueNormalizer::cleanText('  MEJA   KERJA '));
    }
}
