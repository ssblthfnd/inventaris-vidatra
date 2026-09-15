<?php

namespace Tests\Unit\Import;

use App\Import\Validation\MasterData;
use App\Models\Category;
use App\Models\Location;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tahap 6.8.2 (Part B) — proves `MasterData`'s three snapshot queries
 * (locations/categories/subcategory pairs) only consider active rows, so an
 * import row referencing a deactivated location/category/subcategory is
 * correctly rejected (`invalid_location`/`invalid_category`/
 * `invalid_subcategory`) rather than silently accepted.
 */
class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_location_is_recognised(): void
    {
        $location = Location::factory()->create();

        $this->assertTrue((new MasterData)->hasLocation($location->code));
    }

    public function test_inactive_location_is_not_recognised(): void
    {
        $location = Location::factory()->inactive()->create();

        $this->assertFalse((new MasterData)->hasLocation($location->code));
    }

    public function test_active_category_is_recognised(): void
    {
        $category = Category::factory()->create();

        $this->assertTrue((new MasterData)->hasCategory($category->code));
    }

    public function test_inactive_category_is_not_recognised(): void
    {
        $category = Category::factory()->inactive()->create();

        $this->assertFalse((new MasterData)->hasCategory($category->code));
    }

    public function test_active_subcategory_pair_is_recognised(): void
    {
        $subcategory = Subcategory::factory()->create();

        $master = new MasterData;
        $this->assertTrue($master->hasSubcategoryPair($subcategory->category_code, $subcategory->code));
    }

    public function test_inactive_subcategory_pair_is_not_recognised(): void
    {
        $subcategory = Subcategory::factory()->inactive()->create();

        $master = new MasterData;
        $this->assertFalse($master->hasSubcategoryPair($subcategory->category_code, $subcategory->code));
    }

    public function test_unknown_codes_remain_unrecognised(): void
    {
        $master = new MasterData;

        $this->assertFalse($master->hasLocation('ZZ'));
        $this->assertFalse($master->hasCategory('ZZ'));
        $this->assertFalse($master->hasSubcategoryPair('ZZ', 'ZZZ'));
        $this->assertFalse($master->hasLocation(null));
    }
}
