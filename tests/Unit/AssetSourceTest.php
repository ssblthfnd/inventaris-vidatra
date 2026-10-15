<?php

namespace Tests\Unit;

use App\Enums\AssetSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tahap 2 — the AssetSource enum: stable stored values, UI labels, and the
 * lenient-but-never-guessing input normalisation used by forms and the importer.
 */
class AssetSourceTest extends TestCase
{
    public function test_the_six_stored_values_are_stable_and_in_db_enum_order(): void
    {
        $this->assertSame(
            ['bosda', 'bosp', 'bostk', 'yayasan', 'lainnya', 'belum_diberi_keterangan'],
            AssetSource::values(),
        );
        $this->assertSame(AssetSource::BelumDiberiKeterangan, AssetSource::DEFAULT);
    }

    public function test_labels(): void
    {
        $this->assertSame(
            ['BOSDA', 'BOSP', 'BOSTK', 'Yayasan', 'Lainnya', 'Belum diberi keterangan'],
            array_map(fn (AssetSource $s) => $s->label(), AssetSource::cases()),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function recognisedInputProvider(): array
    {
        return [
            'value' => ['bosda', 'bosda'],
            'upper label' => ['BOSDA', 'bosda'],
            'padded' => ['  bosp  ', 'bosp'],
            'mixed case label' => ['YaYaSaN', 'yayasan'],
            'label with spaces' => ['Belum diberi keterangan', 'belum_diberi_keterangan'],
            'collapsed whitespace' => ["Belum \t diberi   KETERANGAN", 'belum_diberi_keterangan'],
            'slug' => ['belum_diberi_keterangan', 'belum_diberi_keterangan'],
            'lainnya' => ['LAINNYA', 'lainnya'],
        ];
    }

    #[DataProvider('recognisedInputProvider')]
    public function test_from_input_normalises_recognised_values(string $input, string $expected): void
    {
        $this->assertSame($expected, AssetSource::fromInput($input)?->value);
    }

    public function test_from_input_never_guesses_and_never_maps_to_yayasan(): void
    {
        foreach ([null, '', '   ', 'YYS', 'Yayasan Lama', 'bos', 'dana desa', 'belum'] as $input) {
            $this->assertNull(AssetSource::fromInput($input), var_export($input, true));
        }
    }

    public function test_canonical_or_original_leaves_unrecognised_input_for_validation_to_reject(): void
    {
        $this->assertSame('bostk', AssetSource::canonicalOrOriginal(' BOSTK '));
        $this->assertSame('xyz', AssetSource::canonicalOrOriginal('xyz'));
        $this->assertNull(AssetSource::canonicalOrOriginal(null));
        $this->assertSame(['bosda'], AssetSource::canonicalOrOriginal(['bosda']));
    }
}
