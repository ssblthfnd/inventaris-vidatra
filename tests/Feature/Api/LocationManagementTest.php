<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Import\Validation\MasterData;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Room;
use App\Models\RoomAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.8.3 — `GET /api/locations` (`?include_inactive=1`, admin-only
 * addition), `POST /api/locations`, `PUT|PATCH /api/locations/{location}`,
 * all `can:admin`.
 *
 * The pre-existing Tahap 5.3 read contract (`GET /api/locations` without
 * the param, `GET /api/locations/{location}`) is NOT touched by this file —
 * see {@see MasterDataReadApiTest}, which stays green unmodified.
 *
 * No `DELETE /api/locations/{location}` exists — deactivation (`is_active`)
 * is the only lifecycle mechanism, same precedent as rooms/users.
 */
class LocationManagementTest extends TestCase
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

    public function test_unauthenticated_cannot_manage_locations(): void
    {
        $location = Location::factory()->create();

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(401);
        $this->patchJson("/api/locations/{$location->code}", ['name' => 'X'])->assertStatus(401);
    }

    public function test_viewer_cannot_manage_locations(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->viewer());

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(403);
        $this->patchJson("/api/locations/{$location->code}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_operator_cannot_manage_locations(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(403);
        $this->patchJson("/api/locations/{$location->code}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_admin_can_get_post_and_patch(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/locations?include_inactive=1')->assertOk();

        $created = $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'Lokasi Baru'])
            ->assertStatus(201)->json('data');

        $this->patchJson("/api/locations/{$created['code']}", ['name' => 'Lokasi Diubah'])->assertOk();
    }

    public function test_delete_route_does_not_exist(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/locations/{$location->code}")->assertStatus(405);
    }

    public function test_inactive_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->admin(active: false));

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'X'])->assertStatus(403);
    }

    /* ================================================================== create */

    public function test_create_location_succeeds_and_starts_active(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/locations', [
            'code' => 'ZZ',
            'name' => '  Lokasi Cabang  ',
            'alias' => '  LC  ',
        ])->assertStatus(201);

        $response->assertJsonPath('data.code', 'ZZ')
            ->assertJsonPath('data.name', 'Lokasi Cabang')
            ->assertJsonPath('data.alias', 'LC')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('locations', ['code' => 'ZZ', 'name' => 'Lokasi Cabang', 'is_active' => true]);
    }

    public function test_create_location_requires_code(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/locations', ['name' => 'Lokasi Baru'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_location_requires_code_exactly_two_characters(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/locations', ['code' => 'Z', 'name' => 'Lokasi Baru'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/locations', ['code' => 'ZZZ', 'name' => 'Lokasi Baru Lagi'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_location_rejects_duplicate_code(): void
    {
        Location::factory()->create(['code' => 'ZZ']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'Lokasi Lain'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_create_location_requires_name(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_create_location_rejects_duplicate_name(): void
    {
        Location::factory()->create(['name' => 'Lokasi Sama']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'Lokasi Sama'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_create_location_ignores_client_supplied_is_active(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'Lokasi Baru', 'is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_create_location_trims_whitespace(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => '  Lokasi Trim  '])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Lokasi Trim');
    }

    /* ================================================================== update */

    public function test_update_location_name_succeeds(): void
    {
        $location = Location::factory()->create(['name' => 'Nama Lama']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['name' => '  Nama Baru  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru');
    }

    public function test_update_location_alias_succeeds(): void
    {
        $location = Location::factory()->create(['alias' => null]);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['alias' => 'AL'])
            ->assertOk()
            ->assertJsonPath('data.alias', 'AL');
    }

    public function test_update_location_partial_patch_touching_only_one_field(): void
    {
        $location = Location::factory()->create(['name' => 'Nama Tetap', 'alias' => 'AT']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['alias' => 'BARU'])->assertOk();

        $this->assertDatabaseHas('locations', ['code' => $location->code, 'name' => 'Nama Tetap', 'alias' => 'BARU']);
    }

    public function test_update_location_rejects_duplicate_name(): void
    {
        Location::factory()->create(['name' => 'Nama Diambil']);
        $location = Location::factory()->create(['name' => 'Nama Saya']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['name' => 'Nama Diambil'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_update_location_allows_renaming_to_its_own_current_name(): void
    {
        $location = Location::factory()->create(['name' => 'Nama Sama']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['name' => 'Nama Sama'])->assertOk();
    }

    public function test_update_location_rejects_code_change(): void
    {
        $location = Location::factory()->create(['code' => 'ZA']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['code' => 'ZB'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertDatabaseHas('locations', ['code' => 'ZA']);
        $this->assertDatabaseMissing('locations', ['code' => 'ZB']);
    }

    public function test_update_location_can_deactivate_and_reactivate(): void
    {
        $location = Location::factory()->create(['is_active' => true]);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/locations/{$location->code}", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
    }

    /* ================================================================== deactivation: no cascade */

    public function test_deactivating_a_location_does_not_touch_rooms_aliases_or_assets(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        $alias = RoomAlias::factory()->forRoom($room)->create();
        $asset = Asset::factory()->inRoom($room)->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['is_active' => false])->assertOk();

        // room: untouched, still active
        $room->refresh();
        $this->assertTrue($room->is_active);
        $this->assertSame($location->code, $room->location_code);

        // alias: untouched
        $this->assertDatabaseHas('room_aliases', ['id' => $alias->id, 'room_id' => $room->id]);

        // asset: untouched, still readable
        $asset->refresh();
        $this->assertSame($room->id, $asset->room_id);
        $this->assertSame($location->code, $asset->location_code);

        $viewer = $this->viewer();
        Sanctum::actingAs($viewer);
        $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonPath('data.id', $asset->id);
    }

    public function test_inactive_location_blocks_new_room_creation(): void
    {
        $location = Location::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => 'Ruang Baru'])
            ->assertStatus(422)->assertJsonValidationErrors('location_code');
    }

    public function test_inactive_location_blocks_new_alias_creation(): void
    {
        $location = Location::factory()->inactive()->create();
        $room = Room::factory()->forLocation($location)->create();
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/room-aliases', [
            'location_code' => $location->code,
            'raw_value' => 'X',
            'room_id' => $room->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_code');
    }

    public function test_reactivating_a_location_restores_new_room_and_alias_creation(): void
    {
        $location = Location::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/locations/{$location->code}", ['is_active' => true])->assertOk();

        $room = $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => 'Ruang Baru'])
            ->assertStatus(201)->json('data');

        Sanctum::actingAs($this->operator());
        $this->postJson('/api/room-aliases', [
            'location_code' => $location->code,
            'raw_value' => 'RB',
            'room_id' => $room['id'],
        ])->assertStatus(201);
    }

    /* ================================================================== import regression (Part I) */

    public function test_active_location_is_valid_for_import(): void
    {
        $location = Location::factory()->create();

        $this->assertTrue((new MasterData)->hasLocation($location->code));
    }

    public function test_deactivated_location_is_invalid_for_import(): void
    {
        $location = Location::factory()->create();
        $location->update(['is_active' => false]);

        $this->assertFalse((new MasterData)->hasLocation($location->code));
    }

    public function test_reactivated_location_is_valid_for_import_again(): void
    {
        $location = Location::factory()->inactive()->create();
        $location->update(['is_active' => true]);

        $this->assertTrue((new MasterData)->hasLocation($location->code));
    }

    /* ================================================================== read/list behaviour */

    public function test_admin_index_with_include_inactive_returns_both(): void
    {
        Location::factory()->count(2)->create();
        Location::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/locations?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');
    }

    public function test_admin_index_without_include_inactive_matches_existing_active_only_behaviour(): void
    {
        Location::factory()->count(2)->create();
        Location::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        // no ?include_inactive param at all — byte-identical to the pre-6.8.3
        // contract, even for an admin actor.
        $this->getJson('/api/locations')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_include_inactive_is_ignored_for_non_admin(): void
    {
        Location::factory()->count(2)->create();
        Location::factory()->inactive()->create();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/locations?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(2, 'data'); // still active-only — param silently has no effect
    }

    public function test_admin_index_supports_q_search_alongside_include_inactive(): void
    {
        Location::factory()->create(['name' => 'Gedung Utama']);
        Location::factory()->inactive()->create(['name' => 'Gedung Lama']);
        Location::factory()->create(['name' => 'Aula']);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/locations?include_inactive=1&q=gedung')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
