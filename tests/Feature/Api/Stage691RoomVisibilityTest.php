<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * R7.1 — `?include_inactive=1` on `GET /api/locations/{location}/rooms`
 * (`RoomController::index()`), gated on `can:rooms.manage`. Added so a
 * `unit_admin`/`super_admin` (neither of whom can reach the legacy
 * `can:admin`-only `GET /api/rooms` admin browser) has SOME way to see an
 * inactive room in their own scope in order to reactivate it — without this,
 * `rooms.manage`'s reactivate action would be functionally unreachable for
 * them once a room disappears from the active-only default list.
 *
 * Default behaviour (the param omitted, or sent by a caller without
 * `rooms.manage`) must stay byte-identical to before — covered by the
 * pre-existing `MasterDataReadApiTest`/`Stage69ReadScopeTest`, not repeated
 * here.
 */
class Stage691RoomVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function ensureLocation(string $code, bool $active = true): Location
    {
        return Location::query()->firstOrCreate(
            ['code' => $code],
            ['name' => "Lokasi {$code}", 'is_active' => $active],
        );
    }

    private function globalUser(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function unitAdmin(?string $locationCode): User
    {
        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    public function test_default_listing_excludes_inactive_rooms_for_every_role(): void
    {
        $location = $this->ensureLocation('02');
        Room::factory()->create(['location_code' => '02', 'is_active' => true, 'name' => 'Aktif']);
        Room::factory()->create(['location_code' => '02', 'is_active' => false, 'name' => 'Nonaktif']);
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->getJson('/api/locations/02/rooms')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_include_inactive_is_ignored_without_rooms_manage_ability(): void
    {
        $this->ensureLocation('02');
        Room::factory()->create(['location_code' => '02', 'is_active' => false, 'name' => 'Nonaktif']);

        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $this->getJson('/api/locations/02/rooms?include_inactive=1')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->globalUser(UserRole::Viewer));
        $this->getJson('/api/locations/02/rooms?include_inactive=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_sees_inactive_rooms_with_include_inactive(): void
    {
        $this->ensureLocation('02');
        Room::factory()->create(['location_code' => '02', 'is_active' => false, 'name' => 'Nonaktif']);
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->getJson('/api/locations/02/rooms?include_inactive=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_super_admin_sees_inactive_rooms_with_include_inactive(): void
    {
        $this->ensureLocation('02');
        Room::factory()->create(['location_code' => '02', 'is_active' => false, 'name' => 'Nonaktif']);
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->getJson('/api/locations/02/rooms?include_inactive=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unit_admin_sees_own_inactive_room_with_include_inactive(): void
    {
        $this->ensureLocation('02');
        Room::factory()->create(['location_code' => '02', 'is_active' => false, 'name' => 'Nonaktif']);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/locations/02/rooms?include_inactive=1')->assertOk()->assertJsonCount(1, 'data');
    }

    /** The opt-in never widens LOCATION scope — only whether inactive rows show up within an already-authorized location. */
    public function test_unit_admin_cannot_use_include_inactive_to_see_another_locations_rooms(): void
    {
        $this->ensureLocation('02');
        $this->ensureLocation('03');
        Room::factory()->create(['location_code' => '03', 'is_active' => false, 'name' => 'Nonaktif']);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/locations/03/rooms?include_inactive=1')->assertStatus(404);
    }
}
