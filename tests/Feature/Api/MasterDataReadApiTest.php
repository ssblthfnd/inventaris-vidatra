<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 5.3 — read-only master-data API: locations, categories, subcategories, rooms.
 *
 * These lists are finite (dropdown sources) — plain `{ "data": [...] }`, no pagination
 * meta (docs/api_convention.md §11). Only `is_active = true` rows are visible.
 */
class MasterDataReadApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => $active]);
        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------- auth matrix

    public function test_index_endpoints_require_authentication(): void
    {
        foreach (['/api/locations', '/api/categories'] as $path) {
            $this->getJson($path)->assertStatus(401);
        }
    }

    public function test_index_endpoints_forbidden_for_inactive_user(): void
    {
        $this->actingAsRole(UserRole::Viewer, active: false);

        foreach (['/api/locations', '/api/categories'] as $path) {
            $this->getJson($path)->assertStatus(403);
        }
    }

    public function test_index_endpoints_allow_every_active_role(): void
    {
        foreach ([UserRole::Viewer, UserRole::Operator, UserRole::Admin] as $role) {
            $this->actingAsRole($role);
            foreach (['/api/locations', '/api/categories'] as $path) {
                $this->getJson($path)->assertOk()->assertJsonStructure(['data']);
            }
        }
    }

    public function test_nested_and_show_endpoints_auth_matrix(): void
    {
        $location = Location::factory()->create();
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($category)->create();
        $room = Room::factory()->forLocation($location)->create();

        $paths = [
            "/api/locations/{$location->code}",
            "/api/categories/{$category->code}",
            "/api/categories/{$category->code}/subcategories",
            "/api/subcategories/{$subcategory->id}",
            "/api/locations/{$location->code}/rooms",
            "/api/rooms/{$room->id}",
        ];

        foreach ($paths as $path) {
            $this->getJson($path)->assertStatus(401);
        }

        $this->actingAsRole(UserRole::Viewer, active: false);
        foreach ($paths as $path) {
            $this->getJson($path)->assertStatus(403);
        }

        $this->actingAsRole(UserRole::Operator);
        foreach ($paths as $path) {
            $this->getJson($path)->assertOk();
        }
    }

    // ------------------------------------------------------------- locations

    public function test_locations_index_returns_active_only_as_finite_collection(): void
    {
        Location::factory()->count(2)->create();
        Location::factory()->inactive()->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('meta')
            ->assertJsonStructure(['data' => [['code', 'name', 'alias', 'is_active']]]);
    }

    public function test_locations_index_supports_q_search(): void
    {
        Location::factory()->create(['name' => 'Gedung Perpustakaan Pusat', 'alias' => 'GPP']);
        Location::factory()->create(['name' => 'Aula Utama', 'alias' => 'AU']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations?q=perpustakaan')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Gedung Perpustakaan Pusat');
    }

    public function test_location_show_by_code(): void
    {
        $location = Location::factory()->create(['code' => 'LP']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations/LP')
            ->assertOk()
            ->assertJsonPath('data.code', 'LP');
    }

    public function test_location_show_returns_404_for_inactive(): void
    {
        Location::factory()->inactive()->create(['code' => 'LQ']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations/LQ')->assertStatus(404);
    }

    public function test_location_show_returns_404_for_unknown(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations/ZZ')->assertStatus(404);
    }

    // ------------------------------------------------------------- categories

    public function test_categories_index_returns_active_only(): void
    {
        Category::factory()->count(3)->create();
        Category::factory()->inactive()->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/categories')
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_category_show_by_code(): void
    {
        Category::factory()->create(['code' => 'CP']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/categories/CP')->assertOk()->assertJsonPath('data.code', 'CP');
    }

    public function test_category_show_returns_404_for_inactive(): void
    {
        Category::factory()->inactive()->create(['code' => 'CQ']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/categories/CQ')->assertStatus(404);
    }

    // ------------------------------------------------------------- subcategories

    public function test_subcategories_are_scoped_to_the_parent_category(): void
    {
        $catA = Category::factory()->create(['code' => 'C1']);
        $catB = Category::factory()->create(['code' => 'C2']);
        Subcategory::factory()->forCategory($catA)->create(['code' => '001', 'name' => 'MEJA']);
        Subcategory::factory()->forCategory($catA)->create(['code' => '002', 'name' => 'KURSI']);
        Subcategory::factory()->forCategory($catB)->create(['code' => '001', 'name' => 'PROYEKTOR']);

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/categories/C1/subcategories')->assertOk();
        $response->assertJsonCount(2, 'data');
        foreach ($response->json('data') as $row) {
            $this->assertSame('C1', $row['category']['code']);
        }
    }

    public function test_subcategories_index_returns_active_only(): void
    {
        $category = Category::factory()->create(['code' => 'C3']);
        Subcategory::factory()->forCategory($category)->create();
        Subcategory::factory()->forCategory($category)->inactive()->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/categories/C3/subcategories')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_subcategories_index_404_when_category_inactive(): void
    {
        $category = Category::factory()->inactive()->create(['code' => 'C4']);
        Subcategory::factory()->forCategory($category)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/categories/C4/subcategories')->assertStatus(404);
    }

    public function test_subcategory_show_carries_its_own_category(): void
    {
        $catA = Category::factory()->create(['code' => 'C5', 'name' => 'PERALATAN KANTOR']);
        $catB = Category::factory()->create(['code' => 'C6']);
        $subA = Subcategory::factory()->forCategory($catA)->create(['code' => '001']);
        Subcategory::factory()->forCategory($catB)->create(['code' => '001']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/subcategories/{$subA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $subA->id)
            ->assertJsonPath('data.code', '001')
            ->assertJsonPath('data.category.code', 'C5')
            ->assertJsonPath('data.category.name', 'PERALATAN KANTOR');
    }

    public function test_subcategory_show_404_for_inactive(): void
    {
        $subcategory = Subcategory::factory()->inactive()->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/subcategories/{$subcategory->id}")->assertStatus(404);
    }

    // ------------------------------------------------------------- rooms

    public function test_rooms_are_scoped_to_the_parent_location(): void
    {
        $locA = Location::factory()->create(['code' => 'LR']);
        $locB = Location::factory()->create(['code' => 'LT']);
        Room::factory()->count(2)->forLocation($locA)->create();
        Room::factory()->forLocation($locB)->create();

        $this->actingAsRole(UserRole::Viewer);

        $response = $this->getJson('/api/locations/LR/rooms')->assertOk();
        $response->assertJsonCount(2, 'data');
        foreach ($response->json('data') as $row) {
            $this->assertSame('LR', $row['location']['code']);
        }
    }

    public function test_rooms_index_returns_active_only(): void
    {
        $location = Location::factory()->create(['code' => 'LU']);
        Room::factory()->forLocation($location)->create();
        Room::factory()->forLocation($location)->inactive()->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations/LU/rooms')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rooms_index_supports_q_search(): void
    {
        $location = Location::factory()->create(['code' => 'LV']);
        Room::factory()->forLocation($location)->create(['name' => 'Ruang Server']);
        Room::factory()->forLocation($location)->create(['name' => 'Ruang Rapat']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations/LV/rooms?q=server')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ruang Server');
    }

    public function test_rooms_index_404_when_location_inactive(): void
    {
        $location = Location::factory()->inactive()->create(['code' => 'LW']);
        Room::factory()->forLocation($location)->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson('/api/locations/LW/rooms')->assertStatus(404);
    }

    public function test_room_show(): void
    {
        $location = Location::factory()->create(['code' => 'LY']);
        $room = Room::factory()->forLocation($location)->create(['name' => 'Gudang']);

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $room->id)
            ->assertJsonPath('data.name', 'Gudang')
            ->assertJsonPath('data.location.code', 'LY')
            ->assertJsonStructure(['data' => ['id', 'name', 'location' => ['code', 'name'], 'pic', 'notes', 'is_active']]);
    }

    public function test_room_show_404_for_inactive(): void
    {
        $room = Room::factory()->inactive()->create();

        $this->actingAsRole(UserRole::Viewer);

        $this->getJson("/api/rooms/{$room->id}")->assertStatus(404);
    }

    public function test_room_resource_does_not_expose_aliases(): void
    {
        $room = Room::factory()->create();

        $this->actingAsRole(UserRole::Viewer);

        $data = $this->getJson("/api/rooms/{$room->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('aliases', $data);
        $this->assertArrayNotHasKey('room_aliases', $data);
        $this->assertArrayNotHasKey('created_at', $data);
    }
}
