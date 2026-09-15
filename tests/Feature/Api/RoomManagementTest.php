<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.8.1 — `GET/POST /api/rooms`, `PUT|PATCH /api/rooms/{room}`, all
 * `can:admin`. These are separate, new endpoints alongside the existing
 * Tahap 5.3 read-only `GET /api/locations/{location}/rooms` and
 * `GET /api/rooms/{room}` (see {@see MasterDataReadApiTest}, which this
 * stage does not touch).
 *
 * No `DELETE /api/rooms/{room}` exists — deactivation (`is_active`) is the
 * only lifecycle mechanism, same precedent as user management.
 */
class RoomManagementTest extends TestCase
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

    public function test_unauthenticated_cannot_list_create_or_update(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();

        $this->getJson('/api/rooms')->assertStatus(401);
        $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => 'Ruang Baru'])->assertStatus(401);
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'X'])->assertStatus(401);
    }

    public function test_viewer_cannot_list_create_or_update(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/rooms')->assertStatus(403);
        $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => 'Ruang Baru'])->assertStatus(403);
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_operator_cannot_list_create_or_update(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/rooms')->assertStatus(403);
        $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => 'Ruang Baru'])->assertStatus(403);
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_inactive_admin_is_forbidden(): void
    {
        Sanctum::actingAs($this->admin(active: false));

        $this->getJson('/api/rooms')->assertStatus(403);
    }

    public function test_admin_can_list_create_and_update(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/rooms')->assertOk();

        $created = $this->postJson('/api/rooms', [
            'location_code' => $location->code,
            'name' => 'Ruang Rapat',
        ])->assertStatus(201)->json('data');

        $this->patchJson("/api/rooms/{$created['id']}", ['name' => 'Ruang Rapat Utama'])->assertOk();
    }

    /* ================================================================== admin index */

    public function test_admin_index_returns_active_and_inactive_across_all_locations(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        Room::factory()->forLocation($locA)->create();
        Room::factory()->forLocation($locA)->inactive()->create();
        Room::factory()->forLocation($locB)->create();

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/rooms')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta'); // unpaginated, like every other master-data list
    }

    public function test_admin_index_supports_location_code_filter(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        Room::factory()->forLocation($locA)->create();
        Room::factory()->forLocation($locB)->create();

        Sanctum::actingAs($this->admin());

        $this->getJson("/api/rooms?location_code={$locA->code}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location.code', $locA->code);
    }

    public function test_admin_index_supports_q_search(): void
    {
        $location = Location::factory()->create();
        Room::factory()->forLocation($location)->create(['name' => 'Ruang Server']);
        Room::factory()->forLocation($location)->create(['name' => 'Ruang Rapat']);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/rooms?q=server')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ruang Server');
    }

    /* ================================================================== create */

    public function test_create_room_succeeds_and_starts_active(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/rooms', [
            'location_code' => $location->code,
            'name' => '  Ruang Arsip  ',
            'pic' => '  Budi  ',
            'notes' => '  Lantai 2  ',
        ])->assertStatus(201);

        $response->assertJsonPath('data.name', 'Ruang Arsip')
            ->assertJsonPath('data.pic', 'Budi')
            ->assertJsonPath('data.notes', 'Lantai 2')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.location.code', $location->code);

        $this->assertDatabaseHas('rooms', [
            'location_code' => $location->code,
            'name' => 'Ruang Arsip',
            'is_active' => true,
        ]);
    }

    public function test_create_room_rejects_inactive_location(): void
    {
        $location = Location::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => 'Ruang Baru'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_create_room_rejects_nonexistent_location(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/rooms', ['location_code' => 'ZZ', 'name' => 'Ruang Baru'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_create_room_rejects_duplicate_name_in_same_location(): void
    {
        $location = Location::factory()->create();
        Room::factory()->forLocation($location)->create(['name' => 'Ruang Rapat']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => 'Ruang Rapat'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_create_room_allows_same_name_in_different_locations(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        Room::factory()->forLocation($locA)->create(['name' => 'Ruang Rapat']);
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/rooms', ['location_code' => $locB->code, 'name' => 'Ruang Rapat'])
            ->assertStatus(201);

        $this->assertDatabaseCount('rooms', 2);
    }

    public function test_create_room_ignores_id_and_is_active_from_client(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/rooms', [
            'location_code' => $location->code,
            'name' => 'Ruang Baru',
            'id' => 999999,
            'is_active' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['id', 'is_active']);
    }

    public function test_create_room_requires_name(): void
    {
        $location = Location::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/rooms', ['location_code' => $location->code, 'name' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /* ================================================================== update */

    public function test_update_room_rename_succeeds(): void
    {
        $room = Room::factory()->create(['name' => 'Ruang Lama']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['name' => '  Ruang Baru  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ruang Baru');

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => 'Ruang Baru']);
    }

    public function test_update_room_pic_and_notes_succeeds(): void
    {
        $room = Room::factory()->create(['pic' => null, 'notes' => null]);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['pic' => 'Siti', 'notes' => 'Dekat tangga'])
            ->assertOk()
            ->assertJsonPath('data.pic', 'Siti')
            ->assertJsonPath('data.notes', 'Dekat tangga');
    }

    public function test_update_room_can_deactivate(): void
    {
        $room = Room::factory()->create(['is_active' => true]);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'is_active' => false]);
    }

    public function test_update_room_can_reactivate(): void
    {
        $room = Room::factory()->inactive()->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_update_room_deactivation_is_not_blocked_by_asset_references(): void
    {
        $room = Room::factory()->create();
        Asset::factory()->inRoom($room)->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['is_active' => false])->assertOk();

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'is_active' => false]);
    }

    public function test_update_room_rejects_location_code_change(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        $room = Room::factory()->forLocation($locA)->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['location_code' => $locB->code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'location_code' => $locA->code]);
    }

    public function test_update_room_rejects_id_change(): void
    {
        $room = Room::factory()->create();
        $other = Room::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id');
    }

    public function test_update_room_rejects_duplicate_rename(): void
    {
        $location = Location::factory()->create();
        Room::factory()->forLocation($location)->create(['name' => 'Ruang A']);
        $roomB = Room::factory()->forLocation($location)->create(['name' => 'Ruang B']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$roomB->id}", ['name' => 'Ruang A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_update_room_allows_renaming_to_its_own_current_name(): void
    {
        $room = Room::factory()->create(['name' => 'Ruang Sama']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'Ruang Sama'])->assertOk();
    }

    public function test_update_room_requires_name_when_supplied_but_not_otherwise(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs($this->admin());

        // touching only pic — name absent entirely is fine (sometimes semantics)
        $this->patchJson("/api/rooms/{$room->id}", ['pic' => 'Andi'])->assertOk();

        // name present but blank IS rejected
        $this->patchJson("/api/rooms/{$room->id}", ['name' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /* ================================================================== existing asset behaviour */

    public function test_existing_asset_remains_readable_after_its_room_is_deactivated(): void
    {
        $room = Room::factory()->create();
        $asset = Asset::factory()->inRoom($room)->create();
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/rooms/{$room->id}", ['is_active' => false])->assertOk();

        // the existing asset detail path (can:viewer) must still work exactly
        // as before — deactivating a room must not alter or hide the asset.
        $viewer = $this->viewer();
        Sanctum::actingAs($viewer);

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.room.id', $room->id);

        $asset->refresh();
        $this->assertSame($room->id, $asset->room_id);
    }

    /* ================================================================== no delete route */

    public function test_delete_route_does_not_exist(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(405);
    }
}
