<?php

namespace Tests\Feature\Models;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tahap 5.1 — verify the ACTUAL database composite constraints reject bad data
 * (not just model metadata). These are the guarantees the models rely on instead of
 * fake composite Eloquent relationships.
 */
class CompositeConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_an_asset_whose_room_is_in_another_location(): void
    {
        $loc01 = Location::factory()->create(['code' => '01']);
        $loc03 = Location::factory()->create(['code' => '03']);
        $category = Category::factory()->create(['code' => '02']);
        $subcategory = Subcategory::factory()->forCategory($category)->create(['code' => '001']);
        $roomInLoc03 = Room::factory()->forLocation($loc03)->create();

        $this->expectException(QueryException::class);

        // asset is in location 01 but points at a room that belongs to location 03
        Asset::factory()->forSubcategory($subcategory)->create([
            'location_code' => '01',
            'room_id' => $roomInLoc03->id,
        ]);
    }

    public function test_database_accepts_an_asset_whose_room_matches_its_location(): void
    {
        $loc = Location::factory()->create(['code' => '01']);
        $category = Category::factory()->create(['code' => '02']);
        $subcategory = Subcategory::factory()->forCategory($category)->create(['code' => '001']);
        $room = Room::factory()->forLocation($loc)->create();

        $asset = Asset::factory()->forSubcategory($subcategory)->inRoom($room)->create();

        $this->assertSame($room->id, $asset->fresh()->room_id);
    }

    public function test_database_rejects_an_invalid_category_subcategory_pair(): void
    {
        $loc = Location::factory()->create(['code' => '01']);
        $category = Category::factory()->create(['code' => '02']);
        Subcategory::factory()->forCategory($category)->create(['code' => '001']);

        $this->expectException(QueryException::class);

        // (02, 999) is not a real subcategory pair
        Asset::factory()->create([
            'location_code' => '01',
            'category_code' => '02',
            'subcategory_code' => '999',
        ]);
    }

    public function test_database_rejects_quantity_other_than_one(): void
    {
        $loc = Location::factory()->create(['code' => '01']);
        $category = Category::factory()->create(['code' => '02']);
        $subcategory = Subcategory::factory()->forCategory($category)->create(['code' => '001']);

        $this->expectException(QueryException::class);

        Asset::factory()->forSubcategory($subcategory)->create([
            'location_code' => '01',
            'quantity' => 3,
        ]);
    }

    public function test_database_rejects_asset_year_out_of_range(): void
    {
        $loc = Location::factory()->create(['code' => '01']);
        $category = Category::factory()->create(['code' => '02']);
        $subcategory = Subcategory::factory()->forCategory($category)->create(['code' => '001']);

        $this->expectException(QueryException::class);

        Asset::factory()->forSubcategory($subcategory)->create([
            'location_code' => '01',
            'asset_year' => 1500,
        ]);
    }

    public function test_database_rejects_a_duplicate_business_identity(): void
    {
        $loc = Location::factory()->create(['code' => '01']);
        $category = Category::factory()->create(['code' => '02']);
        $subcategory = Subcategory::factory()->forCategory($category)->create(['code' => '001']);

        Asset::factory()->forSubcategory($subcategory)->create([
            'location_code' => '01', 'sequence_no' => '005A', 'asset_year' => 2020,
        ]);

        $this->expectException(QueryException::class);

        Asset::factory()->forSubcategory($subcategory)->create([
            'location_code' => '01', 'sequence_no' => '005A', 'asset_year' => 2020,
        ]);
    }

    public function test_room_alias_cannot_point_at_a_room_in_another_location(): void
    {
        $loc01 = Location::factory()->create(['code' => '01']);
        $loc03 = Location::factory()->create(['code' => '03']);
        $roomInLoc03 = Room::factory()->forLocation($loc03)->create();

        $this->expectException(QueryException::class);

        DB::table('room_aliases')->insert([
            'location_code' => '01',
            'raw_value' => 'GUDANG',
            'match_key' => 'GUDANG',
            'room_id' => $roomInLoc03->id,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
