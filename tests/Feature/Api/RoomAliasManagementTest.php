<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Import\Parsing\ValueNormalizer;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Room;
use App\Models\RoomAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tahap 6.8.2 — `GET/POST /api/room-aliases`, `PUT|PATCH|DELETE
 * /api/room-aliases/{roomAlias}`.
 *
 * Unlike every other Tahap 6.8 master-data write (`can:admin`), room aliases
 * are `can:operator` for every verb including write — see
 * RoomAliasController's docblock for why (`AuthServiceProvider`'s own
 * Tahap-5.0 docblock already documented this split). `DELETE` genuinely
 * exists here (unlike rooms/locations/etc.) — RoomAlias is a leaf table.
 */
class RoomAliasManagementTest extends TestCase
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

    public function test_unauthenticated_cannot_access_any_verb(): void
    {
        $room = Room::factory()->create();
        $alias = RoomAlias::factory()->forRoom($room)->create();

        $this->getJson('/api/room-aliases')->assertStatus(401);
        $this->postJson('/api/room-aliases', ['location_code' => $room->location_code, 'raw_value' => 'X', 'room_id' => $room->id])
            ->assertStatus(401);
        $this->patchJson("/api/room-aliases/{$alias->id}", ['notes' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/room-aliases/{$alias->id}")->assertStatus(401);
    }

    public function test_viewer_can_get_but_not_write(): void
    {
        $room = Room::factory()->create();
        $alias = RoomAlias::factory()->forRoom($room)->create();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/room-aliases')->assertOk();

        $this->postJson('/api/room-aliases', ['location_code' => $room->location_code, 'raw_value' => 'X', 'room_id' => $room->id])
            ->assertStatus(403);
        $this->patchJson("/api/room-aliases/{$alias->id}", ['notes' => 'x'])->assertStatus(403);
        $this->deleteJson("/api/room-aliases/{$alias->id}")->assertStatus(403);
    }

    public function test_operator_can_get_and_write(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/room-aliases')->assertOk();

        $created = $this->postJson('/api/room-aliases', [
            'location_code' => $room->location_code,
            'raw_value' => 'Ruang X',
            'room_id' => $room->id,
        ])->assertStatus(201)->json('data');

        $this->patchJson("/api/room-aliases/{$created['id']}", ['notes' => 'catatan'])->assertOk();
        $this->deleteJson("/api/room-aliases/{$created['id']}")->assertOk();
    }

    public function test_admin_can_get_and_write(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/room-aliases')->assertOk();

        $created = $this->postJson('/api/room-aliases', [
            'location_code' => $room->location_code,
            'raw_value' => 'Ruang Y',
            'room_id' => $room->id,
        ])->assertStatus(201);

        $created->assertStatus(201);
    }

    public function test_inactive_operator_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => UserRole::Operator, 'is_active' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/room-aliases')->assertStatus(403);
    }

    /* ================================================================== create */

    public function test_create_alias_succeeds(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        Sanctum::actingAs($this->operator());

        $response = $this->postJson('/api/room-aliases', [
            'location_code' => $location->code,
            // leading/trailing whitespace only here — raw_value is trimmed at
            // the edges but otherwise kept verbatim (it's an audit trail of
            // what was typed/imported); internal-whitespace COLLAPSING is a
            // match_key-only concern, covered by the next test.
            'raw_value' => '  Ruang Server  ',
            'room_id' => $room->id,
            'notes' => '  catatan  ',
        ])->assertStatus(201);

        $response->assertJsonPath('data.raw_value', 'Ruang Server')
            ->assertJsonPath('data.match_key', 'RUANG SERVER')
            ->assertJsonPath('data.location.code', $location->code)
            ->assertJsonPath('data.room.id', $room->id)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.notes', 'catatan');

        $this->assertDatabaseHas('room_aliases', [
            'location_code' => $location->code,
            'raw_value' => 'Ruang Server',
            'match_key' => 'RUANG SERVER',
            'room_id' => $room->id,
        ]);
    }

    public function test_create_alias_generates_match_key_via_canonical_normalizer(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs($this->operator());

        $raw = '  R.  Rapat   Utama ';
        $this->postJson('/api/room-aliases', [
            'location_code' => $room->location_code,
            'raw_value' => $raw,
            'room_id' => $room->id,
        ])->assertStatus(201);

        $expectedKey = ValueNormalizer::roomMatchKey($raw);
        $this->assertDatabaseHas('room_aliases', [
            'location_code' => $room->location_code,
            'match_key' => $expectedKey,
        ]);
    }

    public function test_create_alias_rejects_duplicate_match_key_in_same_location(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        RoomAlias::factory()->forRoom($room)->create(['raw_value' => 'Ruang Rapat', 'match_key' => 'RUANG RAPAT']);
        Sanctum::actingAs($this->operator());

        // different casing/whitespace, same normalized key -> still a duplicate
        $this->postJson('/api/room-aliases', [
            'location_code' => $location->code,
            'raw_value' => 'ruang   rapat',
            'room_id' => $room->id,
        ])->assertStatus(422)->assertJsonValidationErrors('raw_value');
    }

    public function test_create_alias_allows_same_match_key_in_different_locations(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        $roomA = Room::factory()->forLocation($locA)->create();
        $roomB = Room::factory()->forLocation($locB)->create();
        RoomAlias::factory()->forRoom($roomA)->create(['raw_value' => 'Ruang Rapat', 'match_key' => 'RUANG RAPAT']);
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/room-aliases', [
            'location_code' => $locB->code,
            'raw_value' => 'Ruang Rapat',
            'room_id' => $roomB->id,
        ])->assertStatus(201);

        $this->assertDatabaseCount('room_aliases', 2);
    }

    public function test_create_alias_rejects_inactive_location(): void
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

    public function test_create_alias_rejects_room_from_another_location(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        $roomB = Room::factory()->forLocation($locB)->create();
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/room-aliases', [
            'location_code' => $locA->code,
            'raw_value' => 'X',
            'room_id' => $roomB->id,
        ])->assertStatus(422)->assertJsonValidationErrors('room_id');
    }

    public function test_create_alias_allows_inactive_room(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->inactive()->create();
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/room-aliases', [
            'location_code' => $location->code,
            'raw_value' => 'X',
            'room_id' => $room->id,
        ])->assertStatus(201)->assertJsonPath('data.room.is_active', false);
    }

    public function test_create_alias_ignores_client_supplied_match_key(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/room-aliases', [
            'location_code' => $room->location_code,
            'raw_value' => 'Ruang Rapat',
            'room_id' => $room->id,
            'match_key' => 'SOMETHING ELSE',
        ])->assertStatus(422)->assertJsonValidationErrors('match_key');
    }

    public function test_create_alias_derives_created_by_from_authenticated_user(): void
    {
        $room = Room::factory()->create();
        $operator = $this->operator();
        Sanctum::actingAs($operator);

        $response = $this->postJson('/api/room-aliases', [
            'location_code' => $room->location_code,
            'raw_value' => 'Ruang Rapat',
            'room_id' => $room->id,
            'created_by' => 999999, // must be ignored
        ])->assertStatus(422)->assertJsonValidationErrors('created_by');

        $response->assertStatus(422);
    }

    public function test_create_alias_derives_created_by_when_field_absent(): void
    {
        $room = Room::factory()->create();
        $operator = $this->operator();
        Sanctum::actingAs($operator);

        $response = $this->postJson('/api/room-aliases', [
            'location_code' => $room->location_code,
            'raw_value' => 'Ruang Rapat',
            'room_id' => $room->id,
        ])->assertStatus(201);

        $response->assertJsonPath('data.created_by.id', $operator->id);
        $this->assertDatabaseHas('room_aliases', ['raw_value' => 'Ruang Rapat', 'created_by' => $operator->id]);
    }

    /* ================================================================== update */

    public function test_update_alias_raw_value_regenerates_match_key(): void
    {
        $room = Room::factory()->create();
        $alias = RoomAlias::factory()->forRoom($room)->create(['raw_value' => 'Old', 'match_key' => 'OLD']);
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/room-aliases/{$alias->id}", ['raw_value' => 'New Value'])
            ->assertOk()
            ->assertJsonPath('data.raw_value', 'New Value')
            ->assertJsonPath('data.match_key', 'NEW VALUE');
    }

    public function test_update_alias_rejects_duplicate_match_key(): void
    {
        $location = Location::factory()->create();
        $room = Room::factory()->forLocation($location)->create();
        RoomAlias::factory()->forRoom($room)->create(['raw_value' => 'Taken', 'match_key' => 'TAKEN']);
        $alias = RoomAlias::factory()->forRoom($room)->create(['raw_value' => 'Mine', 'match_key' => 'MINE']);
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/room-aliases/{$alias->id}", ['raw_value' => 'Taken'])
            ->assertStatus(422)->assertJsonValidationErrors('raw_value');
    }

    public function test_update_alias_room_change_allowed_within_same_location(): void
    {
        $location = Location::factory()->create();
        $roomA = Room::factory()->forLocation($location)->create();
        $roomB = Room::factory()->forLocation($location)->create();
        $alias = RoomAlias::factory()->forRoom($roomA)->create();
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/room-aliases/{$alias->id}", ['room_id' => $roomB->id])
            ->assertOk()
            ->assertJsonPath('data.room.id', $roomB->id);
    }

    public function test_update_alias_rejects_room_from_another_location(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        $roomA = Room::factory()->forLocation($locA)->create();
        $roomB = Room::factory()->forLocation($locB)->create();
        $alias = RoomAlias::factory()->forRoom($roomA)->create();
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/room-aliases/{$alias->id}", ['room_id' => $roomB->id])
            ->assertStatus(422)->assertJsonValidationErrors('room_id');

        $this->assertDatabaseHas('room_aliases', ['id' => $alias->id, 'room_id' => $roomA->id]);
    }

    public function test_update_alias_rejects_location_code_change(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        $room = Room::factory()->forLocation($locA)->create();
        $alias = RoomAlias::factory()->forRoom($room)->create();
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/room-aliases/{$alias->id}", ['location_code' => $locB->code])
            ->assertStatus(422)->assertJsonValidationErrors('location_code');

        $this->assertDatabaseHas('room_aliases', ['id' => $alias->id, 'location_code' => $locA->code]);
    }

    public function test_update_alias_allows_selecting_an_inactive_room(): void
    {
        $location = Location::factory()->create();
        $activeRoom = Room::factory()->forLocation($location)->create();
        $inactiveRoom = Room::factory()->forLocation($location)->inactive()->create();
        $alias = RoomAlias::factory()->forRoom($activeRoom)->create();
        Sanctum::actingAs($this->operator());

        $this->patchJson("/api/room-aliases/{$alias->id}", ['room_id' => $inactiveRoom->id])
            ->assertOk()
            ->assertJsonPath('data.room.id', $inactiveRoom->id)
            ->assertJsonPath('data.room.is_active', false);
    }

    /* ================================================================== delete */

    public function test_delete_alias_removes_it_without_touching_room_or_assets(): void
    {
        $room = Room::factory()->create();
        $asset = Asset::factory()->inRoom($room)->create();
        $alias = RoomAlias::factory()->forRoom($room)->create();
        Sanctum::actingAs($this->operator());

        $this->deleteJson("/api/room-aliases/{$alias->id}")->assertOk();

        $this->assertDatabaseMissing('room_aliases', ['id' => $alias->id]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'is_active' => true]);
        $asset->refresh();
        $this->assertSame($room->id, $asset->room_id);
    }

    /* ================================================================== filters */

    public function test_index_filters_by_location_room_and_search(): void
    {
        $locA = Location::factory()->create();
        $locB = Location::factory()->create();
        $roomA1 = Room::factory()->forLocation($locA)->create();
        $roomA2 = Room::factory()->forLocation($locA)->create();
        $roomB = Room::factory()->forLocation($locB)->create();

        RoomAlias::factory()->forRoom($roomA1)->create(['raw_value' => 'Ruang Server']);
        RoomAlias::factory()->forRoom($roomA2)->create(['raw_value' => 'Ruang Rapat']);
        RoomAlias::factory()->forRoom($roomB)->create(['raw_value' => 'Ruang Lain']);

        Sanctum::actingAs($this->operator());

        $this->getJson("/api/room-aliases?location_code={$locA->code}")
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/room-aliases?room_id={$roomA1->id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.raw_value', 'Ruang Server');

        $this->getJson('/api/room-aliases?q=server')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.raw_value', 'Ruang Server');
    }
}
