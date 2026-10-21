<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * `GET /api/dashboard/rooms` — the Dashboard's "Aset per Ruangan" table:
 * server-side search, location filter, sort and pagination over the same rows
 * as `GET /api/dashboard`'s `by_room`.
 */
class DashboardRoomsApiTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private const URL = '/api/dashboard/rooms';

    private Subcategory $sub;

    /**
     *  02 (Lokasi Dua):  "Ruang Satu" 3 (one written off), "Gudang" 1 (+1 soft-deleted),
     *                    "Ruang Kosong" 0 (hidden), "Lab 100%_x" 1, roomless 1
     *  03 (Lokasi Tiga): "Ruang Satu" 2 (same name, different location), roomless 1
     */
    private function seedRooms(): void
    {
        $l2 = Location::factory()->create(['code' => '02', 'name' => 'Lokasi Dua']);
        $l3 = Location::factory()->create(['code' => '03', 'name' => 'Lokasi Tiga']);
        $cat = Category::factory()->create(['code' => 'KA', 'name' => 'Kategori Alpha']);
        $this->sub = Subcategory::factory()->forCategory($cat)->create(['code' => '001', 'name' => 'MEJA']);

        $satu2 = Room::factory()->forLocation($l2)->create(['name' => 'Ruang Satu']);
        $gudang = Room::factory()->forLocation($l2)->create(['name' => 'Gudang']);
        Room::factory()->forLocation($l2)->create(['name' => 'Ruang Kosong']);
        $lab = Room::factory()->forLocation($l2)->create(['name' => 'Lab 100%_x']);
        $satu3 = Room::factory()->forLocation($l3)->create(['name' => 'Ruang Satu']);

        Asset::factory()->count(2)->forSubcategory($this->sub)->inRoom($satu2)->create();
        Asset::factory()->forSubcategory($this->sub)->inRoom($satu2)->writtenOff('2024-01-01')->create();
        Asset::factory()->forSubcategory($this->sub)->inRoom($gudang)->create();
        Asset::factory()->forSubcategory($this->sub)->inRoom($gudang)->create()->delete();
        Asset::factory()->forSubcategory($this->sub)->inRoom($lab)->create();
        Asset::factory()->count(2)->forSubcategory($this->sub)->inRoom($satu3)->create();
        Asset::factory()->forSubcategory($this->sub)->create(['location_code' => '02', 'room_id' => null]);
        Asset::factory()->forSubcategory($this->sub)->create(['location_code' => '03', 'room_id' => null]);
    }

    /** @return list<string> "name@location_code=count" per row, in response order */
    private function rows(string $query = ''): array
    {
        return array_map(
            fn (array $r): string => "{$r['name']}@".($r['location_code'] ?? '-')."={$r['asset_count']}",
            $this->getJson(self::URL.$query)->assertOk()->json('data'),
        );
    }

    /* ------------------------------------------------------------------ authorization */

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_inactive_user_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Viewer, 'is_active' => false]));
        $this->getJson(self::URL)->assertStatus(403);
    }

    public function test_every_global_role_gets_200(): void
    {
        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin, UserRole::SuperAdmin] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson(self::URL)->assertOk();
        }
    }

    public function test_write_methods_are_405(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->postJson(self::URL)->assertStatus(405);
        $this->deleteJson(self::URL)->assertStatus(405);
    }

    /* ------------------------------------------------------------------ shape & default order */

    public function test_rows_and_meta_shape(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        $body = $this->getJson(self::URL)->assertOk()->json();

        $this->assertSame(['data', 'meta'], array_keys($body));
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 5], $body['meta']);
        $this->assertSame(['id', 'name', 'location_code', 'location_name', 'asset_count'], array_keys($body['data'][0]));
        $this->assertSame(
            ['id' => null, 'name' => 'Tanpa Ruangan', 'location_code' => null, 'location_name' => null, 'asset_count' => 2],
            end($body['data']),
        );
    }

    public function test_default_sort_is_most_assets_first_with_the_roomless_bucket_last(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        // written-off assets count, soft-deleted ones and empty rooms do not
        $this->assertSame(
            ['Ruang Satu@02=3', 'Ruang Satu@03=2', 'Gudang@02=1', 'Lab 100%_x@02=1', 'Tanpa Ruangan@-=2'],
            $this->rows(),
        );
    }

    public function test_sort_by_name(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        $this->assertSame(
            ['Gudang@02=1', 'Lab 100%_x@02=1', 'Ruang Satu@02=3', 'Ruang Satu@03=2', 'Tanpa Ruangan@-=2'],
            $this->rows('?sort=name'),
        );
    }

    public function test_rows_match_the_dashboard_by_room_totals(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        $byRoom = collect($this->getJson('/api/dashboard')->json('data.by_room'));
        $page = collect($this->getJson(self::URL.'?per_page=25')->json('data'));

        $this->assertSame($byRoom->sum('asset_count'), $page->sum('asset_count'));
        $this->assertSame($byRoom->count(), $page->count());
        $this->assertSame(9, $page->sum('asset_count'));
    }

    /* ------------------------------------------------------------------ search & filter */

    public function test_search_is_a_case_insensitive_substring_match_including_the_roomless_label(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        $this->assertSame(['Ruang Satu@02=3', 'Ruang Satu@03=2', 'Tanpa Ruangan@-=2'], $this->rows('?q=RUANG'));
        $this->assertSame(['Gudang@02=1'], $this->rows('?q=dan'));
        $this->assertSame([], $this->rows('?q=tidak-ada'));
    }

    public function test_like_wildcards_in_the_search_are_literal(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        $this->assertSame(['Lab 100%_x@02=1'], $this->rows('?q=%25'));
        $this->assertSame(['Lab 100%_x@02=1'], $this->rows('?q=_'));
    }

    public function test_location_filter_narrows_rooms_and_the_roomless_bucket(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        $this->assertSame(['Ruang Satu@03=2', 'Tanpa Ruangan@-=1'], $this->rows('?location_code=03'));
        $this->assertSame(['Ruang Satu@02=3'], $this->rows('?location_code=02&q=satu'));
    }

    public function test_blank_params_are_ignored(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        $this->assertCount(5, $this->rows('?q=&location_code=&sort=&per_page='));
    }

    /* ------------------------------------------------------------------ pagination */

    public function test_pagination_with_both_page_sizes(): void
    {
        $this->seedRooms();
        $l2 = Location::query()->find('02');
        foreach (range(1, 20) as $i) {
            $room = Room::factory()->forLocation($l2)->create(['name' => sprintf('Kelas %02d', $i)]);
            Asset::factory()->forSubcategory($this->sub)->inRoom($room)->create();
        }
        Sanctum::actingAs($this->viewer());

        $this->getJson(self::URL.'?sort=name&page=3')
            ->assertOk()
            ->assertJsonPath('meta', ['current_page' => 3, 'last_page' => 3, 'per_page' => 10, 'total' => 25])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.4.name', 'Tanpa Ruangan');

        $this->getJson(self::URL.'?per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonCount(25, 'data');

        // past the last page: empty page, same total
        $this->getJson(self::URL.'?page=9')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 25);
    }

    /* ------------------------------------------------------------------ validation */

    public function test_invalid_params_are_422(): void
    {
        $this->seedRooms();
        Sanctum::actingAs($this->viewer());

        foreach ([
            'per_page' => '?per_page=5',
            'per_page ' => '?per_page=100',
            'per_page  ' => '?per_page=abc',
            'sort' => '?sort=asset_count',
            'location_code' => '?location_code=99',
            'page' => '?page=0',
            'q' => '?q='.str_repeat('a', 101),
        ] as $field => $query) {
            $this->getJson(self::URL.$query)->assertStatus(422)->assertJsonValidationErrors(trim($field));
        }
    }

    /* ------------------------------------------------------------------ location scope */

    public function test_unit_admin_only_sees_their_own_location(): void
    {
        $this->seedRooms();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '02']));

        $this->assertSame(
            ['Ruang Satu@02=3', 'Gudang@02=1', 'Lab 100%_x@02=1', 'Tanpa Ruangan@-=1'],
            $this->rows(),
        );
        // asking for another unit's location yields nothing, never that unit's rooms
        $this->assertSame([], $this->rows('?location_code=03'));
    }

    /* ------------------------------------------------------------------ cost */

    public function test_query_count_does_not_grow_with_the_number_of_rooms(): void
    {
        $this->seedRooms();
        $user = $this->viewer();
        Sanctum::actingAs($user);

        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->getJson(self::URL)->assertOk();

            return $n;
        };

        $before = $count();
        $l3 = Location::query()->find('03');
        foreach (range(1, 15) as $i) {
            Asset::factory()->forSubcategory($this->sub)->inRoom(Room::factory()->forLocation($l3)->create())->create();
        }
        $this->assertSame($before, $count());
    }
}
