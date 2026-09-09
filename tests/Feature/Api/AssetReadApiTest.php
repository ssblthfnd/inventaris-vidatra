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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 5.3 — read-only asset API (`GET /api/assets`, `GET /api/assets/{asset}`).
 *
 * No write path is exercised here because none exists yet.
 */
class AssetReadApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => $active]);
        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------- auth matrix

    public function test_index_requires_authentication(): void
    {
        Asset::factory()->create();

        $this->getJson('/api/assets')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_index_forbidden_for_inactive_user(): void
    {
        $this->actingAsRole(UserRole::Viewer, active: false);

        $this->getJson('/api/assets')->assertStatus(403);
    }

    public function test_index_allows_every_active_role(): void
    {
        Asset::factory()->count(2)->create();

        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/assets')->assertOk()->assertJsonStructure(['data', 'meta']);
        }
    }

    public function test_show_auth_matrix(): void
    {
        $asset = Asset::factory()->create();

        $this->getJson("/api/assets/{$asset->id}")->assertStatus(401);

        $this->actingAsRole(UserRole::Viewer, active: false);
        $this->getJson("/api/assets/{$asset->id}")->assertStatus(403);

        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/assets/{$asset->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $asset->id);
        }
    }

    // ------------------------------------------------------------- basic listing

    public function test_index_lists_assets_with_pagination_meta(): void
    {
        Asset::factory()->count(3)->create();
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonMissingPath('meta.links')
            ->assertJsonMissingPath('links');
    }

    // ------------------------------------------------------------- pagination

    public function test_per_page_one_returns_a_single_row_and_multiple_pages(): void
    {
        Asset::factory()->count(3)->create();
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 3);

        $this->getJson('/api/assets?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_per_page_one_hundred_is_accepted(): void
    {
        Asset::factory()->count(5)->create();
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_per_page_over_one_hundred_is_rejected_not_clamped(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_per_page_zero_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_page_zero_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('page');
    }

    // ------------------------------------------------------------- search

    public function test_search_matches_asset_code(): void
    {
        $location = Location::factory()->create(['code' => 'LX']);
        $category = Category::factory()->create(['code' => 'CX']);
        $sub = Subcategory::factory()->forCategory($category)->create(['code' => '003']);
        $wanted = Asset::factory()->forSubcategory($sub)
            ->identity('LX', 'CX', '003', '0042', 2019)->create()->fresh();
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/assets?q='.$wanted->asset_code)->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_search_matches_brand_model_and_serial_no_case_insensitively(): void
    {
        $brand = Asset::factory()->create(['brand_model' => 'Canon LBP-2900', 'serial_no' => null]);
        $serial = Asset::factory()->create(['brand_model' => null, 'serial_no' => 'XZ-99887']);
        Asset::factory()->count(2)->create(['brand_model' => null, 'serial_no' => null]);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?q=canon lbp')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $brand->id);

        $this->getJson('/api/assets?q=xz-99887')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $serial->id);
    }

    public function test_search_matches_room_name(): void
    {
        $room = Room::factory()->create(['name' => 'Laboratorium Komputer']);
        $inRoom = Asset::factory()->inRoom($room)->create();
        Asset::factory()->count(2)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?q=laboratorium komputer')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inRoom->id);
    }

    public function test_search_term_over_one_hundred_chars_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?q='.str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    // ------------------------------------------------------------- filters

    public function test_filter_by_location_code(): void
    {
        Location::factory()->create(['code' => 'LF']);
        Asset::factory()->count(2)->create(['location_code' => 'LF']);
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?location_code=LF')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filter_by_unknown_location_code_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?location_code=ZZ')
            ->assertStatus(422)->assertJsonValidationErrors('location_code');
    }

    public function test_filter_by_category_code(): void
    {
        $category = Category::factory()->create(['code' => 'CF']);
        $sub = Subcategory::factory()->forCategory($category)->create();
        Asset::factory()->count(2)->forSubcategory($sub)->create();
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?category_code=CF')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filter_by_subcategory_code(): void
    {
        $category = Category::factory()->create(['code' => 'CG']);
        $sub = Subcategory::factory()->forCategory($category)->create(['code' => '009']);
        Asset::factory()->count(2)->forSubcategory($sub)->create();
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?subcategory_code=009')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filter_by_room_id(): void
    {
        $room = Room::factory()->create();
        Asset::factory()->count(2)->inRoom($room)->create();
        Asset::factory()->count(2)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets?room_id={$room->id}")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filter_by_unknown_room_id_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?room_id=999999')
            ->assertStatus(422)->assertJsonValidationErrors('room_id');
    }

    public function test_filter_by_condition(): void
    {
        Asset::factory()->count(2)->create(['condition' => AssetCondition::RusakBerat]);
        Asset::factory()->count(3)->create(['condition' => AssetCondition::Baik]);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?condition=rusak_berat')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filter_by_invalid_condition_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?condition=totally_broken')
            ->assertStatus(422)->assertJsonValidationErrors('condition');
    }

    public function test_filter_by_asset_year(): void
    {
        Asset::factory()->count(2)->create(['asset_year' => 2011]);
        Asset::factory()->count(3)->create(['asset_year' => 2020]);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?asset_year=2011')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filter_by_absurd_asset_year_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?asset_year=999999')
            ->assertStatus(422)->assertJsonValidationErrors('asset_year');
        $this->getJson('/api/assets?asset_year=-999')
            ->assertStatus(422)->assertJsonValidationErrors('asset_year');
    }

    public function test_combined_filters_are_anded(): void
    {
        $category = Category::factory()->create(['code' => 'CH']);
        $sub = Subcategory::factory()->forCategory($category)->create();

        // matches all three
        Asset::factory()->forSubcategory($sub)
            ->writtenOff()->create(['condition' => AssetCondition::KurangBaik]);
        // right category + condition, but not written off
        Asset::factory()->forSubcategory($sub)
            ->create(['condition' => AssetCondition::KurangBaik]);
        // right category + written off, wrong condition
        Asset::factory()->forSubcategory($sub)
            ->writtenOff()->create(['condition' => AssetCondition::Baik]);
        // unrelated
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?category_code=CH&condition=kurang_baik&is_written_off=1')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------- sorting

    public function test_sort_by_asset_year_ascending_and_descending(): void
    {
        Asset::factory()->create(['asset_year' => 2015]);
        Asset::factory()->create(['asset_year' => 2022]);
        Asset::factory()->create(['asset_year' => 2018]);

        $this->actingAsRole(UserRole::Viewer);

        $asc = $this->getJson('/api/assets?sort=asset_year&direction=asc')->assertOk();
        $this->assertSame([2015, 2018, 2022], array_column($asc->json('data'), 'asset_year'));

        $desc = $this->getJson('/api/assets?sort=asset_year&direction=desc')->assertOk();
        $this->assertSame([2022, 2018, 2015], array_column($desc->json('data'), 'asset_year'));
    }

    public function test_default_sort_is_asset_code_ascending(): void
    {
        $location = Location::factory()->create(['code' => 'LS']);
        $category = Category::factory()->create(['code' => 'CS']);
        $sub = Subcategory::factory()->forCategory($category)->create(['code' => '001']);
        Asset::factory()->forSubcategory($sub)->identity('LS', 'CS', '001', '0003', 2016)->create();
        Asset::factory()->forSubcategory($sub)->identity('LS', 'CS', '001', '0001', 2016)->create();
        Asset::factory()->forSubcategory($sub)->identity('LS', 'CS', '001', '0002', 2016)->create();

        $this->actingAsRole(UserRole::Viewer);

        $codes = array_column($this->getJson('/api/assets')->json('data'), 'asset_code');
        $sorted = $codes;
        sort($sorted);
        $this->assertSame($sorted, $codes);
    }

    public function test_invalid_sort_column_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        foreach (['password', '(SELECT 1)', '1 desc', 'id'] as $bad) {
            $this->getJson('/api/assets?sort='.urlencode($bad))
                ->assertStatus(422)->assertJsonValidationErrors('sort');
        }
    }

    public function test_invalid_sort_direction_is_rejected(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?direction=sideways')
            ->assertStatus(422)->assertJsonValidationErrors('direction');
    }

    // ------------------------------------------------- composite subcategory context

    public function test_subcategory_filter_never_crosses_category_boundary(): void
    {
        $catA = Category::factory()->create(['code' => 'A1']);
        $catB = Category::factory()->create(['code' => 'B1']);
        $subA = Subcategory::factory()->forCategory($catA)->create(['code' => '001', 'name' => 'MEJA']);
        $subB = Subcategory::factory()->forCategory($catB)->create(['code' => '001', 'name' => 'KURSI']);

        $assetA = Asset::factory()->forSubcategory($subA)->create();
        $assetB = Asset::factory()->forSubcategory($subB)->create();

        $this->actingAsRole(UserRole::Viewer);

        // category A + subcategory 001 must yield ONLY the category-A asset
        $response = $this->getJson('/api/assets?category_code=A1&subcategory_code=001')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assetA->id);
        $response->assertJsonPath('data.0.subcategory.name', 'MEJA');
        $response->assertJsonPath('data.0.category.code', 'A1');
    }

    public function test_asset_resource_subcategory_is_resolved_from_its_own_category(): void
    {
        $catA = Category::factory()->create(['code' => 'A2']);
        $catB = Category::factory()->create(['code' => 'B2']);
        $subA = Subcategory::factory()->forCategory($catA)->create(['code' => '005', 'name' => 'LEMARI ARSIP']);
        Subcategory::factory()->forCategory($catB)->create(['code' => '005', 'name' => 'AC SPLIT']);

        $asset = Asset::factory()->forSubcategory($subA)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.subcategory.code', '005')
            ->assertJsonPath('data.subcategory.name', 'LEMARI ARSIP');
    }

    public function test_subcategory_code_filter_with_wrong_category_context_is_rejected(): void
    {
        $catA = Category::factory()->create(['code' => 'A3']);
        Category::factory()->create(['code' => 'B3']);
        Subcategory::factory()->forCategory($catA)->create(['code' => '001']);

        $this->actingAsRole(UserRole::Viewer);

        // subcategory 001 exists, but not under category B3
        $this->getJson('/api/assets?category_code=B3&subcategory_code=001')
            ->assertStatus(422)->assertJsonValidationErrors('subcategory_code');
    }

    // ------------------------------------------------- soft-delete & written-off

    public function test_normal_and_written_off_assets_both_appear_by_default(): void
    {
        $normal = Asset::factory()->create();
        $writtenOff = Asset::factory()->writtenOff()->create();

        $this->actingAsRole(UserRole::Viewer);

        $ids = array_column($this->getJson('/api/assets')->json('data'), 'id');
        $this->assertContains($normal->id, $ids);
        $this->assertContains($writtenOff->id, $ids);
    }

    public function test_is_written_off_filter_selects_written_off_assets(): void
    {
        Asset::factory()->count(2)->writtenOff()->create();
        Asset::factory()->count(3)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets?is_written_off=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/assets?is_written_off=0')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_soft_deleted_assets_never_appear_in_the_index(): void
    {
        $live = Asset::factory()->create();
        $deleted = Asset::factory()->create();
        $deleted->delete();

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/assets')->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_soft_deleted_asset_detail_returns_404(): void
    {
        $asset = Asset::factory()->create();
        $asset->delete();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets/{$asset->id}")->assertStatus(404);
    }

    public function test_unknown_asset_detail_returns_404(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/assets/999999')->assertStatus(404);
    }

    // ------------------------------------------------------------- response shape

    public function test_asset_resource_exposes_the_expected_shape(): void
    {
        $location = Location::factory()->create(['alias' => 'PH']);
        $category = Category::factory()->create();
        $sub = Subcategory::factory()->forCategory($category)->create();
        $room = Room::factory()->forLocation($location)->create();
        $asset = Asset::factory()->forSubcategory($sub)->inRoom($room)
            ->create(['location_code' => $location->code])->fresh();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'asset_code',
                    'location' => ['code', 'name', 'alias'],
                    'category' => ['code', 'name'],
                    'subcategory' => ['code', 'name'],
                    'room' => ['id', 'name'],
                    'sequence_no', 'asset_year', 'condition', 'is_written_off',
                ],
            ])
            ->assertJsonPath('data.asset_code', $asset->asset_code)
            ->assertJsonPath('data.location.code', $location->code);
    }

    public function test_asset_resource_hides_internal_and_audit_fields(): void
    {
        $asset = Asset::factory()->create([
            'created_by' => User::factory()->create()->id,
            'updated_by' => User::factory()->create()->id,
        ]);

        $this->actingAsRole(UserRole::Viewer);

        $data = $this->getJson("/api/assets/{$asset->id}")->assertOk()->json('data');

        foreach (['import_row_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at', 'password'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function test_asset_with_no_room_serialises_room_as_null(): void
    {
        $asset = Asset::factory()->create(['room_id' => null]);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.room', null);
    }

    // ------------------------------------------------------------- N+1 regression

    public function test_index_query_count_does_not_grow_with_row_count(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        // A small shared pool of master data so every asset still triggers a lazy
        // relation load per instance if eager loading were missing, without exhausting
        // the factories' unique code space.
        $locations = collect(['L1', 'L2', 'L3'])->map(fn ($c) => Location::factory()->create(['code' => $c]));
        $subs = collect(['K1', 'K2', 'K3'])->map(function ($c) {
            $category = Category::factory()->create(['code' => $c]);

            return Subcategory::factory()->forCategory($category)->create(['code' => '001']);
        });
        $rooms = $locations->map(fn ($loc) => Room::factory()->forLocation($loc)->create());

        $make = function (int $n) use ($subs, $rooms): void {
            for ($i = 0; $i < $n; $i++) {
                $room = $rooms->random();
                Asset::factory()
                    ->forSubcategory($subs->random())
                    ->create(['location_code' => $room->location_code, 'room_id' => $room->id]);
            }
        };

        $make(10);
        $small = $this->countQueries(fn () => $this->getJson('/api/assets?per_page=100')->assertOk()->assertJsonCount(10, 'data'));

        $make(40);
        $large = $this->countQueries(fn () => $this->getJson('/api/assets?per_page=100')->assertOk()->assertJsonCount(50, 'data'));

        // A per-row query would make `large` roughly 5x `small`. Allow a tiny constant slack.
        $this->assertLessThanOrEqual($small + 1, $large, "Query count grew from {$small} to {$large} — likely N+1.");
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };
        DB::listen($listener);
        $callback();

        return $count;
    }
}
