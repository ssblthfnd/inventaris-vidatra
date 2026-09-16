<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use App\Support\LocationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Unit\Support\LocationScopeTest;

/**
 * Stage 6.9 R4 — read-path location scope. Every test here hits a real HTTP
 * endpoint (never a helper/unit-level shortcut) so each one demonstrates
 * actual backend enforcement, not just that {@see LocationScope}
 * itself behaves correctly in isolation (already covered by
 * {@see LocationScopeTest}).
 *
 * R4 is READ-ONLY authorization: no test here exercises or asserts anything
 * about create/update/delete/batch/import/write-off/restore/revert — those
 * stay completely ungated for unit_admin until a later phase, and nothing
 * in this file should be read as proving otherwise.
 */
class Stage69ReadScopeTest extends TestCase
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

    private function assetIn(string $locationCode, array $overrides = []): Asset
    {
        $this->ensureLocation($locationCode);

        return Asset::factory()->create(array_merge(['location_code' => $locationCode], $overrides));
    }

    private function roomIn(string $locationCode, array $overrides = []): Room
    {
        $this->ensureLocation($locationCode);

        return Room::factory()->create(array_merge(['location_code' => $locationCode], $overrides));
    }

    /* ================================================================== A. global roles see everything */

    public function test_super_admin_sees_all_locations_in_asset_list(): void
    {
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_legacy_admin_sees_all_locations_in_asset_list(): void
    {
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_operator_sees_all_locations_in_asset_list(): void
    {
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_viewer_sees_all_locations_in_asset_list(): void
    {
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 3);
    }

    /* ================================================================== B. unit_admin basic scope */

    public function test_unit_admin_02_sees_only_02_assets(): void
    {
        $this->seedUnitLocations();
        $mine = $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('02'));

        $response = $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_unit_admin_03_sees_only_03_assets(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $mine = $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('03'));

        $response = $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_unit_admin_04_sees_only_04_assets(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        $mine = $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('04'));

        $response = $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    /* ================================================================== C. asset detail IDOR */

    public function test_unit_admin_02_cannot_retrieve_asset_from_03(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/assets/{$foreign->id}")->assertStatus(404);
    }

    public function test_unit_admin_02_cannot_retrieve_asset_from_04(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/assets/{$foreign->id}")->assertStatus(404);
    }

    public function test_unit_admin_03_cannot_retrieve_asset_from_02(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('03'));

        $this->getJson("/api/assets/{$foreign->id}")->assertStatus(404);
    }

    public function test_unit_admin_03_cannot_retrieve_asset_from_04(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('03'));

        $this->getJson("/api/assets/{$foreign->id}")->assertStatus(404);
    }

    public function test_unit_admin_04_cannot_retrieve_asset_from_02(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('04'));

        $this->getJson("/api/assets/{$foreign->id}")->assertStatus(404);
    }

    public function test_unit_admin_04_cannot_retrieve_asset_from_03(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('04'));

        $this->getJson("/api/assets/{$foreign->id}")->assertStatus(404);
    }

    /** Bonus: mutation history is keyed by the same asset id — same IDOR must hold there too. */
    public function test_unit_admin_02_cannot_read_mutation_history_of_asset_from_03(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/assets/{$foreign->id}/mutations")->assertStatus(404);
    }

    public function test_unit_admin_02_can_retrieve_its_own_asset(): void
    {
        $this->seedUnitLocations();
        $mine = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/assets/{$mine->id}")->assertOk()->assertJsonPath('data.id', $mine->id);
    }

    /* ================================================================== D. filter manipulation */

    public function test_unit_admin_02_cannot_broaden_with_location_code_03(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/assets?'.http_build_query(['location_code' => '03']))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unit_admin_02_cannot_broaden_with_location_code_04(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/assets?'.http_build_query(['location_code' => '04']))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unit_admin_03_cannot_broaden_with_location_code_02(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('03'));

        $this->getJson('/api/assets?'.http_build_query(['location_code' => '02']))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unit_admin_04_cannot_broaden_with_location_code_02(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('04'));

        $this->getJson('/api/assets?'.http_build_query(['location_code' => '02']))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unit_admin_02_multi_location_request_is_intersected_with_own_scope(): void
    {
        $this->seedUnitLocations();
        $mine = $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('02'));

        $response = $this->getJson('/api/assets?'.http_build_query(['location_code' => ['02', '03', '04']]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    /** Global roles' existing multi-location filtering must not regress. */
    public function test_global_role_multi_location_filter_still_returns_both_requested_locations(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->getJson('/api/assets?'.http_build_query(['location_code' => ['02', '03']]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    /* ================================================================== E. dashboard */

    public function test_unit_admin_02_dashboard_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('02'));

        $response = $this->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('data.summary.total_assets', 1);

        $byLocation = collect($response->json('data.by_location'))->keyBy('code');
        $this->assertSame(1, $byLocation['02']['asset_count']);
        $this->assertSame(0, $byLocation['03']['asset_count']);
        $this->assertSame(0, $byLocation['04']['asset_count']);
    }

    public function test_unit_admin_03_dashboard_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('03'));

        $response = $this->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('data.summary.total_assets', 1);

        $byLocation = collect($response->json('data.by_location'))->keyBy('code');
        $this->assertSame(0, $byLocation['02']['asset_count']);
        $this->assertSame(1, $byLocation['03']['asset_count']);
        $this->assertSame(0, $byLocation['04']['asset_count']);
    }

    public function test_unit_admin_04_dashboard_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('04'));

        $response = $this->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('data.summary.total_assets', 1);

        $byLocation = collect($response->json('data.by_location'))->keyBy('code');
        $this->assertSame(0, $byLocation['02']['asset_count']);
        $this->assertSame(0, $byLocation['03']['asset_count']);
        $this->assertSame(1, $byLocation['04']['asset_count']);
    }

    public function test_global_dashboard_remains_unchanged(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $response = $this->getJson('/api/dashboard')->assertOk();
        $response->assertJsonPath('data.summary.total_assets', 3);

        $byLocation = collect($response->json('data.by_location'))->keyBy('code');
        $this->assertSame(1, $byLocation['02']['asset_count']);
        $this->assertSame(1, $byLocation['03']['asset_count']);
        $this->assertSame(1, $byLocation['04']['asset_count']);
    }

    /* ================================================================== F. reports */

    public function test_unit_admin_02_report_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/reports/inventory')
            ->assertOk()
            ->assertJsonPath('data.summary.total_assets', 1);
    }

    public function test_unit_admin_03_report_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('03'));

        $this->getJson('/api/reports/inventory')
            ->assertOk()
            ->assertJsonPath('data.summary.total_assets', 1);
    }

    public function test_unit_admin_04_report_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('04');
        Sanctum::actingAs($this->unitAdmin('04'));

        $this->getJson('/api/reports/inventory')
            ->assertOk()
            ->assertJsonPath('data.summary.total_assets', 1);
    }

    public function test_global_report_remains_unchanged(): void
    {
        $this->seedUnitLocations();
        $this->assetIn('02');
        $this->assetIn('03');
        $this->assetIn('04');
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->getJson('/api/reports/inventory')
            ->assertOk()
            ->assertJsonPath('data.summary.total_assets', 3);
    }

    /** Regression: a viewer must still be forbidden from reports, exactly as before R4. */
    public function test_viewer_still_forbidden_from_reports(): void
    {
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->getJson('/api/reports/inventory')->assertStatus(403);
    }

    /** unit_admin must be forbidden from export — R4 deliberately leaves export ungated for unit_admin. */
    public function test_unit_admin_still_forbidden_from_export(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/assets/export')->assertStatus(403);
    }

    /* ================================================================== G. rooms */

    public function test_unit_admin_02_room_listing_is_scoped(): void
    {
        $this->seedUnitLocations();
        $mine = $this->roomIn('02');
        $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $response = $this->getJson('/api/locations/02/rooms')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_unit_admin_03_room_listing_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->roomIn('02');
        $mine = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('03'));

        $response = $this->getJson('/api/locations/03/rooms')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_unit_admin_04_room_listing_is_scoped(): void
    {
        $this->seedUnitLocations();
        $this->roomIn('02');
        $mine = $this->roomIn('04');
        Sanctum::actingAs($this->unitAdmin('04'));

        $response = $this->getJson('/api/locations/04/rooms')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_unit_admin_cannot_query_another_locations_rooms(): void
    {
        $this->seedUnitLocations();
        $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/locations/03/rooms')->assertStatus(404);
    }

    public function test_unit_admin_cannot_retrieve_another_locations_room_by_id(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson("/api/rooms/{$foreign->id}")->assertStatus(404);
    }

    /** Regression: global roles' room listing must not narrow at all. */
    public function test_global_role_room_listing_is_unaffected(): void
    {
        $this->seedUnitLocations();
        $this->roomIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->getJson('/api/locations/02/rooms')->assertOk()->assertJsonCount(1, 'data');
    }

    /* ================================================================== H. security / fail-safe */

    public function test_invalid_unit_admin_location_never_becomes_global(): void
    {
        $this->seedUnitLocations();
        $this->ensureLocation('01');
        $this->assetIn('02');
        $this->assetIn('03');
        // Bypasses app-level validation on purpose — simulates a corrupt/legacy
        // row, exactly the state UserLocationValidator (R2) would normally
        // prevent from ever being created via the API. Location '01' must
        // exist for the FK alone; UserRole::UNIT_ADMIN_LOCATION_CODES still
        // excludes it regardless of the row's own is_active state.
        $corrupt = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '01']);
        Sanctum::actingAs($corrupt);

        $this->getJson('/api/assets')->assertStatus(403);
    }

    public function test_inactive_unit_admin_location_never_becomes_global(): void
    {
        $this->ensureLocation('02');
        $this->assetIn('02');
        $unitAdmin = $this->unitAdmin('02');
        Location::query()->where('code', '02')->update(['is_active' => false]);
        Sanctum::actingAs($unitAdmin);

        $this->getJson('/api/assets')->assertStatus(403);
    }

    public function test_unit_admin_with_null_location_cannot_access_global_inventory(): void
    {
        $this->assetIn('02');
        // Also bypasses app-level validation — UserLocationValidator would
        // reject this combination via the API.
        $corrupt = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => null]);
        Sanctum::actingAs($corrupt);

        $this->getJson('/api/assets')->assertStatus(403);
    }

    /** Existing legacy roles must not regress across every scoped endpoint touched by R4. */
    public function test_legacy_roles_do_not_regress_across_every_touched_endpoint(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $this->roomIn('03');
        $operator = $this->globalUser(UserRole::Operator);
        Sanctum::actingAs($operator);

        $this->getJson('/api/assets')->assertOk();
        $this->getJson("/api/assets/{$asset->id}")->assertOk();
        $this->getJson("/api/assets/{$asset->id}/mutations")->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/reports/inventory')->assertOk();
        $this->getJson('/api/locations/03/rooms')->assertOk();
    }
}
