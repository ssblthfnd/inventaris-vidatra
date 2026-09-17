<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Room;
use App\Models\RoomAlias;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stage 6.9 R7 — room location scope and room-alias exclusion. Every test
 * hits a real HTTP endpoint. R7 activates real room MANAGEMENT (create/
 * update/deactivate/reactivate) for `unit_admin` for the first time, scoped
 * to their own assigned location — read scope was already done in R4 (see
 * `Stage69ReadScopeTest`'s "G. rooms" section, untouched by R7).
 *
 * `roomAliases.manage` is deliberately NOT part of this phase — see the
 * "F. alias exclusion" section below.
 */
class Stage69RoomScopeTest extends TestCase
{
    use RefreshDatabase;

    /* ================================================================== fixtures */

    private function ensureLocation(string $code, bool $active = true): Location
    {
        return Location::query()->firstOrCreate(
            ['code' => $code],
            ['name' => "Lokasi {$code}", 'is_active' => $active],
        );
    }

    private function seedUnitLocations(): void
    {
        $this->ensureLocation('02');
        $this->ensureLocation('03');
        $this->ensureLocation('04');
    }

    private function globalUser(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function unitAdmin(?string $locationCode): User
    {
        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    private function roomIn(string $locationCode, array $overrides = []): Room
    {
        $this->ensureLocation($locationCode);

        return Room::factory()->create(array_merge(['location_code' => $locationCode], $overrides));
    }

    private function assetIn(string $locationCode, array $overrides = []): Asset
    {
        $this->ensureLocation($locationCode);

        return Asset::factory()->create(array_merge(['location_code' => $locationCode], $overrides));
    }

    /* ================================================================== A. permissions */

    public function test_unit_admin_has_rooms_view_and_manage(): void
    {
        $this->assertTrue(PermissionRegistry::has(UserRole::UnitAdmin, 'rooms.view'));
        $this->assertTrue(PermissionRegistry::has(UserRole::UnitAdmin, 'rooms.manage'));
    }

    public function test_unit_admin_does_not_have_room_aliases_manage(): void
    {
        $this->assertFalse(PermissionRegistry::has(UserRole::UnitAdmin, 'roomAliases.manage'));
    }

    public function test_unit_admin_does_not_gain_master_data_abilities(): void
    {
        foreach (['locations.manage', 'categories.manage', 'subcategories.manage', 'users.manage'] as $ability) {
            $this->assertFalse(PermissionRegistry::has(UserRole::UnitAdmin, $ability));
        }
    }

    public function test_viewer_cannot_manage_rooms(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Ruang Baru'])->assertStatus(403);
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_operator_retains_no_room_management(): void
    {
        // Room management was never operator's — Tahap 6.8.1 always gated it
        // `can:admin`. R7 only adds unit_admin; operator's own scope is
        // untouched (still no rooms.manage in PermissionRegistry).
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Ruang Baru'])->assertStatus(403);
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'X'])->assertStatus(403);
    }

    public function test_legacy_admin_retains_global_room_management(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('03');
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Ruang Admin'])->assertCreated();
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'Diubah Admin'])->assertOk();
    }

    public function test_super_admin_has_global_room_management(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('03');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Ruang Super Admin'])->assertCreated();
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'Diubah Super Admin'])->assertOk();
    }

    /* ================================================================== B. read scope (regression only — see Stage69ReadScopeTest §G) */

    public function test_unit_admin_can_view_own_room(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/rooms/{$room->id}")->assertOk()->assertJsonPath('data.id', $room->id);
    }

    public function test_unit_admin_cannot_view_foreign_room(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/rooms/{$foreign->id}")->assertStatus(404);
    }

    /* ================================================================== C. create */

    public function test_unit_admin_creates_room_in_own_location(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Laboratorium'])
            ->assertCreated()
            ->assertJsonPath('data.location.code', '02')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('rooms', ['name' => 'Laboratorium', 'location_code' => '02']);
    }

    public function test_unit_admin_cannot_create_room_in_foreign_location(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/rooms', ['location_code' => '03', 'name' => 'Laboratorium'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('rooms', ['name' => 'Laboratorium', 'location_code' => '03']);
    }

    /** The submitted out-of-scope location must be rejected, never silently rewritten to the actor's own. */
    public function test_request_location_code_cannot_be_rewritten_or_overridden_on_create(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/rooms', ['location_code' => '04', 'name' => 'Gudang'])->assertStatus(403);
        $this->assertSame(0, Room::where('name', 'Gudang')->count());
    }

    public function test_missing_location_on_create_is_rejected_not_defaulted(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/rooms', ['name' => 'Tanpa Lokasi'])->assertStatus(422);
        $this->assertSame(0, Room::where('name', 'Tanpa Lokasi')->count());
    }

    /* ================================================================== D. update */

    public function test_unit_admin_updates_own_room(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02', ['name' => 'Ruang Lama']);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'Ruang Baru'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ruang Baru');
    }

    public function test_unit_admin_cannot_update_foreign_room(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->roomIn('03', ['name' => 'Original']);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/rooms/{$foreign->id}", ['name' => 'Diubah'])->assertStatus(403);
        $this->assertSame('Original', $foreign->fresh()->name);
    }

    public function test_unit_admin_cannot_transfer_room_outside_scope(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        // location_code is prohibited for every actor (room location is
        // immutable) — this proves that invariant still holds for unit_admin.
        $this->patchJson("/api/rooms/{$room->id}", ['location_code' => '03'])->assertStatus(422);
        $this->assertSame('02', $room->fresh()->location_code);
    }

    public function test_location_tampering_is_rejected_on_update(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/rooms/{$foreign->id}", ['location_code' => '02', 'name' => 'Hijack'])
            ->assertStatus(422);
        $this->assertSame('03', $foreign->fresh()->location_code);
    }

    /* ================================================================== E. lifecycle */

    public function test_unit_admin_deactivates_own_room(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/rooms/{$room->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($room->fresh()->is_active);
    }

    public function test_unit_admin_reactivates_own_room(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02', ['is_active' => false]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/rooms/{$room->id}", ['is_active' => true])->assertOk();
        $this->assertTrue($room->fresh()->is_active);
    }

    public function test_unit_admin_cannot_lifecycle_manage_foreign_room(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/rooms/{$foreign->id}", ['is_active' => false])->assertStatus(403);
        $this->assertTrue($foreign->fresh()->is_active);
    }

    /* ================================================================== F. asset interaction (R5 invariant, must remain intact) */

    public function test_own_asset_to_own_location_room_is_allowed(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$asset->id}", [
            'location_code' => '02',
            'category_code' => $asset->category_code,
            'subcategory_code' => $asset->subcategory_code,
            'asset_year' => $asset->asset_year,
            'room_id' => $room->id,
        ])->assertOk();
        $this->assertSame($room->id, $asset->fresh()->room_id);
    }

    public function test_own_asset_to_foreign_room_is_rejected(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $foreignRoom = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$asset->id}", [
            'location_code' => '02',
            'category_code' => $asset->category_code,
            'subcategory_code' => $asset->subcategory_code,
            'asset_year' => $asset->asset_year,
            'room_id' => $foreignRoom->id,
        ])->assertStatus(422);
        $this->assertNotSame($foreignRoom->id, $asset->fresh()->room_id);
    }

    public function test_foreign_asset_to_foreign_room_is_rejected_because_asset_itself_is_out_of_scope(): void
    {
        $this->seedUnitLocations();
        $foreignAsset = $this->assetIn('03');
        $foreignRoom = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$foreignAsset->id}", [
            'location_code' => '03',
            'category_code' => $foreignAsset->category_code,
            'subcategory_code' => $foreignAsset->subcategory_code,
            'asset_year' => $foreignAsset->asset_year,
            'room_id' => $foreignRoom->id,
        ])->assertStatus(403);
        $this->assertNotSame($foreignRoom->id, $foreignAsset->fresh()->room_id);
    }

    /* ================================================================== G. alias exclusion */

    public function test_unit_admin_cannot_create_alias(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/room-aliases', [
            'location_code' => '02',
            'room_id' => $room->id,
            'raw_value' => 'Lab Komputer',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('room_aliases', ['raw_value' => 'Lab Komputer']);
    }

    public function test_unit_admin_cannot_modify_alias(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        $alias = RoomAlias::factory()->create([
            'location_code' => '02',
            'room_id' => $room->id,
            'raw_value' => 'Original Alias',
            'match_key' => 'original alias',
        ]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson("/api/room-aliases/{$alias->id}", ['raw_value' => 'Changed Alias'])
            ->assertStatus(403);
        $this->assertSame('Original Alias', $alias->fresh()->raw_value);
    }

    public function test_unit_admin_cannot_delete_alias(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        $alias = RoomAlias::factory()->create([
            'location_code' => '02',
            'room_id' => $room->id,
        ]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->deleteJson("/api/room-aliases/{$alias->id}")->assertStatus(403);
        $this->assertDatabaseHas('room_aliases', ['id' => $alias->id]);
    }

    /** Existing authorized (operator/admin) alias behavior must remain unchanged by R7. */
    public function test_operator_alias_management_remains_unchanged(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->postJson('/api/room-aliases', [
            'location_code' => '02',
            'room_id' => $room->id,
            'raw_value' => 'Alias Operator',
        ])->assertCreated();
    }

    /** unit_admin needs no roomAliases.manage to USE an existing alias mapping during their own authorized import — read access to aliases stays open (R7 explicitly does not restrict alias READ). */
    public function test_unit_admin_can_still_read_existing_aliases(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        RoomAlias::factory()->create([
            'location_code' => '02',
            'room_id' => $room->id,
            'raw_value' => 'Lab Komputer',
            'match_key' => 'lab komputer',
        ]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/room-aliases?location_code=02')->assertOk()->assertJsonCount(1, 'data');
    }

    /* ================================================================== H. multiple unit admins */

    public function test_two_unit_admins_in_same_location_can_both_manage_its_rooms(): void
    {
        $this->seedUnitLocations();
        $a = $this->unitAdmin('02');
        $b = $this->unitAdmin('02');

        Sanctum::actingAs($a);
        $created = $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Ruang A'])
            ->assertCreated()->json('data');

        Sanctum::actingAs($b);
        $this->patchJson("/api/rooms/{$created['id']}", ['name' => 'Ruang A Diubah'])->assertOk();
    }

    public function test_each_unit_admin_remains_blocked_from_other_locations(): void
    {
        $this->seedUnitLocations();
        $a = $this->unitAdmin('02');
        $b = $this->unitAdmin('03');
        $roomInThree = $this->roomIn('03');

        Sanctum::actingAs($a);
        $this->postJson('/api/rooms', ['location_code' => '03', 'name' => 'Percobaan'])->assertStatus(403);
        $this->patchJson("/api/rooms/{$roomInThree->id}", ['name' => 'X'])->assertStatus(403);

        $roomInTwo = $this->roomIn('02');
        Sanctum::actingAs($b);
        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Percobaan Lain'])->assertStatus(403);
        $this->patchJson("/api/rooms/{$roomInTwo->id}", ['name' => 'Y'])->assertStatus(403);
    }

    /* ================================================================== I. invalid scope state */

    public function test_malformed_unit_admin_never_becomes_global_on_room_management(): void
    {
        $this->seedUnitLocations();
        $this->ensureLocation('01');
        // Bypasses app-level validation on purpose — simulates a corrupt/legacy row.
        $corrupt = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '01']);
        Sanctum::actingAs($corrupt);

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Percobaan'])->assertStatus(403);
        $this->assertSame(0, Room::where('name', 'Percobaan')->count());
    }

    /**
     * Unlike the asset write path (which re-validates `location_code` as
     * part of every payload, incidentally producing a 422 here), a room
     * update never submits `location_code` at all (it's `prohibited`) — the
     * only enforcement path is `LocationScope::for()` refusing to resolve a
     * scope for an actor whose OWN assigned location is inactive, which
     * surfaces as the framework's standard 403 for an authorization denial
     * (same convention as `Stage69ReadScopeTest::
     * test_inactive_unit_admin_location_never_becomes_global`).
     */
    public function test_inactive_assigned_location_never_becomes_global_on_room_management(): void
    {
        $this->ensureLocation('02');
        $room = $this->roomIn('02');
        $unitAdmin = $this->unitAdmin('02');
        Location::query()->where('code', '02')->update(['is_active' => false]);
        Sanctum::actingAs($unitAdmin);

        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'Diubah'])->assertStatus(403);
        $this->assertNotSame('Diubah', $room->fresh()->name);
    }

    public function test_unit_admin_with_null_location_cannot_manage_any_room(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        $corrupt = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => null]);
        Sanctum::actingAs($corrupt);

        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Percobaan'])->assertStatus(403);
        $this->patchJson("/api/rooms/{$room->id}", ['name' => 'X'])->assertStatus(403);
    }

    /* ================================================================== J. regression / security */

    public function test_room_existence_is_not_leaked_to_out_of_scope_unit_admin(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        // 404, never 403 — matches the existing R4 convention that a
        // cross-unit resource's existence is never confirmed.
        $this->getJson("/api/rooms/{$foreign->id}")->assertStatus(404);
    }

    public function test_query_parameter_location_cannot_override_scope_on_listing(): void
    {
        $this->seedUnitLocations();
        $this->roomIn('02');
        $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/locations/03/rooms')->assertStatus(404);
    }

    public function test_global_room_admin_index_behavior_unchanged(): void
    {
        $this->seedUnitLocations();
        $this->roomIn('02');
        $this->roomIn('03', ['is_active' => false]);
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_unit_admin_cannot_reach_global_room_admin_index(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/rooms')->assertStatus(403);
    }
}
