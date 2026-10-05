<?php

namespace Tests\Feature\Api;

use App\Enums\AssetCondition;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.9 R9.4-15 (D5) — Dashboard & Reports semantics.
 *
 *   D15-1  Reports exposes total / active / written-off; its breakdowns keep
 *          counting the TOTAL population (written-off included).
 *   D15-2  Dashboard "Total Aset" keeps meaning all non-deleted assets.
 *   D15-3  `by_room` ends with a virtual "Tanpa Ruangan" bucket (room_id NULL).
 *   D15-4  master-data cards count active master rows (rows carry `is_active`).
 *   D15-5  an inactive location/category that still holds assets stays listed.
 *
 * Invariant pinned throughout: every breakdown sums to `summary.total_assets`.
 */
class Stage698DashboardReportSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private Subcategory $meja;

    private Subcategory $laptop;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['01', '02', '03', '04'] as $code) {
            Location::query()->firstOrCreate(['code' => $code], ['name' => "Lokasi {$code}", 'is_active' => true]);
        }
        Category::query()->firstOrCreate(['code' => '02'], ['name' => 'Meubelair', 'is_active' => true]);
        Category::query()->firstOrCreate(['code' => '03'], ['name' => 'Elektronik', 'is_active' => true]);
        $this->meja = Subcategory::query()->firstOrCreate(['category_code' => '02', 'code' => '001'], ['name' => 'Meja', 'is_active' => true]);
        $this->laptop = Subcategory::query()->firstOrCreate(['category_code' => '03', 'code' => '020'], ['name' => 'Laptop', 'is_active' => true]);
    }

    /* ================================================================== fixtures */

    private function user(UserRole $role, ?string $locationCode = null): User
    {
        return User::factory()->create(['role' => $role, 'location_code' => $locationCode]);
    }

    private function asset(string $locationCode, array $overrides = [], ?Subcategory $subcategory = null): Asset
    {
        return Asset::factory()
            ->forSubcategory($subcategory ?? $this->meja)
            ->create(array_merge(['location_code' => $locationCode, 'room_id' => null], $overrides));
    }

    /**
     * 3 active + 2 written-off in 02; one written-off and one active asset have no room.
     *
     * @return array{room: Room}
     */
    private function mixedPopulation(): array
    {
        $room = Room::factory()->create(['location_code' => '02', 'name' => 'Ruang Guru']);
        $this->asset('02', ['room_id' => $room->id, 'asset_year' => 2020, 'condition' => AssetCondition::Baik]);
        $this->asset('02', ['room_id' => $room->id, 'asset_year' => 2021, 'condition' => AssetCondition::KurangBaik], $this->laptop);
        $this->asset('02', ['asset_year' => 2021, 'condition' => null]);
        $this->asset('02', ['room_id' => $room->id, 'asset_year' => 2020, 'is_written_off' => true, 'written_off_on' => '2024-01-01', 'condition' => AssetCondition::RusakBerat]);
        $this->asset('02', ['asset_year' => 2022, 'is_written_off' => true, 'written_off_on' => '2024-02-01', 'condition' => AssetCondition::Baik]);
        $this->asset('02')->delete(); // soft-deleted: outside every number

        return ['room' => $room];
    }

    private function dashboard(): array
    {
        return $this->getJson('/api/dashboard')->assertOk()->json('data');
    }

    private function report(string $query = ''): array
    {
        return $this->getJson('/api/reports/inventory'.($query !== '' ? "?{$query}" : ''))->assertOk()->json('data');
    }

    /** Every breakdown of a dashboard/report payload must add up to its total. */
    private function assertBreakdownsSumTo(int $total, array $data, bool $isReport): void
    {
        $sum = fn (array $rows): int => (int) collect($rows)->sum('asset_count');
        $this->assertSame($total, $sum($data['by_location']), 'by_location');
        $this->assertSame($total, $sum($data['by_category']), 'by_category');
        $this->assertSame($total, $sum($data['by_room']), 'by_room incl. Tanpa Ruangan');
        $this->assertSame($total, array_sum($isReport ? $data['by_condition'] : $data['summary']['by_condition']), 'by_condition');
        if ($isReport) {
            $this->assertSame($total, $sum($data['by_year']), 'by_year');
        }
    }

    private function roomless(array $byRoom): ?array
    {
        return collect($byRoom)->first(fn (array $r) => $r['id'] === null);
    }

    /* ================================================================== D15-1 / D15-2 */

    public function test_reports_expose_three_populations_and_breakdowns_count_the_total(): void
    {
        $this->mixedPopulation();
        Sanctum::actingAs($this->user(UserRole::Operator));

        $data = $this->report();
        $this->assertSame(['total_assets' => 5, 'active_assets' => 3, 'written_off_assets' => 2], $data['summary']);
        $this->assertBreakdownsSumTo(5, $data, isReport: true);
        // the breakdowns are the TOTAL population, explicitly not the active one
        $this->assertNotSame($data['summary']['active_assets'], (int) collect($data['by_location'])->sum('asset_count'));
    }

    public function test_reports_status_aktif_filter_still_narrows_everything_to_active(): void
    {
        $this->mixedPopulation();
        Sanctum::actingAs($this->user(UserRole::Operator));

        $data = $this->report('is_written_off[]=0');
        $this->assertSame(['total_assets' => 3, 'active_assets' => 3, 'written_off_assets' => 0], $data['summary']);
        $this->assertBreakdownsSumTo(3, $data, isReport: true);
    }

    public function test_dashboard_total_still_includes_written_off_and_every_breakdown_reconciles(): void
    {
        $this->mixedPopulation();
        Sanctum::actingAs($this->user(UserRole::Viewer));

        $data = $this->dashboard();
        $this->assertSame(5, $data['summary']['total_assets']);
        $this->assertSame(2, $data['summary']['written_off']);
        $this->assertBreakdownsSumTo(5, $data, isReport: false);
    }

    /* ================================================================== D15-3 roomless bucket */

    public function test_roomless_bucket_follows_the_real_rooms_on_both_endpoints(): void
    {
        $roomB = Room::factory()->create(['location_code' => '02', 'name' => 'B Ruang']);
        $roomA = Room::factory()->create(['location_code' => '02', 'name' => 'A Ruang']);
        $this->asset('02', ['room_id' => $roomB->id]);
        $this->asset('02', ['room_id' => $roomA->id]);
        $this->asset('02');
        Sanctum::actingAs($this->user(UserRole::Operator));

        foreach ([$this->dashboard()['by_room'], $this->report()['by_room']] as $byRoom) {
            // real rooms keep their ordering (location, name, id); the bucket is last
            $this->assertSame(['A Ruang', 'B Ruang', 'Tanpa Ruangan'], array_column($byRoom, 'name'));
            $this->assertSame(
                ['id' => null, 'name' => 'Tanpa Ruangan', 'location_code' => null, 'location_name' => null, 'asset_count' => 1],
                end($byRoom),
            );
        }
    }

    public function test_no_roomless_bucket_when_every_asset_has_a_room_and_only_the_bucket_when_none_has(): void
    {
        $room = Room::factory()->create(['location_code' => '02']);
        $this->asset('02', ['room_id' => $room->id]);
        Sanctum::actingAs($this->user(UserRole::Operator));

        $this->assertNull($this->roomless($this->dashboard()['by_room']));
        $this->assertNull($this->roomless($this->report()['by_room']));

        Asset::query()->update(['room_id' => null]);
        $this->asset('03');

        foreach ([$this->dashboard(), $this->report()] as $data) {
            $this->assertSame(['Tanpa Ruangan'], array_column($data['by_room'], 'name'));
            $this->assertSame(2, $data['by_room'][0]['asset_count']);
        }
    }

    public function test_report_room_filters_compose_with_the_bucket(): void
    {
        ['room' => $room] = $this->mixedPopulation();
        Sanctum::actingAs($this->user(UserRole::Operator));

        $this->assertSame([$room->id], array_column($this->report("room_id[]={$room->id}")['by_room'], 'id'));
        $onlyRoomless = $this->report('room_id[]=none');
        $this->assertSame([null], array_column($onlyRoomless['by_room'], 'id'));
        $this->assertSame(2, $onlyRoomless['summary']['total_assets']);
        $this->assertBreakdownsSumTo(2, $onlyRoomless, isReport: true);
    }

    /* ================================================================== D15-4 / D15-5 inactive master rows */

    public function test_inactive_location_with_assets_stays_listed_and_empty_inactive_one_does_not(): void
    {
        $this->asset('02');
        $this->asset('03');
        $this->asset('03');
        Location::query()->whereIn('code', ['03', '04'])->update(['is_active' => false]); // 03 holds assets, 04 is empty
        Sanctum::actingAs($this->user(UserRole::Operator));

        foreach ([[$this->dashboard(), false], [$this->report(), true]] as [$data, $isReport]) {
            $rows = collect($data['by_location'])->keyBy('code');
            $this->assertSame(['01', '02', '03'], $rows->keys()->all(), 'empty inactive 04 stays hidden');
            $this->assertSame([false, 2], [$rows['03']['is_active'], $rows['03']['asset_count']]);
            $this->assertTrue($rows['02']['is_active']);
            // the "Lokasi Terdaftar" card counts active master rows only (01, 02)
            $this->assertSame(2, $rows->where('is_active', true)->count());
            $this->assertBreakdownsSumTo(3, $data, $isReport);
        }
    }

    public function test_inactive_category_with_assets_stays_listed_and_empty_inactive_one_does_not(): void
    {
        $this->asset('02');
        $this->asset('02', [], $this->laptop);
        Category::query()->firstOrCreate(['code' => '06'], ['name' => 'Alat Kebersihan', 'is_active' => false]);
        Category::query()->where('code', '03')->update(['is_active' => false]); // holds the laptop
        Sanctum::actingAs($this->user(UserRole::Operator));

        foreach ([[$this->dashboard(), false], [$this->report(), true]] as [$data, $isReport]) {
            $rows = collect($data['by_category'])->keyBy('code');
            $this->assertSame(['02', '03'], $rows->keys()->all(), 'empty inactive 06 stays hidden');
            $this->assertSame([false, 1], [$rows['03']['is_active'], $rows['03']['asset_count']]);
            $this->assertSame(1, $rows->where('is_active', true)->count());
            $this->assertBreakdownsSumTo(2, $data, $isReport);
        }
    }

    /* ================================================================== scope */

    public function test_unit_admin_aggregates_never_carry_foreign_counts(): void
    {
        $room02 = Room::factory()->create(['location_code' => '02']);
        $room03 = Room::factory()->create(['location_code' => '03']);
        $this->asset('02', ['room_id' => $room02->id]);
        $this->asset('02');                                     // own roomless
        $this->asset('03');                                     // foreign roomless
        $this->asset('03', ['room_id' => $room03->id], $this->laptop);
        $this->asset('04', [], $this->laptop);                  // foreign, in a location deactivated below
        Location::query()->where('code', '04')->update(['is_active' => false]);
        Category::query()->where('code', '03')->update(['is_active' => false]); // only foreign assets use it

        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        foreach ([[$this->dashboard(), false], [$this->report(), true]] as [$data, $isReport]) {
            $this->assertSame(2, $data['summary']['total_assets']);
            $this->assertBreakdownsSumTo(2, $data, $isReport);

            /** @var Collection $locations */
            $locations = collect($data['by_location'])->keyBy('code');
            $this->assertArrayNotHasKey('04', $locations->all(), 'foreign inactive location never surfaces');
            $this->assertSame(0, $locations['03']['asset_count']);
            $this->assertArrayNotHasKey('03', collect($data['by_category'])->keyBy('code')->all(), 'category used only abroad never surfaces');
            $this->assertSame([$room02->id, null], array_column($data['by_room'], 'id'));
            $this->assertSame(1, $this->roomless($data['by_room'])['asset_count'], 'only own roomless asset');
        }

        // a global actor sees everything, inactive rows included
        Sanctum::actingAs($this->user(UserRole::SuperAdmin));
        $global = $this->dashboard();
        $this->assertSame(5, $global['summary']['total_assets']);
        $this->assertBreakdownsSumTo(5, $global, isReport: false);
        $this->assertSame(3, $this->roomless($global['by_room'])['asset_count']); // 02 + 03 + 04 roomless
        $this->assertFalse(collect($global['by_location'])->keyBy('code')['04']['is_active']);
    }
}
