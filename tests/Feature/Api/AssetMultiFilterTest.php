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
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 5.8.1 — multi-value filters on `GET /api/assets`.
 *
 * Contract: OR within one filter, AND between filters. Bare scalar filters
 * (`?category_code=02`) keep working — that is covered by {@see AssetReadApiTest};
 * here every filter is exercised with the `?x[]=` array form.
 */
class AssetMultiFilterTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => $active]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<int, int> */
    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data'), 'id');
    }

    // ------------------------------------------------------------- single value still works

    public function test_single_category_array_form_still_works(): void
    {
        $category = Category::factory()->create(['code' => 'CA']);
        $sub = Subcategory::factory()->forCategory($category)->create();
        Asset::factory()->count(2)->forSubcategory($sub)->create();
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?category_code[]=CA')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------- multiple values (OR within)

    public function test_multiple_categories_are_ored(): void
    {
        $catA = Category::factory()->create(['code' => 'M1']);
        $catB = Category::factory()->create(['code' => 'M2']);
        $catC = Category::factory()->create(['code' => 'M3']);
        $a = Asset::factory()->forSubcategory(Subcategory::factory()->forCategory($catA)->create())->create();
        $b = Asset::factory()->forSubcategory(Subcategory::factory()->forCategory($catB)->create())->create();
        Asset::factory()->forSubcategory(Subcategory::factory()->forCategory($catC)->create())->create();

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/assets?category_code[]=M1&category_code[]=M2')->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($response));
    }

    public function test_multiple_locations_are_ored(): void
    {
        Location::factory()->create(['code' => 'P1']);
        Location::factory()->create(['code' => 'P2']);
        Location::factory()->create(['code' => 'P3']);
        Asset::factory()->count(2)->create(['location_code' => 'P1']);
        Asset::factory()->create(['location_code' => 'P2']);
        Asset::factory()->create(['location_code' => 'P3']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?location_code[]=P1&location_code[]=P2')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_multiple_conditions_are_ored(): void
    {
        Asset::factory()->count(2)->create(['condition' => AssetCondition::Baik]);
        Asset::factory()->count(3)->create(['condition' => AssetCondition::RusakBerat]);
        Asset::factory()->create(['condition' => AssetCondition::KurangBaik]);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?condition[]=baik&condition[]=rusak_berat')
            ->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_condition_unknown_sentinel_matches_null_condition(): void
    {
        Asset::factory()->count(2)->unknownCondition()->create();
        Asset::factory()->count(3)->create(['condition' => AssetCondition::Baik]);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?condition[]=unknown')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/assets?condition[]=baik&condition[]=unknown')
            ->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_multiple_statuses_selected_together_is_a_no_op(): void
    {
        Asset::factory()->count(2)->writtenOff()->create();
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        // both -> "all"
        $this->getJson('/api/assets?is_written_off[]=1&is_written_off[]=0')
            ->assertOk()->assertJsonCount(5, 'data');

        // just one still filters
        $this->getJson('/api/assets?is_written_off[]=1')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_multiple_asset_years_use_in(): void
    {
        Asset::factory()->count(2)->create(['asset_year' => 2019]);
        Asset::factory()->count(3)->create(['asset_year' => 2021]);
        Asset::factory()->create(['asset_year' => 2023]);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?asset_year[]=2019&asset_year[]=2021')
            ->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_multiple_rooms_are_ored(): void
    {
        $location = Location::factory()->create(['code' => 'RL']);
        $r1 = Room::factory()->forLocation($location)->create();
        $r2 = Room::factory()->forLocation($location)->create();
        $r3 = Room::factory()->forLocation($location)->create();
        Asset::factory()->count(2)->inRoom($r1)->create();
        Asset::factory()->inRoom($r2)->create();
        Asset::factory()->inRoom($r3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets?room_id[]={$r1->id}&room_id[]={$r2->id}")
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_multiple_subcategories_are_ored_within_a_category(): void
    {
        $category = Category::factory()->create(['code' => 'SC']);
        $s1 = Subcategory::factory()->forCategory($category)->create(['code' => '001']);
        $s2 = Subcategory::factory()->forCategory($category)->create(['code' => '002']);
        $s3 = Subcategory::factory()->forCategory($category)->create(['code' => '003']);
        Asset::factory()->count(2)->forSubcategory($s1)->create();
        Asset::factory()->forSubcategory($s2)->create();
        Asset::factory()->forSubcategory($s3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?category_code[]=SC&subcategory_code[]=001&subcategory_code[]=002')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    // ------------------------------------------------------------- composite subcategory correctness

    public function test_subcategory_filter_is_category_aware_across_multiple_categories(): void
    {
        // The dev-data hazard: categories 02 & 03 both own a subcategory code "001".
        $catMeubel = Category::factory()->create(['code' => 'D2']);
        $catElektro = Category::factory()->create(['code' => 'D3']);
        $meja = Subcategory::factory()->forCategory($catMeubel)->create(['code' => '001', 'name' => 'MEJA']);
        $komputer = Subcategory::factory()->forCategory($catElektro)->create(['code' => '001', 'name' => 'KOMPUTER']);

        $mejaAsset = Asset::factory()->forSubcategory($meja)->create();
        $komputerAsset = Asset::factory()->forSubcategory($komputer)->create();

        $this->actingAsRole(UserRole::Viewer);

        // Both categories + bare code 001 -> both assets (both are legitimately "001").
        $both = $this->getJson('/api/assets?category_code[]=D2&category_code[]=D3&subcategory_code[]=001')->assertOk();
        $this->assertEqualsCanonicalizing([$mejaAsset->id, $komputerAsset->id], $this->ids($both));

        // Category-qualified 02.001 -> ONLY the Meubelair meja, never the Elektronik komputer.
        $mejaOnly = $this->getJson('/api/assets?category_code[]=D2&category_code[]=D3&subcategory_code[]=D2.001')->assertOk();
        $mejaOnly->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mejaAsset->id);
    }

    public function test_bare_subcategory_without_category_resolves_every_owning_category(): void
    {
        $catA = Category::factory()->create(['code' => 'E2']);
        $catB = Category::factory()->create(['code' => 'E3']);
        $subA = Subcategory::factory()->forCategory($catA)->create(['code' => '007']);
        $subB = Subcategory::factory()->forCategory($catB)->create(['code' => '007']);
        $a = Asset::factory()->forSubcategory($subA)->create();
        $b = Asset::factory()->forSubcategory($subB)->create();
        Asset::factory()->create();

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/assets?subcategory_code[]=007')->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($response));
    }

    public function test_qualified_subcategory_outside_selected_category_is_rejected(): void
    {
        $catA = Category::factory()->create(['code' => 'F2']);
        $catB = Category::factory()->create(['code' => 'F3']);
        Subcategory::factory()->forCategory($catB)->create(['code' => '001']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?category_code[]=F2&subcategory_code[]=F3.001')
            ->assertStatus(422)->assertJsonValidationErrors('subcategory_code');
    }

    // ------------------------------------------------------------- room + location correctness

    public function test_room_and_location_together_stay_consistent(): void
    {
        $locA = Location::factory()->create(['code' => 'G1']);
        $locB = Location::factory()->create(['code' => 'G2']);
        $roomA = Room::factory()->forLocation($locA)->create();
        $roomB = Room::factory()->forLocation($locB)->create();

        $inA = Asset::factory()->inRoom($roomA)->create();
        Asset::factory()->inRoom($roomB)->create();

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson("/api/assets?location_code[]=G1&room_id[]={$roomA->id}")->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inA->id);
    }

    public function test_room_outside_selected_location_is_rejected(): void
    {
        $locA = Location::factory()->create(['code' => 'H1']);
        $locB = Location::factory()->create(['code' => 'H2']);
        $roomB = Room::factory()->forLocation($locB)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets?location_code[]=H1&room_id[]={$roomB->id}")
            ->assertStatus(422)->assertJsonValidationErrors('room_id');
    }

    public function test_room_without_location_filter_is_allowed_directly(): void
    {
        $room = Room::factory()->create();
        Asset::factory()->count(2)->inRoom($room)->create();
        Asset::factory()->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets?room_id[]={$room->id}")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------- AND between filters

    public function test_and_between_different_filters(): void
    {
        $catA = Category::factory()->create(['code' => 'I1']);
        $catB = Category::factory()->create(['code' => 'I2']);
        $subA = Subcategory::factory()->forCategory($catA)->create();
        $subB = Subcategory::factory()->forCategory($catB)->create();

        // (cat I1 OR I2) AND (condition baik)
        $match = Asset::factory()->forSubcategory($subA)->create(['condition' => AssetCondition::Baik]);
        Asset::factory()->forSubcategory($subB)->create(['condition' => AssetCondition::RusakBerat]); // wrong condition
        Asset::factory()->create(['condition' => AssetCondition::Baik]); // wrong category

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/assets?category_code[]=I1&category_code[]=I2&condition[]=baik')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
    }

    // ------------------------------------------------------------- duplicate / invalid array values

    public function test_duplicate_array_values_are_rejected(): void
    {
        $category = Category::factory()->create(['code' => 'J1']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?category_code[]=J1&category_code[]=J1')
            ->assertStatus(422)->assertJsonValidationErrors('category_code');
    }

    public function test_invalid_array_values_are_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?location_code[]=ZZ')
            ->assertStatus(422)->assertJsonValidationErrors('location_code');

        $this->getJson('/api/assets?condition[]=baik&condition[]=maybe')
            ->assertStatus(422)->assertJsonValidationErrors('condition');

        $this->getJson('/api/assets?asset_year[]=2020&asset_year[]=999999')
            ->assertStatus(422)->assertJsonValidationErrors('asset_year');

        $this->getJson('/api/assets?is_written_off[]=yes')
            ->assertStatus(422)->assertJsonValidationErrors('is_written_off');
    }

    // ------------------------------------------------------------- search + filter + paging + sort

    public function test_search_combined_with_multi_filter(): void
    {
        $catA = Category::factory()->create(['code' => 'K1']);
        $catB = Category::factory()->create(['code' => 'K2']);
        $subA = Subcategory::factory()->forCategory($catA)->create();
        $subB = Subcategory::factory()->forCategory($catB)->create();

        $wanted = Asset::factory()->forSubcategory($subA)->create(['brand_model' => 'Epson EcoTank L3210']);
        Asset::factory()->forSubcategory($subB)->create(['brand_model' => 'Epson EcoTank L3210']); // right name, wrong category filter later
        Asset::factory()->forSubcategory($subA)->create(['brand_model' => 'Something else']);

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/assets?category_code[]=K1&q=epson ecotank')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_filter_with_pagination(): void
    {
        $category = Category::factory()->create(['code' => 'L1']);
        $sub = Subcategory::factory()->forCategory($category)->create();
        Asset::factory()->count(5)->forSubcategory($sub)->create();
        Asset::factory()->count(4)->create();

        $this->actingAsRole(UserRole::Viewer);

        $page1 = $this->getJson('/api/assets?category_code[]=L1&per_page=2&page=1')->assertOk();
        $page1->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);

        $this->getJson('/api/assets?category_code[]=L1&per_page=2&page=3')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 3);
    }

    public function test_filter_with_sorting(): void
    {
        $category = Category::factory()->create(['code' => 'N1']);
        $sub = Subcategory::factory()->forCategory($category)->create();
        Asset::factory()->forSubcategory($sub)->create(['asset_year' => 2015]);
        Asset::factory()->forSubcategory($sub)->create(['asset_year' => 2022]);
        Asset::factory()->forSubcategory($sub)->create(['asset_year' => 2018]);
        Asset::factory()->create(['asset_year' => 2000 + 1]);

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/assets?category_code[]=N1&sort=asset_year&direction=desc')->assertOk();
        $this->assertSame([2022, 2018, 2015], array_column($response->json('data'), 'asset_year'));
    }

    public function test_no_results_is_a_200_with_empty_data(): void
    {
        Category::factory()->create(['code' => 'O1']);
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?category_code[]=O1')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    // ------------------------------------------------------------- authorization unchanged

    public function test_authorization_is_unchanged_for_multi_filter_requests(): void
    {
        Asset::factory()->create();

        $this->getJson('/api/assets?condition[]=baik')->assertStatus(401);

        $this->actingAsRole(UserRole::Viewer, active: false);
        $this->getJson('/api/assets?condition[]=baik')->assertStatus(403);

        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/assets?condition[]=baik')->assertOk();
        }
    }

    // ------------------------------------------------------------- N+1 stays flat

    public function test_multi_filter_does_not_introduce_per_row_queries(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $category = Category::factory()->create(['code' => 'Q1']);
        $subs = collect(['001', '002', '003'])->map(
            fn ($c) => Subcategory::factory()->forCategory($category)->create(['code' => $c]),
        );

        $make = function (int $n) use ($subs): void {
            for ($i = 0; $i < $n; $i++) {
                Asset::factory()->forSubcategory($subs->random())->create();
            }
        };

        $count = function (callable $cb): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $cb();

            return $n;
        };

        $make(10);
        $small = $count(fn () => $this->getJson('/api/assets?category_code[]=Q1&subcategory_code[]=001&subcategory_code[]=002&subcategory_code[]=003&per_page=100')->assertOk());

        $make(30);
        $large = $count(fn () => $this->getJson('/api/assets?category_code[]=Q1&subcategory_code[]=001&subcategory_code[]=002&subcategory_code[]=003&per_page=100')->assertOk()->assertJsonCount(40, 'data'));

        $this->assertLessThanOrEqual($small + 1, $large, "Query count grew {$small} -> {$large} — likely N+1.");
    }
}
