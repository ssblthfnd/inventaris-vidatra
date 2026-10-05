<?php

namespace Tests\Feature\Api;

use App\Enums\MutationEventType;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tahap 6.9 R9.4-14 (D4) — the asset Trash, `GET /api/assets/trash`.
 *
 * Soft-deleted assets only (`onlyTrashed()`), gated by the existing
 * `assets.restore` ability, scoped by the same LocationScope WHERE as the active
 * list, newest deletion first. Restore and detail are the existing R5/R9.3
 * endpoints, unchanged.
 */
class Stage697AssetTrashTest extends TestCase
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
            ->create(array_merge(['location_code' => $locationCode], $overrides));
    }

    /** Soft-deletes `$asset` at `$at` (so ordering is deterministic). */
    private function trash(Asset $asset, string $at = '2026-10-01 10:00:00'): Asset
    {
        Carbon::setTestNow($at);
        $asset->delete();
        Carbon::setTestNow();

        return $asset;
    }

    /** @return list<int> ids in response order */
    private function trashIds(string $query = ''): array
    {
        return array_column($this->getJson('/api/assets/trash'.($query !== '' ? "?{$query}" : ''))->assertOk()->json('data'), 'id');
    }

    /* ================================================================== content */

    public function test_trash_lists_only_deleted_assets_with_what_identifies_them(): void
    {
        $room = Room::factory()->create(['location_code' => '02', 'name' => 'Ruang Guru']);
        $active = $this->asset('02');
        $deleted = $this->trash($this->asset('02', ['room_id' => $room->id, 'asset_year' => 2021, 'brand_model' => 'Olympic']), '2026-10-02 08:30:00');

        Sanctum::actingAs($this->user(UserRole::Operator));
        $response = $this->getJson('/api/assets/trash')->assertOk()->assertJsonCount(1, 'data');

        $this->assertNotContains($active->id, array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.id', $deleted->id)
            ->assertJsonPath('data.0.asset_code', Asset::withTrashed()->find($deleted->id)->asset_code)
            ->assertJsonPath('data.0.location.code', '02')
            ->assertJsonPath('data.0.category.code', '02')
            ->assertJsonPath('data.0.subcategory.name', 'Meja')
            ->assertJsonPath('data.0.room.name', 'Ruang Guru')
            ->assertJsonPath('data.0.asset_year', 2021)
            ->assertJsonPath('data.0.condition', 'baik')
            ->assertJsonPath('data.0.is_trashed', true)
            ->assertJsonPath('data.0.deleted_at', Carbon::parse('2026-10-02 08:30:00')->toIso8601String())
            ->assertJsonPath('meta.total', 1);
    }

    public function test_inventory_list_and_asset_resource_are_unchanged(): void
    {
        $active = $this->asset('02');
        $deleted = $this->trash($this->asset('02'));

        Sanctum::actingAs($this->user(UserRole::Operator));
        $ids = array_column($this->getJson('/api/assets')->assertOk()->json('data'), 'id');
        $this->assertSame([$active->id], $ids);
        $this->assertNotContains($deleted->id, $ids);

        // the shared resource still never exposes the raw timestamp
        $this->getJson("/api/assets/{$active->id}")->assertOk()->assertJsonMissingPath('data.deleted_at');
        $this->getJson("/api/assets/{$deleted->id}")->assertOk()
            ->assertJsonPath('data.is_trashed', true)
            ->assertJsonMissingPath('data.deleted_at');
    }

    /* ================================================================== authorization */

    /** @return array<string, array{UserRole}> */
    public static function globalRoles(): array
    {
        return ['super_admin' => [UserRole::SuperAdmin], 'operator' => [UserRole::Operator], 'admin' => [UserRole::Admin]];
    }

    #[DataProvider('globalRoles')]
    public function test_global_roles_see_every_location_and_can_restore(UserRole $role): void
    {
        $a02 = $this->trash($this->asset('02'));
        $a03 = $this->trash($this->asset('03'));
        $a04 = $this->trash($this->asset('04'));

        Sanctum::actingAs($this->user($role));
        $this->assertEqualsCanonicalizing([$a02->id, $a03->id, $a04->id], $this->trashIds());

        $this->postJson("/api/assets/{$a03->id}/restore")->assertOk();
        $this->assertEqualsCanonicalizing([$a02->id, $a04->id], $this->trashIds());
    }

    public function test_unit_admin_sees_and_restores_only_its_own_location(): void
    {
        $own = $this->trash($this->asset('02'));
        $foreign = $this->trash($this->asset('03'));
        $actor = $this->user(UserRole::UnitAdmin, '02');

        Sanctum::actingAs($actor);
        $this->assertSame([$own->id], $this->trashIds());

        // asking for the foreign location narrows to nothing — never a 403 that confirms anything
        $this->assertSame([], $this->trashIds('location_code[]=03'));
        $this->assertSame([$own->id], $this->trashIds('location_code[]=02'));

        // foreign detail stays hidden (404) and foreign restore stays refused (R5 / R9.3, unchanged)
        $this->getJson("/api/assets/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/assets/{$foreign->id}/restore")->assertForbidden();
        $this->assertTrue(Asset::withTrashed()->find($foreign->id)->trashed());

        // own detail and restore work
        $this->getJson("/api/assets/{$own->id}")->assertOk()->assertJsonPath('data.is_trashed', true);
        $this->postJson("/api/assets/{$own->id}/restore")->assertOk()->assertJsonPath('data.is_trashed', false);
        $this->assertSame([], $this->trashIds());
    }

    public function test_viewer_gets_no_trash_and_cannot_restore(): void
    {
        $deleted = $this->trash($this->asset('02'));

        Sanctum::actingAs($this->user(UserRole::Viewer));
        $response = $this->getJson('/api/assets/trash')->assertForbidden();
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertStringNotContainsString(Asset::withTrashed()->find($deleted->id)->asset_code, $response->getContent());

        $this->getJson("/api/assets/{$deleted->id}")->assertNotFound();
        $this->postJson("/api/assets/{$deleted->id}/restore")->assertForbidden();
        $this->assertTrue(Asset::withTrashed()->find($deleted->id)->trashed());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/assets/trash')->assertUnauthorized();
    }

    /* ================================================================== filters, paging, order */

    public function test_filters_apply_on_top_of_the_deleted_only_base(): void
    {
        $roomA = Room::factory()->create(['location_code' => '02', 'name' => 'Laboratorium']);
        $mejaLab = $this->trash($this->asset('02', ['room_id' => $roomA->id, 'asset_year' => 2020, 'brand_model' => 'Informa']));
        $mejaNoRoom = $this->trash($this->asset('02', ['asset_year' => 2022, 'brand_model' => 'Olympic']));
        $laptop03 = $this->trash($this->asset('03', ['asset_year' => 2020, 'brand_model' => 'Lenovo'], $this->laptop));
        // ACTIVE assets that match every filter below must never leak in (search ORs must stay inside the trashed scope)
        $this->asset('02', ['room_id' => $roomA->id, 'asset_year' => 2020, 'brand_model' => 'Informa']);
        $this->asset('03', ['asset_year' => 2020, 'brand_model' => 'Lenovo'], $this->laptop);

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame([$mejaLab->id], $this->trashIds('q=Informa'));
        $this->assertSame([$mejaLab->id], $this->trashIds('q=Laboratorium')); // room-name search
        $this->assertSame([$laptop03->id], $this->trashIds('category_code[]=03'));
        $this->assertSame([$laptop03->id], $this->trashIds('subcategory_code[]=03.020'));
        $this->assertEqualsCanonicalizing([$mejaLab->id, $laptop03->id], $this->trashIds('asset_year[]=2020'));
        $this->assertEqualsCanonicalizing([$mejaLab->id, $mejaNoRoom->id], $this->trashIds('location_code[]=02'));
        $this->assertSame([$mejaLab->id], $this->trashIds("room_id[]={$roomA->id}"));
        $this->assertSame([$mejaNoRoom->id], $this->trashIds('location_code[]=02&room_id[]=none'));
        $this->assertSame([], $this->trashIds('q=tidak-ada-yang-cocok'));

        // invalid filters are rejected exactly like the active list
        $this->getJson('/api/assets/trash?per_page=500')->assertStatus(422);
        $this->getJson('/api/assets/trash?location_code[]=99')->assertStatus(422);
    }

    public function test_default_order_is_newest_deletion_first_with_id_as_tie_breaker(): void
    {
        $old = $this->trash($this->asset('02'), '2026-09-01 09:00:00');
        $newest = $this->trash($this->asset('02'), '2026-10-03 09:00:00');
        $tieLow = $this->trash($this->asset('02'), '2026-09-15 09:00:00');
        $tieHigh = $this->trash($this->asset('02'), '2026-09-15 09:00:00');

        Sanctum::actingAs($this->user(UserRole::Operator));
        $this->assertSame([$newest->id, $tieHigh->id, $tieLow->id, $old->id], $this->trashIds());
        // a sort parameter meant for the active list does not reorder the Trash
        $this->assertSame([$newest->id, $tieHigh->id, $tieLow->id, $old->id], $this->trashIds('sort=asset_code&direction=asc'));
    }

    public function test_pagination_is_deterministic_across_pages(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->trash($this->asset('02'), '2026-10-01 10:00:00')->id; // identical deleted_at
        }
        $expected = array_reverse($ids); // id desc

        Sanctum::actingAs($this->user(UserRole::Operator));
        $page1 = $this->getJson('/api/assets/trash?per_page=2&page=1')->assertOk();
        $page2 = $this->getJson('/api/assets/trash?per_page=2&page=2')->assertOk();
        $page3 = $this->getJson('/api/assets/trash?per_page=2&page=3')->assertOk();

        $page1->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
        $this->assertSame($expected, [
            ...array_column($page1->json('data'), 'id'),
            ...array_column($page2->json('data'), 'id'),
            ...array_column($page3->json('data'), 'id'),
        ]);
        $this->assertSame(array_column($page2->json('data'), 'id'), array_column($this->getJson('/api/assets/trash?per_page=2&page=2')->json('data'), 'id'));
    }

    /* ================================================================== restore flow */

    public function test_restore_moves_the_asset_back_to_inventory_unchanged(): void
    {
        $creator = $this->user(UserRole::Admin);
        $asset = $this->asset('02', ['created_by' => $creator->id]);
        $code = $asset->fresh()->asset_code;

        $operator = $this->user(UserRole::Operator);
        Sanctum::actingAs($operator);
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();
        $this->assertSame([$asset->id], $this->trashIds());
        $this->assertNotContains($asset->id, array_column($this->getJson('/api/assets')->json('data'), 'id'));

        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk()
            ->assertJsonPath('data.asset_code', $code)
            ->assertJsonPath('data.is_trashed', false);

        $this->assertSame([], $this->trashIds());
        $this->assertContains($asset->id, array_column($this->getJson('/api/assets')->json('data'), 'id'));
        $restored = Asset::find($asset->id);
        $this->assertSame([$code, $creator->id], [$restored->asset_code, $restored->created_by]);
        $this->assertSame(1, MutationLog::where('asset_id', $asset->id)->where('event_type', MutationEventType::Restore->value)->count());
    }

    /* ================================================================== performance */

    public function test_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $room = Room::factory()->create(['location_code' => '02']);
        Sanctum::actingAs($this->user(UserRole::Operator));

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/assets/trash?per_page=50')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->trash($this->asset('02', ['room_id' => $room->id]));
        $few = $count();

        for ($i = 0; $i < 8; $i++) {
            $this->trash($this->asset($i % 2 ? '02' : '03', $i % 2 ? ['room_id' => $room->id] : [], $i % 3 ? $this->meja : $this->laptop));
        }
        $this->assertSame($few, $count());
    }
}
