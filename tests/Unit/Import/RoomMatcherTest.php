<?php

namespace Tests\Unit\Import;

use App\Import\Matching\RoomMatcher;
use App\Import\Matching\RoomMatchResult;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Room;
use App\Models\RoomAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tahap 6.8.2 (Part B) — proves `RoomMatcher::load()`'s `is_active` filter:
 * an inactive room (exact-name or via an alias pointing to it) is no longer
 * a candidate for NEW import matching, while historical asset references are
 * completely unaffected (RoomMatcher is never consulted for an existing
 * asset — see `test_deactivating_a_room_never_touches_an_existing_asset`).
 *
 * Uses isolated factory-created test data throughout (RefreshDatabase);
 * never touches the real dev DB.
 */
class RoomMatcherTest extends TestCase
{
    use RefreshDatabase;

    /* ============================================================ active room matching (unchanged) */

    public function test_active_room_matches_by_exact_name(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create(['name' => 'Gudang']);

        $result = (new RoomMatcher)->match($location->code, 'Gudang');

        $this->assertSame($room->id, $result->roomId);
        $this->assertSame(RoomMatchResult::METHOD_EXACT_NAME, $result->method);
        $this->assertTrue($result->isMapped());
    }

    public function test_active_room_matches_via_alias(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        RoomAlias::factory()->forRoom($room)->create(['raw_value' => 'GDG', 'match_key' => 'GDG']);

        $result = (new RoomMatcher)->match($location->code, 'GDG');

        $this->assertSame($room->id, $result->roomId);
        $this->assertSame(RoomMatchResult::METHOD_ALIAS, $result->method);
    }

    /* ============================================================ inactive room excluded (Part B) */

    public function test_inactive_room_is_excluded_from_exact_name_matching(): void
    {
        $location = Location::factory()->create();
        Room::factory()->forLocation($location)->inactive()->create(['name' => 'Gudang Lama']);

        $result = (new RoomMatcher)->match($location->code, 'Gudang Lama');

        $this->assertNull($result->roomId);
        $this->assertSame(RoomMatchResult::METHOD_NONE, $result->method);
        $this->assertFalse($result->isMapped());
    }

    public function test_alias_pointing_to_an_inactive_room_does_not_resolve(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->inactive()->create();
        RoomAlias::factory()->forRoom($room)->create(['raw_value' => 'GDG LAMA', 'match_key' => 'GDG LAMA']);

        $result = (new RoomMatcher)->match($location->code, 'GDG LAMA');

        // treated as unresolved, exactly like an unknown room — never
        // silently assigned to the inactive room, never a new import status.
        $this->assertNull($result->roomId);
        $this->assertSame(RoomMatchResult::METHOD_NONE, $result->method);
        // the raw value itself is still preserved on the result for the caller
        // (RowParser/AssetPromoter keep it on the import row regardless of match).
        $this->assertSame('GDG LAMA', $result->rawValue);
    }

    public function test_deactivating_a_room_does_not_reactivate_or_recreate_it(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->inactive()->create(['name' => 'Gudang Lama']);
        $countBefore = Room::count();

        (new RoomMatcher)->match($location->code, 'Gudang Lama');

        $this->assertSame($countBefore, Room::count());
        $room->refresh();
        $this->assertFalse($room->is_active);
    }

    public function test_deactivating_a_room_never_touches_an_existing_asset(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        $asset = Asset::factory()->inRoom($room)->create();

        $room->update(['is_active' => false]);

        // RoomMatcher is only ever consulted for NEW import rows — it never
        // re-resolves or touches an already-created asset's room_id.
        $asset->refresh();
        $this->assertSame($room->id, $asset->room_id);
    }

    /* ============================================================ location scoping (unchanged) */

    public function test_same_room_name_active_in_one_location_inactive_in_another(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        Room::factory()->forLocation($locA)->create(['name' => 'Gudang']);
        Room::factory()->forLocation($locB)->inactive()->create(['name' => 'Gudang']);

        $resultA = (new RoomMatcher)->match($locA->code, 'Gudang');
        $resultB = (new RoomMatcher)->match($locB->code, 'Gudang');

        $this->assertTrue($resultA->isMapped());
        $this->assertFalse($resultB->isMapped());
    }
}
