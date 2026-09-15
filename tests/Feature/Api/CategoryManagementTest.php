<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Import\Validation\MasterData;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.8.4 — `GET /api/categories` (`?include_inactive=1`, admin-only
 * addition), `POST /api/categories`, `PUT|PATCH /api/categories/{category}`,
 * all `can:admin`. Mirrors `LocationManagementTest` exactly.
 *
 * The pre-existing Tahap 5.3 read contract is NOT touched — see
 * {@see MasterDataReadApiTest}, which stays green unmodified.
 *
 * No `DELETE /api/categories/{category}` exists — deactivation is the only
 * lifecycle mechanism.
 */
class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $active = true): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => $active]);
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => UserRole::Operator, 'is_active' => true]);
    }

    private function viewer(): User
    {
        return User::factory()->create(['role' => UserRole::Viewer, 'is_active' => true]);
    }

    /* ================================================================== authorization */

    public function test_unauthenticated_cannot_manage_categories(): void
    {
        $category = Category::factory()->create();

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(401);
        $this->patchJson("/api/categories/{$category->code}", ['name' => 'X'])->assertStatus(401);
    }

    public function test_viewer_cannot_manage_categories(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->viewer());

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(403);
        $this->patchJson("/api/categories/{$category->code}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_operator_cannot_manage_categories(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(403);
        $this->patchJson("/api/categories/{$category->code}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_admin_can_get_post_and_patch(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/categories?include_inactive=1')->assertOk();

        $created = $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'Kategori Baru'])
            ->assertStatus(201)->json('data');

        $this->patchJson("/api/categories/{$created['code']}", ['name' => 'Kategori Diubah'])->assertOk();
    }

    public function test_delete_route_does_not_exist(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/categories/{$category->code}")->assertStatus(405);
    }

    public function test_inactive_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->admin(active: false));

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(403);
    }

    /* ================================================================== create */

    public function test_create_category_succeeds_and_starts_active(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => '  Kategori Baru  '])
            ->assertStatus(201);

        $response->assertJsonPath('data.code', 'ZZ')
            ->assertJsonPath('data.name', 'Kategori Baru')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', ['code' => 'ZZ', 'name' => 'Kategori Baru', 'is_active' => true]);
    }

    public function test_create_category_requires_code_exactly_two_characters(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/categories', ['code' => 'Z', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/categories', ['code' => 'ZZZ', 'name' => 'Y'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_category_rejects_duplicate_code(): void
    {
        Category::factory()->create(['code' => 'ZZ']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'Kategori Lain'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_category_rejects_duplicate_name(): void
    {
        Category::factory()->create(['name' => 'Kategori Sama']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'Kategori Sama'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_create_category_rejects_trimmed_duplicate_name(): void
    {
        Category::factory()->create(['name' => 'Kategori Sama']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => '  Kategori Sama  '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_create_category_ignores_client_supplied_is_active(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'X', 'is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    /* ================================================================== update */

    public function test_update_category_name_succeeds(): void
    {
        $category = Category::factory()->create(['name' => 'Nama Lama']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/categories/{$category->code}", ['name' => '  Nama Baru  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru');
    }

    public function test_update_category_rejects_code_change(): void
    {
        $category = Category::factory()->create(['code' => 'ZA']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/categories/{$category->code}", ['code' => 'ZB'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('categories', ['code' => 'ZA']);
        $this->assertDatabaseMissing('categories', ['code' => 'ZB']);
    }

    public function test_update_category_can_deactivate_and_reactivate(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/categories/{$category->code}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/categories/{$category->code}", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    /* ================================================================== deactivation: no cascade */

    public function test_deactivating_a_category_does_not_touch_subcategories_or_assets(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($category)->create();
        $asset = Asset::factory()->forSubcategory($subcategory)->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/categories/{$category->code}", ['is_active' => false])->assertOk();

        $subcategory->refresh();
        $this->assertTrue($subcategory->is_active);
        $this->assertSame($category->code, $subcategory->category_code);

        $asset->refresh();
        $this->assertSame($category->code, $asset->category_code);
        $this->assertSame($subcategory->code, $asset->subcategory_code);

        $viewer = $this->viewer();
        Sanctum::actingAs($viewer);
        $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonPath('data.id', $asset->id);
    }

    public function test_inactive_category_blocks_new_subcategory_creation(): void
    {
        $category = Category::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '001', 'name' => 'Baru'])
            ->assertStatus(422)->assertJsonValidationErrors('category_code');
    }

    public function test_reactivating_a_category_restores_new_subcategory_creation(): void
    {
        $category = Category::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/categories/{$category->code}", ['is_active' => true])->assertOk();

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '001', 'name' => 'Baru'])
            ->assertStatus(201);
    }

    /* ================================================================== import regression (Part I precedent) */

    public function test_active_category_is_valid_for_import(): void
    {
        $category = Category::factory()->create();

        $this->assertTrue((new MasterData)->hasCategory($category->code));
    }

    public function test_deactivated_category_is_invalid_for_import(): void
    {
        $category = Category::factory()->create();
        $category->update(['is_active' => false]);

        $this->assertFalse((new MasterData)->hasCategory($category->code));
    }

    public function test_reactivated_category_is_valid_for_import_again(): void
    {
        $category = Category::factory()->inactive()->create();
        $category->update(['is_active' => true]);

        $this->assertTrue((new MasterData)->hasCategory($category->code));
    }

    /* ================================================================== read/list behaviour */

    public function test_admin_index_with_include_inactive_returns_both(): void
    {
        Category::factory()->count(2)->create();
        Category::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/categories?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_admin_index_without_include_inactive_matches_existing_active_only_behaviour(): void
    {
        Category::factory()->count(2)->create();
        Category::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/categories')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_include_inactive_is_ignored_for_non_admin(): void
    {
        Category::factory()->count(2)->create();
        Category::factory()->inactive()->create();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/categories?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
