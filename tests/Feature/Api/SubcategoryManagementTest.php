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
 * Tahap 6.8.4 — `GET /api/subcategories` (new admin management list),
 * `POST /api/subcategories`, `PUT|PATCH /api/subcategories/{subcategory}`,
 * all `can:admin`. Mirrors `RoomManagementTest` exactly (surrogate-id
 * entity, admin-only, separate flat admin index alongside the existing
 * nested viewer read).
 *
 * The pre-existing Tahap 5.3 read contract
 * (`GET /api/categories/{category}/subcategories`, `GET /api/subcategories/{subcategory}`)
 * is NOT touched — see {@see MasterDataReadApiTest}, which stays green
 * unmodified.
 *
 * No `DELETE /api/subcategories/{subcategory}` exists.
 */
class SubcategoryManagementTest extends TestCase
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

    public function test_unauthenticated_cannot_manage_subcategories(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($category)->create();

        $this->getJson('/api/subcategories')->assertStatus(401);
        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '001', 'name' => 'X'])
            ->assertStatus(401);
        $this->patchJson("/api/subcategories/{$subcategory->id}", ['name' => 'X'])->assertStatus(401);
    }

    public function test_viewer_cannot_manage_subcategories(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($category)->create();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/subcategories')->assertStatus(403);
        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '001', 'name' => 'X'])
            ->assertStatus(403);
        $this->patchJson("/api/subcategories/{$subcategory->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_operator_cannot_manage_subcategories(): void
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($category)->create();
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/subcategories')->assertStatus(403);
        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '001', 'name' => 'X'])
            ->assertStatus(403);
        $this->patchJson("/api/subcategories/{$subcategory->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_admin_can_get_post_and_patch(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/subcategories')->assertOk();

        $created = $this->postJson('/api/subcategories', [
            'category_code' => $category->code,
            'code' => '001',
            'name' => 'Subkategori Baru',
        ])->assertStatus(201)->json('data');

        $this->patchJson("/api/subcategories/{$created['id']}", ['name' => 'Subkategori Diubah'])->assertOk();
    }

    public function test_delete_route_does_not_exist(): void
    {
        $subcategory = Subcategory::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/subcategories/{$subcategory->id}")->assertStatus(405);
    }

    public function test_inactive_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->admin(active: false));

        $this->getJson('/api/subcategories')->assertStatus(403);
    }

    /* ================================================================== create */

    public function test_create_subcategory_succeeds_and_starts_active(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/subcategories', [
            'category_code' => $category->code,
            'code' => '001',
            'name' => '  Subkategori Baru  ',
            'guide_name' => '  Nama Panduan  ',
        ])->assertStatus(201);

        $response->assertJsonPath('data.code', '001')
            ->assertJsonPath('data.name', 'Subkategori Baru')
            ->assertJsonPath('data.guide_name', 'Nama Panduan')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.category.code', $category->code);

        $this->assertDatabaseHas('subcategories', [
            'category_code' => $category->code,
            'code' => '001',
            'name' => 'Subkategori Baru',
            'is_active' => true,
        ]);
    }

    public function test_create_subcategory_requires_code_exactly_three_characters(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '01', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '0001', 'name' => 'Y'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_subcategory_rejects_duplicate_code_within_category(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->forCategory($category)->create(['code' => '001']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '001', 'name' => 'Lain'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_subcategory_allows_same_code_in_different_category(): void
    {
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();
        Subcategory::factory()->forCategory($catA)->create(['code' => '001']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => $catB->code, 'code' => '001', 'name' => 'Beda Kategori'])
            ->assertStatus(201);

        $this->assertDatabaseCount('subcategories', 2);
    }

    public function test_create_subcategory_rejects_duplicate_name_within_category(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->forCategory($category)->create(['name' => 'Nama Sama']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '002', 'name' => 'Nama Sama'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_create_subcategory_rejects_trimmed_duplicate_name(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->forCategory($category)->create(['name' => 'Nama Sama']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '002', 'name' => '  Nama Sama  '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_create_subcategory_rejects_nonexistent_category(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => 'ZZ', 'code' => '001', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('category_code');
    }

    public function test_create_subcategory_rejects_inactive_category(): void
    {
        $category = Category::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', ['category_code' => $category->code, 'code' => '001', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('category_code');
    }

    public function test_create_subcategory_ignores_client_supplied_is_active(): void
    {
        $category = Category::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/subcategories', [
            'category_code' => $category->code, 'code' => '001', 'name' => 'X', 'is_active' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    /* ================================================================== update */

    public function test_update_subcategory_name_succeeds(): void
    {
        $subcategory = Subcategory::factory()->create(['name' => 'Nama Lama']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['name' => '  Nama Baru  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru');
    }

    public function test_update_subcategory_guide_name_succeeds(): void
    {
        $subcategory = Subcategory::factory()->create(['guide_name' => null]);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['guide_name' => 'Panduan Baru'])
            ->assertOk()
            ->assertJsonPath('data.guide_name', 'Panduan Baru');
    }

    public function test_update_subcategory_rejects_duplicate_name_within_category(): void
    {
        $category = Category::factory()->create();
        Subcategory::factory()->forCategory($category)->create(['name' => 'Nama Diambil']);
        $subcategory = Subcategory::factory()->forCategory($category)->create(['name' => 'Nama Saya']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['name' => 'Nama Diambil'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_update_subcategory_rejects_category_code_change(): void
    {
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($catA)->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['category_code' => $catB->code])
            ->assertStatus(422)->assertJsonValidationErrors('category_code');

        $this->assertDatabaseHas('subcategories', ['id' => $subcategory->id, 'category_code' => $catA->code]);
    }

    public function test_update_subcategory_rejects_code_change(): void
    {
        $subcategory = Subcategory::factory()->create(['code' => '001']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['code' => '999'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('subcategories', ['id' => $subcategory->id, 'code' => '001']);
    }

    public function test_update_subcategory_can_deactivate_and_reactivate(): void
    {
        $subcategory = Subcategory::factory()->create(['is_active' => true]);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    /* ================================================================== deactivation: no cascade */

    public function test_deactivating_a_subcategory_does_not_touch_assets(): void
    {
        $subcategory = Subcategory::factory()->create();
        $asset = Asset::factory()->forSubcategory($subcategory)->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/subcategories/{$subcategory->id}", ['is_active' => false])->assertOk();

        $asset->refresh();
        $this->assertSame($subcategory->category_code, $asset->category_code);
        $this->assertSame($subcategory->code, $asset->subcategory_code);

        $viewer = $this->viewer();
        Sanctum::actingAs($viewer);
        $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonPath('data.id', $asset->id);
    }

    /* ================================================================== import regression (Part I precedent) */

    public function test_active_subcategory_pair_is_valid_for_import(): void
    {
        $subcategory = Subcategory::factory()->create();

        $this->assertTrue((new MasterData)->hasSubcategoryPair($subcategory->category_code, $subcategory->code));
    }

    public function test_deactivated_subcategory_pair_is_invalid_for_import(): void
    {
        $subcategory = Subcategory::factory()->create();
        $subcategory->update(['is_active' => false]);

        $this->assertFalse((new MasterData)->hasSubcategoryPair($subcategory->category_code, $subcategory->code));
    }

    public function test_reactivated_subcategory_pair_is_valid_for_import_again(): void
    {
        $subcategory = Subcategory::factory()->inactive()->create();
        $subcategory->update(['is_active' => true]);

        $this->assertTrue((new MasterData)->hasSubcategoryPair($subcategory->category_code, $subcategory->code));
    }

    /* ================================================================== read/list behaviour */

    public function test_admin_index_returns_active_and_inactive_across_all_categories(): void
    {
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();
        Subcategory::factory()->forCategory($catA)->create();
        Subcategory::factory()->forCategory($catA)->inactive()->create();
        Subcategory::factory()->forCategory($catB)->create();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/subcategories')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_admin_index_supports_category_filter(): void
    {
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();
        Subcategory::factory()->forCategory($catA)->create();
        Subcategory::factory()->forCategory($catB)->create();
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/subcategories?category_code={$catA->code}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.code', $catA->code);
    }

    public function test_admin_index_returns_subcategories_of_an_inactive_category(): void
    {
        $category = Category::factory()->inactive()->create();
        Subcategory::factory()->forCategory($category)->create();
        Sanctum::actingAs($this->admin());

        // the existing nested viewer read would 404 here (inactive parent);
        // the admin flat index is not scoped by parent activity at all.
        $this->getJson("/api/subcategories?category_code={$category->code}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
