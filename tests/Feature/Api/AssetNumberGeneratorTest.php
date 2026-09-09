<?php

namespace Tests\Feature\Api;

use App\Services\Asset\AssetNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.4 §4–§6, §13, §28, §29, §32 — the sequence generator, tested directly
 * against MySQL (REGEXP_SUBSTR numeric-prefix extraction).
 */
class AssetNumberGeneratorTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private function generator(): AssetNumberGenerator
    {
        return app(AssetNumberGenerator::class);
    }

    public function test_first_sequence_in_an_empty_scope_is_001(): void
    {
        $this->scope();

        $this->assertSame('001', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_sequence_continues_from_the_numeric_max(): void
    {
        $this->existingAsset('001');
        $this->existingAsset('002');
        $this->existingAsset('003');

        $this->assertSame('004', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_letter_suffix_family_is_counted_by_numeric_prefix(): void
    {
        $this->existingAsset('005A');
        $this->existingAsset('005B');

        $this->assertSame('006', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_zero_padded_and_suffixed_values_compare_numerically(): void
    {
        // §32 fixture set: 001, 002, 005A, 005B, 0017B, 999  ->  next = 1000
        foreach (['001', '002', '005A', '005B', '0017B', '999'] as $seq) {
            $this->existingAsset($seq);
        }

        $this->assertSame('1000', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_lexical_max_would_be_wrong_but_numeric_max_is_used(): void
    {
        $this->existingAsset('0001');
        $this->existingAsset('002');
        $this->existingAsset('010');

        // lexical MAX('0001','002','010') = '810'-ish nonsense; numeric max = 10 -> 011
        $this->assertSame('011', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_values_above_999_drop_the_zero_padding(): void
    {
        $this->existingAsset('999');
        $this->assertSame('1000', $this->generator()->next('ZL', 'ZC', '001'));

        $this->existingAsset('1000');
        $this->assertSame('1001', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_year_is_not_part_of_the_scope(): void
    {
        $this->existingAsset('001', year: 2019);
        $this->existingAsset('002', year: 2024);

        // regardless of the year of the next asset
        $this->assertSame('003', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_scope_is_location_category_subcategory_independent(): void
    {
        $this->existingAsset('001', loc: 'ZL', cat: 'ZC', sub: '001');
        $this->existingAsset('002', loc: 'ZL', cat: 'ZC', sub: '001');

        $this->scope('ZM', 'ZC', '001');
        $this->scope('ZL', 'ZD', '001');
        $this->scope('ZL', 'ZC', '002');

        $this->assertSame('001', $this->generator()->next('ZM', 'ZC', '001'), 'different location');
        $this->assertSame('001', $this->generator()->next('ZL', 'ZD', '001'), 'different category');
        $this->assertSame('001', $this->generator()->next('ZL', 'ZC', '002'), 'different subcategory');
    }

    public function test_soft_deleted_assets_still_consume_their_sequence(): void
    {
        $this->existingAsset('001');
        $this->existingAsset('002');
        $deleted = $this->existingAsset('003');
        $deleted->delete();

        $this->assertSame('004', $this->generator()->next('ZL', 'ZC', '001'));
    }

    public function test_non_numeric_sequences_do_not_crash_the_query(): void
    {
        $this->existingAsset('ABC');
        $this->existingAsset('005');

        $this->assertSame('006', $this->generator()->next('ZL', 'ZC', '001'));
    }
}
