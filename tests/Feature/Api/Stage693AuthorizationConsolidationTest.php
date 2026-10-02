<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\Room;
use App\Models\RoomAlias;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stage 6.9 R9.3-A — Authorization consolidation (WHO = role, WHERE =
 * LocationScope, WHAT = named PermissionRegistry ability).
 *
 * Covers:
 *   A. structural guards over routes/api.php itself — no route may use the
 *      legacy `can:operator`/`can:admin` Gates again, every `can:` gate must be
 *      a registered ability, and whatever PermissionRegistry grants a role the
 *      route gate must grant too;
 *   B. super_admin parity on every endpoint the legacy Gates used to deny it;
 *   C. the trashed-asset show/restore fix (`AssetController::show()` no longer
 *      uses the legacy `canWriteInventory()`), incl. route-id manipulation;
 *   D. unit_admin stays denied everything the product decisions say it must
 *      not have (export, labels, generic alias list/CRUD, import history,
 *      flat room browser, structural master data);
 *   E. operator / viewer / legacy admin behaviour preserved;
 *   F. `/api/me` exposes exactly PermissionRegistry's abilities + the scope flag.
 *
 * `roomAliases.resolve` (the import room-mapping alias ability) is covered
 * in ImportRoomMappingTest, next to the R9.2 flow it authorizes.
 */
class Stage693AuthorizationConsolidationTest extends TestCase
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

    private function unitAdmin(string $locationCode): User
    {
        $this->ensureLocation($locationCode);

        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode]);
    }

    private function assetIn(string $locationCode): Asset
    {
        $this->ensureLocation($locationCode);

        return Asset::factory()->create(['location_code' => $locationCode]);
    }

    private function trashedAssetIn(string $locationCode): Asset
    {
        $asset = $this->assetIn($locationCode);
        $asset->delete();

        return $asset->fresh();
    }

    private function roomIn(string $locationCode): Room
    {
        $this->ensureLocation($locationCode);

        return Room::factory()->create(['location_code' => $locationCode]);
    }

    private function aliasIn(string $locationCode, string $rawValue = 'Ruang Lama'): RoomAlias
    {
        $room = $this->roomIn($locationCode);

        return RoomAlias::factory()->create([
            'location_code' => $locationCode,
            'room_id' => $room->id,
            'raw_value' => $rawValue,
            'match_key' => mb_strtolower($rawValue),
        ]);
    }

    /**
     * Every `can:<x>` middleware name on every registered route.
     *
     * @return list<array{uri:string, ability:string}>
     */
    private function routeGates(): array
    {
        $gates = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $gates[] = ['uri' => $route->methods()[0].' '.$route->uri(), 'ability' => substr($middleware, 4)];
                }
            }
        }

        return $gates;
    }

    /* ================================================================== A. structural route guards */

    public function test_no_route_uses_the_legacy_operator_or_admin_gate(): void
    {
        $legacy = array_filter($this->routeGates(), fn (array $g): bool => in_array($g['ability'], ['operator', 'admin'], true));

        $this->assertSame([], array_values($legacy), 'Legacy can:operator/can:admin must not gate any route (they silently deny super_admin).');
    }

    public function test_every_route_gate_is_viewer_or_a_registered_named_ability(): void
    {
        // guard against this whole section passing vacuously (e.g. if middleware stopped being discoverable)
        $abilities = array_column($this->routeGates(), 'ability');
        foreach (['viewer', 'assets.printLabel', 'assets.export', 'roomAliases.manage', 'locations.manage', 'categories.manage', 'subcategories.manage', 'rooms.manage', 'assets.import'] as $expected) {
            $this->assertContains($expected, $abilities);
        }

        foreach ($this->routeGates() as $gate) {
            $this->assertTrue(
                $gate['ability'] === 'viewer' || in_array($gate['ability'], PermissionRegistry::ABILITIES, true),
                "{$gate['uri']} uses unknown gate [{$gate['ability']}] — an undefined Gate denies everyone."
            );
        }
    }

    /** "PermissionRegistry says yes" must mean "the route gate says yes", for every role and every route ability. */
    public function test_route_gates_agree_with_permission_registry_for_every_role(): void
    {
        $this->seedUnitLocations();
        $users = [];
        foreach (UserRole::cases() as $role) {
            $users[$role->value] = $role === UserRole::UnitAdmin
                ? $this->unitAdmin('02')
                : $this->globalUser($role);
        }

        foreach ($this->routeGates() as $gate) {
            if ($gate['ability'] === 'viewer') {
                continue;
            }
            foreach ($users as $roleValue => $user) {
                $this->assertSame(
                    PermissionRegistry::has($user->role, $gate['ability']),
                    Gate::forUser($user)->allows($gate['ability']),
                    "{$gate['uri']} [{$gate['ability']}] disagrees with PermissionRegistry for {$roleValue}."
                );
            }
        }
    }

    public function test_super_admin_passes_every_route_gate(): void
    {
        $superAdmin = $this->globalUser(UserRole::SuperAdmin);

        foreach ($this->routeGates() as $gate) {
            $this->assertTrue(
                Gate::forUser($superAdmin)->allows($gate['ability']),
                "super_admin is blocked by {$gate['uri']} [{$gate['ability']}]."
            );
        }
    }

    /* ================================================================== B. super_admin parity */

    public function test_super_admin_can_show_active_and_trashed_assets_and_restore(): void
    {
        $this->seedUnitLocations();
        $active = $this->assetIn('03');
        $trashed = $this->trashedAssetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->getJson("/api/assets/{$active->id}")->assertOk()->assertJsonPath('data.is_trashed', false);
        $this->getJson("/api/assets/{$trashed->id}")->assertOk()->assertJsonPath('data.is_trashed', true);
        $this->postJson("/api/assets/{$trashed->id}/restore")->assertOk();
        $this->assertFalse($trashed->fresh()->trashed());
    }

    public function test_super_admin_can_print_labels_and_export(): void
    {
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->get("/api/assets/{$asset->id}/label")->assertOk();
        $this->post('/api/assets/batch/label', ['asset_ids' => [$asset->id]])->assertOk();
        $this->get('/api/assets/export')->assertOk();
    }

    public function test_super_admin_can_list_import_history(): void
    {
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->getJson('/api/imports')->assertOk();
    }

    public function test_super_admin_can_browse_and_manage_rooms(): void
    {
        $this->seedUnitLocations();
        $this->roomIn('03');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/rooms', ['location_code' => '04', 'name' => 'Ruang Super'])->assertCreated();
    }

    public function test_super_admin_can_manage_locations_categories_and_subcategories(): void
    {
        $this->ensureLocation('09', active: false);
        Category::query()->create(['code' => '98', 'name' => 'Kategori Nonaktif', 'is_active' => false]);
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'Lokasi Super'])->assertCreated();
        $this->patchJson('/api/locations/ZZ', ['name' => 'Lokasi Super 2'])->assertOk();
        $this->getJson('/api/locations?include_inactive=1')->assertOk()->assertJsonFragment(['code' => '09']);

        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'Kategori Super'])->assertCreated();
        $this->patchJson('/api/categories/ZZ', ['name' => 'Kategori Super 2'])->assertOk();
        $this->getJson('/api/categories?include_inactive=1')->assertOk()->assertJsonFragment(['code' => '98']);

        $this->getJson('/api/subcategories')->assertOk();
        $sub = $this->postJson('/api/subcategories', ['category_code' => 'ZZ', 'code' => '001', 'name' => 'Sub Super'])
            ->assertCreated()->json('data');
        $this->patchJson("/api/subcategories/{$sub['id']}", ['name' => 'Sub Super 2'])->assertOk();
    }

    public function test_super_admin_has_generic_room_alias_management(): void
    {
        $this->seedUnitLocations();
        $existing = $this->aliasIn('03');
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->getJson('/api/room-aliases')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/room-aliases', ['location_code' => '02', 'room_id' => $room->id, 'raw_value' => 'Alias Super'])
            ->assertCreated();
        $this->patchJson("/api/room-aliases/{$existing->id}", ['raw_value' => 'Ruang Lama Diubah'])->assertOk();
        $this->deleteJson("/api/room-aliases/{$existing->id}")->assertOk();
    }

    /* ================================================================== C. trashed asset show / restore */

    public function test_unit_admin_can_open_and_restore_own_location_trashed_asset(): void
    {
        $this->seedUnitLocations();
        $asset = $this->trashedAssetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonPath('data.is_trashed', true);
        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();
        $this->assertFalse($asset->fresh()->trashed());
        $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonPath('data.is_trashed', false);
    }

    /** Route-id manipulation: a foreign trashed asset is neither visible (404, existence not confirmed) nor restorable (403). */
    public function test_unit_admin_cannot_open_or_restore_foreign_location_trashed_asset_by_id(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->trashedAssetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/assets/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/assets/{$foreign->id}/restore")->assertForbidden();
        $this->assertTrue($foreign->fresh()->trashed());

        $missingId = $foreign->id + 1000;
        $this->getJson("/api/assets/{$missingId}")->assertNotFound();
        $this->postJson("/api/assets/{$missingId}/restore")->assertNotFound();
    }

    public function test_unit_admin_cannot_open_foreign_location_active_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/assets/{$foreign->id}")->assertNotFound();
    }

    public function test_viewer_still_gets_404_for_trashed_asset_and_cannot_restore(): void
    {
        $asset = $this->trashedAssetIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->getJson("/api/assets/{$asset->id}")->assertNotFound();
        $this->postJson("/api/assets/{$asset->id}/restore")->assertForbidden();
        $this->assertTrue($asset->fresh()->trashed());
    }

    public function test_operator_and_admin_trashed_asset_behaviour_is_unchanged(): void
    {
        foreach ([UserRole::Operator, UserRole::Admin] as $role) {
            $asset = $this->trashedAssetIn('02');
            Sanctum::actingAs($this->globalUser($role));

            $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonPath('data.is_trashed', true);
            $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();
        }
    }

    /* ================================================================== D. unit_admin stays denied */

    public function test_unit_admin_is_denied_export_and_labels_even_for_own_location(): void
    {
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/assets/export')->assertForbidden();
        $this->getJson("/api/assets/{$asset->id}/label")->assertForbidden();
        $this->postJson('/api/assets/batch/label', ['asset_ids' => [$asset->id]])->assertForbidden();
    }

    public function test_unit_admin_is_denied_generic_room_alias_crud_even_in_own_location(): void
    {
        $this->seedUnitLocations();
        $own = $this->aliasIn('02');
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/room-aliases', ['location_code' => '02', 'room_id' => $room->id, 'raw_value' => 'Alias Unit'])
            ->assertForbidden();
        $this->patchJson("/api/room-aliases/{$own->id}", ['raw_value' => 'Diubah'])->assertForbidden();
        $this->deleteJson("/api/room-aliases/{$own->id}")->assertForbidden();

        $this->assertSame('Ruang Lama', $own->fresh()->raw_value);
        $this->assertSame(1, RoomAlias::query()->count());
    }

    public function test_unit_admin_generic_alias_listing_does_not_leak_other_locations(): void
    {
        $this->seedUnitLocations();
        $this->aliasIn('02', 'Alias Sendiri');
        $this->aliasIn('03', 'Alias Unit Lain');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/room-aliases')->assertForbidden()->assertDontSee('Alias Unit Lain');
        $this->getJson('/api/room-aliases?location_code=03')->assertForbidden()->assertDontSee('Alias Unit Lain');
        $this->getJson('/api/room-aliases?location_code=02')->assertForbidden();
    }

    public function test_unit_admin_is_denied_unscoped_lists_and_structural_master_data(): void
    {
        $this->seedUnitLocations();
        $this->ensureLocation('09', active: false);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/imports')->assertForbidden();
        $this->getJson('/api/rooms')->assertForbidden();
        $this->getJson('/api/subcategories')->assertForbidden();
        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'X'])->assertForbidden();
        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'X'])->assertForbidden();

        // include_inactive is silently ignored (not an error) for a non-manager, as before
        $this->getJson('/api/locations?include_inactive=1')->assertOk()->assertJsonMissing(['code' => '09']);
    }

    /* ================================================================== E. operator / viewer / admin preserved */

    public function test_operator_behaviour_is_preserved(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $alias = $this->aliasIn('03');
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->get("/api/assets/{$asset->id}/label")->assertOk();
        $this->get('/api/assets/export')->assertOk();
        $this->getJson('/api/imports')->assertOk();
        $this->getJson('/api/room-aliases')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/room-aliases', ['location_code' => '02', 'room_id' => $room->id, 'raw_value' => 'Alias Operator'])
            ->assertCreated();
        $this->patchJson("/api/room-aliases/{$alias->id}", ['raw_value' => 'Diubah Operator'])->assertOk();

        // still no structural master data / room management (never held them)
        $this->getJson('/api/rooms')->assertForbidden();
        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'Ruang Operator'])->assertForbidden();
        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'X'])->assertForbidden();
        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'X'])->assertForbidden();
        $this->getJson('/api/subcategories')->assertForbidden();
    }

    public function test_viewer_behaviour_is_preserved(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $alias = $this->aliasIn('03');
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->getJson("/api/assets/{$asset->id}")->assertOk();
        $this->getJson('/api/room-aliases')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/assets/{$asset->id}/label")->assertForbidden();
        $this->getJson('/api/assets/export')->assertForbidden();
        $this->getJson('/api/imports')->assertForbidden();
        $this->patchJson("/api/room-aliases/{$alias->id}", ['raw_value' => 'X'])->assertForbidden();
        $this->deleteJson("/api/room-aliases/{$alias->id}")->assertForbidden();
        $this->getJson('/api/rooms')->assertForbidden();
        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'X'])->assertForbidden();
    }

    public function test_legacy_admin_behaviour_is_preserved(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->get("/api/assets/{$asset->id}/label")->assertOk();
        $this->get('/api/assets/export')->assertOk();
        $this->getJson('/api/imports')->assertOk();
        $this->getJson('/api/rooms')->assertOk();
        $this->getJson('/api/room-aliases')->assertOk();
        $this->getJson('/api/subcategories')->assertOk();
        $this->postJson('/api/locations', ['code' => 'ZZ', 'name' => 'Lokasi Admin'])->assertCreated();
        $this->postJson('/api/categories', ['code' => 'ZZ', 'name' => 'Kategori Admin'])->assertCreated();
    }

    /* ================================================================== F. /api/me */

    public function test_me_abilities_are_exactly_the_permission_registry_set_for_every_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = $role === UserRole::UnitAdmin ? $this->unitAdmin('02') : $this->globalUser($role);
            Sanctum::actingAs($user);

            $this->getJson('/api/me')
                ->assertOk()
                ->assertJsonPath('data.abilities', PermissionRegistry::abilitiesForRole($role))
                ->assertJsonPath('data.is_global_scope', $role !== UserRole::UnitAdmin);
        }
    }

    public function test_me_reflects_the_r93_product_decisions(): void
    {
        Sanctum::actingAs($this->unitAdmin('02'));
        $unit = $this->getJson('/api/me')->assertOk()->json('data.abilities');
        $this->assertContains('roomAliases.resolve', $unit);
        $this->assertContains('rooms.manage', $unit);
        $this->assertNotContains('roomAliases.manage', $unit);
        $this->assertNotContains('assets.export', $unit);
        $this->assertNotContains('assets.printLabel', $unit);
        $this->assertNotContains('locations.manage', $unit);

        Sanctum::actingAs($this->globalUser(UserRole::Operator));
        $operator = $this->getJson('/api/me')->assertOk()->json('data.abilities');
        $this->assertContains('roomAliases.resolve', $operator);
        $this->assertContains('roomAliases.manage', $operator);
        $this->assertNotContains('rooms.manage', $operator);

        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));
        $this->assertSame(PermissionRegistry::ABILITIES, $this->getJson('/api/me')->json('data.abilities'));
    }

    /** A corrupt unit_admin (no location) must never be reported as global scope. */
    public function test_me_reports_a_corrupt_unit_admin_as_not_global(): void
    {
        $user = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.is_global_scope', false);
    }
}
