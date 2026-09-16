<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\MutationLog;
use App\Models\Room;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stage 6.9 R5 — write-path location scope. Every test hits a real HTTP
 * endpoint. R5 activates real inventory WRITE access for `unit_admin` for
 * the first time — nothing before this phase let a unit_admin mutate
 * anything (every asset write route was `can:operator`, which unit_admin
 * has never satisfied). Global roles (super_admin/admin/operator/viewer)
 * must see byte-identical behavior to before, EXCEPT that `super_admin` now
 * also gains real inventory write access for the first time (it was
 * previously blocked by `can:operator` despite `PermissionRegistry` always
 * having declared it `'*'` — activating the named-ability route gates in R5
 * naturally resolves that long-standing gap; see the R5 report).
 */
class Stage69WriteScopeTest extends TestCase
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

    private function trashedAssetIn(string $locationCode, array $overrides = []): Asset
    {
        $asset = $this->assetIn($locationCode, $overrides);
        $asset->delete();

        return $asset->fresh();
    }

    private function roomIn(string $locationCode, array $overrides = []): Room
    {
        $this->ensureLocation($locationCode);

        return Room::factory()->create(array_merge(['location_code' => $locationCode], $overrides));
    }

    /** A real, active (category, subcategory) pair for `POST /api/assets`. */
    private function classification(): array
    {
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->forCategory($category)->create();

        return [$category->code, $subcategory->code];
    }

    private function createPayload(string $locationCode, array $overrides = []): array
    {
        $this->ensureLocation($locationCode);
        [$categoryCode, $subcategoryCode] = $this->classification();

        return array_merge([
            'location_code' => $locationCode,
            'category_code' => $categoryCode,
            'subcategory_code' => $subcategoryCode,
            'asset_year' => 2025,
            'condition' => 'baik',
        ], $overrides);
    }

    private function updatePayload(Asset $asset, array $overrides = []): array
    {
        return array_merge([
            'location_code' => $asset->location_code,
            'category_code' => $asset->category_code,
            'subcategory_code' => $asset->subcategory_code,
            'asset_year' => $asset->asset_year,
            'brand_model' => 'Updated Brand',
        ], $overrides);
    }

    /* ================================================================== A. create */

    public function test_unit_admin_02_creates_asset_in_02(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/assets', $this->createPayload('02'))
            ->assertCreated()
            ->assertJsonPath('data.location.code', '02');
    }

    public function test_unit_admin_02_cannot_create_asset_in_03(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/assets', $this->createPayload('03'))->assertStatus(403);
        $this->assertSame(0, Asset::where('location_code', '03')->count());
    }

    public function test_unit_admin_03_cannot_create_asset_in_02(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('03'));

        $this->postJson('/api/assets', $this->createPayload('02'))->assertStatus(403);
        $this->assertSame(0, Asset::where('location_code', '02')->count());
    }

    public function test_unit_admin_04_cannot_create_asset_in_03(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('04'));

        $this->postJson('/api/assets', $this->createPayload('03'))->assertStatus(403);
        $this->assertSame(0, Asset::where('location_code', '03')->count());
    }

    public function test_unit_admin_02_batch_create_in_03_is_rejected_and_creates_nothing(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $payload = $this->createPayload('03', ['count' => 3]);
        $this->postJson('/api/assets/batch', $payload)->assertStatus(403);
        $this->assertSame(0, Asset::where('location_code', '03')->count());
    }

    /* ================================================================== B. single edit */

    public function test_unit_admin_02_edits_own_asset(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset))
            ->assertOk()
            ->assertJsonPath('data.brand_model', 'Updated Brand');
    }

    public function test_unit_admin_02_cannot_edit_03_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03', ['brand_model' => 'Original']);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$foreign->id}", $this->updatePayload($foreign))->assertStatus(403);
        $this->assertSame('Original', $foreign->fresh()->brand_model);
    }

    public function test_unit_admin_03_cannot_edit_02_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('02', ['brand_model' => 'Original']);
        Sanctum::actingAs($this->unitAdmin('03'));

        $this->putJson("/api/assets/{$foreign->id}", $this->updatePayload($foreign))->assertStatus(403);
        $this->assertSame('Original', $foreign->fresh()->brand_model);
    }

    public function test_unit_admin_cannot_change_own_assets_location_code_to_another_unit(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset, ['location_code' => '03']))
            ->assertStatus(403);
        $this->assertSame('02', $asset->fresh()->location_code);
    }

    /* ================================================================== C. batch edit atomicity */

    public function test_unit_admin_02_batch_edits_multiple_02_assets_successfully(): void
    {
        $this->seedUnitLocations();
        $a = $this->assetIn('02');
        $b = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a->id, $b->id],
            'changes' => ['notes' => 'batch note'],
        ])->assertOk()->assertJsonPath('updated', 2);

        $this->assertSame('batch note', $a->fresh()->notes);
        $this->assertSame('batch note', $b->fresh()->notes);
    }

    public function test_unit_admin_02_batch_edit_02_plus_03_is_entirely_rejected(): void
    {
        $this->seedUnitLocations();
        $a = $this->assetIn('02', ['notes' => 'original-a']);
        $c = $this->assetIn('03', ['notes' => 'original-c']);
        $mutationsBefore = MutationLog::count();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a->id, $c->id],
            'changes' => ['notes' => 'batch note'],
        ])->assertStatus(403);

        $this->assertSame('original-a', $a->fresh()->notes);
        $this->assertSame('original-c', $c->fresh()->notes);
        $this->assertSame($mutationsBefore, MutationLog::count());
    }

    public function test_unit_admin_03_batch_edit_03_plus_02_is_entirely_rejected(): void
    {
        $this->seedUnitLocations();
        $a = $this->assetIn('03', ['notes' => 'original-a']);
        $b = $this->assetIn('02', ['notes' => 'original-b']);
        Sanctum::actingAs($this->unitAdmin('03'));

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a->id, $b->id],
            'changes' => ['notes' => 'batch note'],
        ])->assertStatus(403);

        $this->assertSame('original-a', $a->fresh()->notes);
        $this->assertSame('original-b', $b->fresh()->notes);
    }

    public function test_unit_admin_04_batch_edit_04_plus_03_is_entirely_rejected(): void
    {
        $this->seedUnitLocations();
        $a = $this->assetIn('04', ['notes' => 'original-a']);
        $b = $this->assetIn('03', ['notes' => 'original-b']);
        Sanctum::actingAs($this->unitAdmin('04'));

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a->id, $b->id],
            'changes' => ['notes' => 'batch note'],
        ])->assertStatus(403);

        $this->assertSame('original-a', $a->fresh()->notes);
        $this->assertSame('original-b', $b->fresh()->notes);
    }

    /* ================================================================== D. delete */

    public function test_unit_admin_deletes_own_asset(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();
        $this->assertTrue($asset->fresh()->trashed());
    }

    public function test_unit_admin_cannot_delete_another_unit_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->deleteJson("/api/assets/{$foreign->id}")->assertStatus(403);
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_unit_admin_mixed_batch_delete_is_entirely_rejected(): void
    {
        $this->seedUnitLocations();
        $a = $this->assetIn('02');
        $c = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->deleteJson('/api/assets/batch', ['asset_ids' => [$a->id, $c->id]])->assertStatus(403);

        $this->assertFalse($a->fresh()->trashed());
        $this->assertFalse($c->fresh()->trashed());
    }

    /* ================================================================== E. restore */

    public function test_unit_admin_restores_own_trashed_asset(): void
    {
        $this->seedUnitLocations();
        $asset = $this->trashedAssetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();
        $this->assertFalse($asset->fresh()->trashed());
    }

    public function test_unit_admin_cannot_restore_another_unit_trashed_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->trashedAssetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson("/api/assets/{$foreign->id}/restore")->assertStatus(403);
        $this->assertTrue($foreign->fresh()->trashed());
    }

    /* ================================================================== F. write-off / unwrite-off */

    public function test_unit_admin_writes_off_own_asset(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => now()->toDateString()])
            ->assertOk();
        $this->assertTrue($asset->fresh()->is_written_off);
    }

    public function test_unit_admin_cannot_write_off_another_unit_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson("/api/assets/{$foreign->id}/write-off", ['written_off_on' => now()->toDateString()])
            ->assertStatus(403);
        $this->assertFalse($foreign->fresh()->is_written_off);
    }

    public function test_unit_admin_unwrite_offs_own_asset(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02', ['is_written_off' => true, 'written_off_on' => now()->toDateString()]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson("/api/assets/{$asset->id}/unwrite-off")->assertOk();
        $this->assertFalse($asset->fresh()->is_written_off);
    }

    public function test_unit_admin_cannot_unwrite_off_another_unit_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03', ['is_written_off' => true, 'written_off_on' => now()->toDateString()]);
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson("/api/assets/{$foreign->id}/unwrite-off")->assertStatus(403);
        $this->assertTrue($foreign->fresh()->is_written_off);
    }

    /* ================================================================== G. move room */

    public function test_unit_admin_moves_own_asset_to_own_location_room(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset, ['room_id' => $room->id]))
            ->assertOk();
        $this->assertSame($room->id, $asset->fresh()->room_id);
    }

    /** Already blocked by pre-existing structural validation (a room must match the submitted location_code) — confirms the invariant holds even without reaching the new authorization layer. */
    public function test_unit_admin_cannot_move_own_asset_to_another_location_room(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $foreignRoom = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset, ['room_id' => $foreignRoom->id]))
            ->assertStatus(422);
        $this->assertNotSame($foreignRoom->id, $asset->fresh()->room_id);
    }

    public function test_unit_admin_cannot_move_another_units_asset(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03');
        $room = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$foreign->id}", $this->updatePayload($foreign, ['room_id' => $room->id]))
            ->assertStatus(403);
        $this->assertNotSame($room->id, $foreign->fresh()->room_id);
    }

    public function test_cross_location_move_cannot_change_asset_location_indirectly(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        $room = $this->roomIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        // Attempting to simultaneously change location_code AND target a
        // matching room in that new location — still rejected, at the
        // authorization layer this time (location_code itself out of scope).
        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset, [
            'location_code' => '03',
            'room_id' => $room->id,
        ]))->assertStatus(403);

        $asset->refresh();
        $this->assertSame('02', $asset->location_code);
        $this->assertNotSame($room->id, $asset->room_id);
    }

    /* ================================================================== H. revert */

    public function test_unit_admin_reverts_own_location_mutation(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02', ['brand_model' => 'Original']);
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));
        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset, ['brand_model' => 'Changed']))->assertOk();
        $mutation = MutationLog::where('asset_id', $asset->id)->latest('id')->first();

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->postJson("/api/mutations/{$mutation->id}/revert")->assertOk();

        $this->assertSame('Original', $asset->fresh()->brand_model);
    }

    public function test_unit_admin_cannot_revert_another_units_mutation(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03', ['brand_model' => 'Original']);
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));
        $this->putJson("/api/assets/{$foreign->id}", $this->updatePayload($foreign, ['brand_model' => 'Changed']))->assertOk();
        $mutation = MutationLog::where('asset_id', $foreign->id)->latest('id')->first();

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->postJson("/api/mutations/{$mutation->id}/revert")->assertStatus(403);

        $this->assertSame('Changed', $foreign->fresh()->brand_model);
    }

    /**
     * The critical scenario: a mutation GROUP (shared batch_operation_id)
     * spans two locations. Reverting the member that belongs to the actor's
     * OWN location must still be rejected, because another member of the
     * SAME group belongs to a unit they don't control — proving revert
     * authorization checks the WHOLE group, not just the clicked mutation's
     * own asset_id.
     */
    public function test_mixed_location_mutation_group_is_entirely_rejected_on_revert(): void
    {
        $this->seedUnitLocations();
        $mine = $this->assetIn('02', ['notes' => 'original-mine']);
        $foreign = $this->assetIn('03', ['notes' => 'original-foreign']);
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));
        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$mine->id, $foreign->id],
            'changes' => ['notes' => 'batch note'],
        ])->assertOk();

        $myMutation = MutationLog::where('asset_id', $mine->id)->latest('id')->first();
        $mutationsBefore = MutationLog::count();

        Sanctum::actingAs($this->unitAdmin('02'));
        $this->postJson("/api/mutations/{$myMutation->id}/revert")->assertStatus(403);

        $this->assertSame('batch note', $mine->fresh()->notes);
        $this->assertSame('batch note', $foreign->fresh()->notes);
        $this->assertSame($mutationsBefore, MutationLog::count());
    }

    /* ================================================================== I. ability boundaries */

    public function test_unit_admin_cannot_import(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/imports')->assertStatus(403);
    }

    public function test_unit_admin_cannot_export(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/assets/export')->assertStatus(403);
    }

    public function test_unit_admin_cannot_manage_room_aliases(): void
    {
        $this->seedUnitLocations();
        $room = $this->roomIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/room-aliases', [
            'location_code' => '02',
            'room_id' => $room->id,
            'alias' => 'Some Alias',
        ])->assertStatus(403);
    }

    public function test_unit_admin_cannot_manage_master_data(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson('/api/locations', ['code' => '09', 'name' => 'New Location'])->assertStatus(403);
        $this->postJson('/api/categories', ['code' => '99', 'name' => 'New Category'])->assertStatus(403);
        $this->postJson('/api/rooms', ['location_code' => '02', 'name' => 'New Room'])->assertStatus(403);
    }

    public function test_unit_admin_cannot_manage_users(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->getJson('/api/users')->assertStatus(403);
    }

    /* ================================================================== J. global regression */

    public function test_legacy_admin_retains_global_write_behaviour(): void
    {
        $this->seedUnitLocations();
        $a = $this->assetIn('02');
        $b = $this->assetIn('03');
        Sanctum::actingAs($this->globalUser(UserRole::Admin));

        $this->putJson("/api/assets/{$a->id}", $this->updatePayload($a, ['brand_model' => 'X']))->assertOk();
        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a->id, $b->id],
            'changes' => ['notes' => 'global note'],
        ])->assertOk()->assertJsonPath('updated', 2);
    }

    public function test_operator_retains_existing_global_operational_behaviour(): void
    {
        $this->seedUnitLocations();
        $a = $this->assetIn('02');
        $b = $this->assetIn('03');
        Sanctum::actingAs($this->globalUser(UserRole::Operator));

        $this->putJson("/api/assets/{$a->id}", $this->updatePayload($a, ['brand_model' => 'X']))->assertOk();
        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$a->id, $b->id],
            'changes' => ['notes' => 'global note'],
        ])->assertOk()->assertJsonPath('updated', 2);
        $this->getJson('/api/imports')->assertOk();
    }

    public function test_viewer_remains_read_only(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::Viewer));

        $this->postJson('/api/assets', $this->createPayload('02'))->assertStatus(403);
        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset))->assertStatus(403);
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(403);
    }

    /** Flagged explicitly in the R5 report: super_admin newly gains real inventory write access as an intended consequence of activating the named-ability route gates (PermissionRegistry has always declared it '*'). */
    public function test_super_admin_gains_real_inventory_write_access(): void
    {
        $this->seedUnitLocations();
        $asset = $this->assetIn('02');
        Sanctum::actingAs($this->globalUser(UserRole::SuperAdmin));

        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset, ['brand_model' => 'X']))->assertOk();
    }

    /* ================================================================== K. security */

    public function test_request_location_code_cannot_override_actor_scope_on_create(): void
    {
        $this->seedUnitLocations();
        Sanctum::actingAs($this->unitAdmin('02'));

        // Explicitly request location 03 — must reject, never silently
        // rewritten to the actor's own location 02.
        $this->postJson('/api/assets', $this->createPayload('03'))->assertStatus(403);
        $this->assertSame(0, Asset::where('location_code', '03')->count());
    }

    public function test_direct_asset_id_cannot_bypass_scope(): void
    {
        $this->seedUnitLocations();
        $foreign = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->putJson("/api/assets/{$foreign->id}", $this->updatePayload($foreign))->assertStatus(403);
    }

    public function test_batch_ids_cannot_bypass_scope(): void
    {
        $this->seedUnitLocations();
        $mine = $this->assetIn('02');
        $foreign = $this->assetIn('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$mine->id, $foreign->id],
            'changes' => ['notes' => 'x'],
        ])->assertStatus(403);
    }

    /** Duplicate ids are already rejected 422 by existing `distinct` validation — confirms this isn't a bypass route, just pre-existing structural validation. */
    public function test_duplicate_ids_cannot_bypass_scope(): void
    {
        $this->seedUnitLocations();
        $mine = $this->assetIn('02');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->patchJson('/api/assets/batch', [
            'asset_ids' => [$mine->id, $mine->id],
            'changes' => ['notes' => 'x'],
        ])->assertStatus(422);
    }

    public function test_invalid_unit_admin_state_never_becomes_global_on_write(): void
    {
        $this->seedUnitLocations();
        $this->ensureLocation('01');
        // Bypasses app-level validation on purpose — simulates a corrupt/legacy row.
        $corrupt = User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => '01']);
        Sanctum::actingAs($corrupt);

        $this->postJson('/api/assets', $this->createPayload('02'))->assertStatus(403);
        $this->assertSame(0, Asset::where('location_code', '02')->count());
    }

    /**
     * Deactivating the actor's own location rejects this write TWICE over —
     * `UpdateAssetRequest`'s own `location_code` rule (`exists ... where
     * is_active`) already 422s the request before authorization is even
     * reached (the backfilled `location_code` value is no longer a valid
     * active location at all), and `LocationScope::for()` would separately
     * 403 it if that structural check were ever removed. Either way, access
     * never becomes global — confirmed here via the actual current (422) behavior.
     */
    public function test_inactive_unit_admin_location_never_becomes_global_on_write(): void
    {
        $this->ensureLocation('02');
        $asset = $this->assetIn('02');
        $unitAdmin = $this->unitAdmin('02');
        Location::query()->where('code', '02')->update(['is_active' => false]);
        Sanctum::actingAs($unitAdmin);

        $this->putJson("/api/assets/{$asset->id}", $this->updatePayload($asset))->assertStatus(422);
        $this->assertNotSame('Updated Brand', $asset->fresh()->brand_model);
    }
}
