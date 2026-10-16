<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Models\Category;
use App\Models\Location;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTestImages;
use Tests\TestCase;

/**
 * Foto Aset — who may list / view / upload / delete photos.
 *
 *  - READ (list + image) = AssetPolicy::viewDetail, the asset detail page's own
 *    rule (moved there from AssetController::show(), unchanged): denied → 404.
 *  - WRITE (upload + delete) = `assets.edit` + AssetPolicy::update: no ability
 *    → 403, unit_admin outside its location → 403 (decision K6-A); a trashed
 *    asset → 404 (K5).
 *  - `{photo}` is scope-bound to `{asset}` (IDOR → 404).
 */
class AssetPhotoAuthorizationTest extends TestCase
{
    use BuildsTestImages;
    use RefreshDatabase;

    private Subcategory $meja;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['01', '02', '03', '04'] as $code) {
            Location::query()->firstOrCreate(['code' => $code], ['name' => "Lokasi {$code}", 'is_active' => true]);
        }
        Category::query()->firstOrCreate(['code' => '02'], ['name' => 'Meubelair', 'is_active' => true]);
        $this->meja = Subcategory::query()->firstOrCreate(['category_code' => '02', 'code' => '001'], ['name' => 'Meja', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTestImages();
        parent::tearDown();
    }

    /* ================================================================== fixtures */

    private function user(UserRole $role, ?string $location = null, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'location_code' => $location, 'is_active' => $active]);
    }

    private function asset(string $location = '02'): Asset
    {
        return Asset::factory()->forSubcategory($this->meja)->create(['location_code' => $location]);
    }

    /** A stored photo created directly (no HTTP), file included. */
    private function photo(Asset $asset, int $slot = 1): AssetPhoto
    {
        $path = "asset-photos/{$asset->id}/".Str::uuid().'.jpg';
        $bytes = $this->jpegBytes(120, 90);
        Storage::disk('local')->put($path, $bytes);

        return AssetPhoto::query()->create([
            'asset_id' => $asset->id, 'slot' => $slot, 'path' => $path, 'mime' => 'image/jpeg',
            'size_bytes' => strlen($bytes), 'width' => 120, 'height' => 90,
        ]);
    }

    private function trashed(Asset $asset): Asset
    {
        $asset->delete();

        return $asset;
    }

    private function upload(Asset $asset): TestResponse
    {
        return $this->post("/api/assets/{$asset->id}/photos", ['photo' => $this->uploadedImage($this->jpegBytes(200, 150))], ['Accept' => 'application/json']);
    }

    private function image(Asset $asset, AssetPhoto $photo, array $headers = []): TestResponse
    {
        return $this->get("/api/assets/{$asset->id}/photos/{$photo->id}", ['Accept' => 'application/json'] + $headers);
    }

    /* ================================================================== authentication */

    public function test_guest_gets_401_everywhere_even_with_a_same_origin_referer(): void
    {
        $asset = $this->asset();
        $photo = $this->photo($asset);
        // Referer/Origin only make Sanctum READ the session cookie; without a session there is no user.
        $headers = ['Accept' => 'application/json', 'Referer' => 'http://localhost/inventory/'.$asset->id, 'Origin' => 'http://localhost'];

        $this->get("/api/assets/{$asset->id}/photos", $headers)->assertUnauthorized();
        $this->get("/api/assets/{$asset->id}/photos/{$photo->id}", $headers)->assertUnauthorized();
        $this->post("/api/assets/{$asset->id}/photos", ['photo' => $this->uploadedImage($this->jpegBytes())], $headers)->assertUnauthorized();
        $this->delete("/api/assets/{$asset->id}/photos/{$photo->id}", [], $headers)->assertUnauthorized();

        $this->assertSame(1, AssetPhoto::query()->count());
    }

    public function test_inactive_user_is_rejected_everywhere(): void
    {
        $asset = $this->asset();
        $photo = $this->photo($asset);
        Sanctum::actingAs($this->user(UserRole::Admin, active: false));

        $this->getJson("/api/assets/{$asset->id}/photos")->assertForbidden();
        $this->image($asset, $photo)->assertForbidden();
        $this->upload($asset)->assertForbidden();
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertForbidden();
        $this->assertSame(1, AssetPhoto::query()->count());
    }

    /* ================================================================== roles */

    public function test_viewer_can_see_photos_but_not_upload_or_delete(): void
    {
        $asset = $this->asset();
        $photo = $this->photo($asset);
        Sanctum::actingAs($this->user(UserRole::Viewer));

        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()->assertJsonCount(1, 'data');
        $this->image($asset, $photo)->assertOk();
        $this->upload($asset)->assertForbidden();
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertForbidden();
        $this->assertSame(1, AssetPhoto::query()->count());
    }

    #[DataProvider('globalWriters')]
    public function test_global_roles_with_assets_edit_can_upload_and_delete_anywhere(UserRole $role): void
    {
        $asset = $this->asset('03');
        Sanctum::actingAs($this->user($role));

        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();
        $this->image($asset, $photo)->assertOk();
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNoContent();
    }

    public static function globalWriters(): array
    {
        return ['operator' => [UserRole::Operator], 'admin' => [UserRole::Admin], 'super_admin' => [UserRole::SuperAdmin]];
    }

    public function test_unit_admin_can_manage_photos_of_its_own_location(): void
    {
        $asset = $this->asset('02');
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->upload($asset)->assertCreated();
        $photo = AssetPhoto::query()->sole();
        $this->getJson("/api/assets/{$asset->id}/photos")->assertOk()->assertJsonCount(1, 'data');
        $this->image($asset, $photo)->assertOk();
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNoContent();
    }

    public function test_unit_admin_gets_403_for_writes_and_404_for_reads_outside_its_location(): void
    {
        $foreign = $this->asset('03');
        $photo = $this->photo($foreign);
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->upload($foreign)->assertForbidden();
        $this->deleteJson("/api/assets/{$foreign->id}/photos/{$photo->id}")->assertForbidden();
        $this->getJson("/api/assets/{$foreign->id}/photos")->assertNotFound();
        $this->image($foreign, $photo)->assertNotFound();
        $this->image($foreign, $photo, ['If-None-Match' => $photo->etag()])->assertNotFound();

        $this->assertSame(1, AssetPhoto::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('asset-photos'));
    }

    /* ================================================================== trashed assets */

    public function test_trashed_asset_photos_follow_the_detail_policy(): void
    {
        $own = $this->trashed($this->asset('02'));
        $ownPhoto = $this->photo($own);
        $foreign = $this->trashed($this->asset('03'));
        $foreignPhoto = $this->photo($foreign);

        Sanctum::actingAs($this->user(UserRole::Viewer));   // no assets.restore
        $this->getJson("/api/assets/{$own->id}/photos")->assertNotFound();
        $this->image($own, $ownPhoto)->assertNotFound();
        $this->image($own, $ownPhoto, ['If-None-Match' => $ownPhoto->etag()])->assertNotFound();

        Sanctum::actingAs($this->user(UserRole::Operator)); // global, holds assets.restore
        $this->getJson("/api/assets/{$foreign->id}/photos")->assertOk()->assertJsonCount(1, 'data');
        $this->image($foreign, $foreignPhoto)->assertOk();

        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $this->getJson("/api/assets/{$own->id}/photos")->assertOk();
        $this->image($own, $ownPhoto)->assertOk();
        $this->getJson("/api/assets/{$foreign->id}/photos")->assertNotFound();
        $this->image($foreign, $foreignPhoto)->assertNotFound();
    }

    public function test_uploading_to_or_deleting_from_a_trashed_asset_is_404_for_everyone(): void
    {
        $asset = $this->trashed($this->asset('02'));
        $photo = $this->photo($asset);

        foreach ([$this->user(UserRole::Admin), $this->user(UserRole::Operator), $this->user(UserRole::UnitAdmin, '02')] as $user) {
            Sanctum::actingAs($user);
            $this->upload($asset)->assertNotFound();
            $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNotFound();
        }

        $this->assertSame(1, AssetPhoto::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('asset-photos'));
    }

    /**
     * The photo endpoints and the detail endpoint must give the same answer for
     * every (role, asset state, location) combination.
     */
    #[DataProvider('detailMatrix')]
    public function test_photo_reads_match_the_asset_detail_endpoint(UserRole $role, ?string $userLocation, string $assetLocation, bool $trashed): void
    {
        $asset = $this->asset($assetLocation);
        $photo = $this->photo($asset);
        if ($trashed) {
            $this->trashed($asset);
        }
        Sanctum::actingAs($this->user($role, $userLocation));

        $detail = $this->getJson("/api/assets/{$asset->id}")->getStatusCode();

        $this->assertContains($detail, [200, 404]);
        $this->assertSame($detail, $this->getJson("/api/assets/{$asset->id}/photos")->getStatusCode());
        $this->assertSame($detail, $this->image($asset, $photo)->getStatusCode());
    }

    public static function detailMatrix(): array
    {
        $cases = [];
        foreach ([false, true] as $trashed) {
            $state = $trashed ? 'trashed' : 'active';
            $cases["viewer {$state}"] = [UserRole::Viewer, null, '02', $trashed];
            $cases["operator {$state}"] = [UserRole::Operator, null, '02', $trashed];
            $cases["admin {$state}"] = [UserRole::Admin, null, '03', $trashed];
            $cases["super_admin {$state}"] = [UserRole::SuperAdmin, null, '04', $trashed];
            $cases["unit_admin own {$state}"] = [UserRole::UnitAdmin, '02', '02', $trashed];
            $cases["unit_admin foreign {$state}"] = [UserRole::UnitAdmin, '02', '03', $trashed];
        }

        return $cases;
    }

    /* ================================================================== IDOR / binding */

    public function test_a_photo_id_of_another_asset_is_404_and_never_touches_that_photo(): void
    {
        $a = $this->asset('02');
        $b = $this->asset('02');
        $photoOfB = $this->photo($b);
        Sanctum::actingAs($this->user(UserRole::Operator));

        $this->image($a, $photoOfB)->assertNotFound();
        $this->image($a, $photoOfB, ['If-None-Match' => $photoOfB->etag()])->assertNotFound();
        $this->deleteJson("/api/assets/{$a->id}/photos/{$photoOfB->id}")->assertNotFound();

        $this->assertTrue(AssetPhoto::query()->whereKey($photoOfB->id)->exists());
        $this->image($b, $photoOfB)->assertOk();
    }

    public function test_unit_admin_cannot_reach_a_foreign_photo_through_its_own_asset(): void
    {
        $own = $this->asset('02');
        $foreign = $this->asset('03');
        $foreignPhoto = $this->photo($foreign);
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));

        $this->image($own, $foreignPhoto)->assertNotFound();
        $this->deleteJson("/api/assets/{$own->id}/photos/{$foreignPhoto->id}")->assertNotFound();
        $this->assertTrue(AssetPhoto::query()->whereKey($foreignPhoto->id)->exists());
    }

    public function test_path_like_or_non_numeric_ids_never_reach_a_file(): void
    {
        $asset = $this->asset();
        $photo = $this->photo($asset);
        Sanctum::actingAs($this->user(UserRole::Operator));

        foreach ([
            "/api/assets/{$asset->id}/photos/..%2F..%2F.env",
            "/api/assets/{$asset->id}/photos/".rawurlencode($photo->path),
            "/api/assets/{$asset->id}/photos/abc",
            "/api/assets/{$asset->id}/photos/-1",
            "/api/assets/{$asset->id}/photos/99999999",
            "/api/assets/x/photos/{$photo->id}",
        ] as $url) {
            $response = $this->get($url, ['Accept' => 'application/json']);
            $this->assertContains($response->getStatusCode(), [404, 405], $url);
            $this->assertNotSame('image/jpeg', $response->headers->get('Content-Type'), $url);
        }
        $this->deleteJson("/api/assets/{$asset->id}/photos/abc")->assertNotFound();
        $this->assertSame(1, AssetPhoto::query()->count());
    }

    /* ================================================================== state change between check and lock (R1) */

    /**
     * Runs `$change` exactly once, immediately BEFORE the service's
     * `SELECT … FROM assets … FOR UPDATE` is sent — i.e. after the controller's
     * own policy check already passed on the old state. This is the window a
     * concurrent relocation/soft-delete by another user would hit.
     *
     * @param  callable(): void  $change
     */
    private function changeRightBeforeTheAssetLock(callable $change): object
    {
        $probe = new class
        {
            public bool $fired = false;
        };

        DB::connection()->beforeExecuting(function (string $query) use ($probe, $change): void {
            if ($probe->fired || ! str_contains($query, 'from `assets`') || ! str_contains($query, 'for update')) {
                return;
            }
            $probe->fired = true;
            $change();
        });

        return $probe;
    }

    public function test_upload_is_refused_when_the_asset_leaves_the_unit_admins_location_before_the_lock(): void
    {
        $asset = $this->asset('02');
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $probe = $this->changeRightBeforeTheAssetLock(
            fn () => DB::table('assets')->where('id', $asset->id)->update(['location_code' => '03']),
        );

        $this->upload($asset)->assertForbidden();

        // The relocation ran after the controller check, before the lock. (In this
        // single-connection test it executes inside the service's transaction and
        // is rolled back with it; in production it would be another user's commit.)
        $this->assertTrue($probe->fired);
        $this->assertSame(0, AssetPhoto::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('asset-photos'), 'the already-written file is removed again');
        $this->assertSame(0, DB::table('mutation_logs')->where('event_type', 'PHOTO_ADDED')->count());
    }

    public function test_delete_is_refused_when_the_asset_leaves_the_unit_admins_location_before_the_lock(): void
    {
        $asset = $this->asset('02');
        $photo = $this->photo($asset);
        Sanctum::actingAs($this->user(UserRole::UnitAdmin, '02'));
        $probe = $this->changeRightBeforeTheAssetLock(
            fn () => DB::table('assets')->where('id', $asset->id)->update(['location_code' => '03']),
        );

        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertForbidden();

        $this->assertTrue($probe->fired);
        $this->assertTrue(AssetPhoto::query()->whereKey($photo->id)->exists());
        Storage::disk('local')->assertExists($photo->path);
        $this->assertSame(0, DB::table('mutation_logs')->where('event_type', 'PHOTO_DELETED')->count());
    }

    public function test_the_same_relocation_does_not_affect_a_global_actor(): void
    {
        // control: proves the 403 above comes from the location re-check, not from the hook
        $asset = $this->asset('02');
        $photo = $this->photo($asset);
        Sanctum::actingAs($this->user(UserRole::Operator));
        $probe = $this->changeRightBeforeTheAssetLock(
            fn () => DB::table('assets')->where('id', $asset->id)->update(['location_code' => '03']),
        );

        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNoContent();
        $this->assertTrue($probe->fired);
    }

    public function test_a_soft_delete_between_check_and_lock_is_a_404_for_upload_and_delete(): void
    {
        $asset = $this->asset('02');
        $photo = $this->photo($asset);
        Sanctum::actingAs($this->user(UserRole::Operator));

        $trash = fn () => DB::table('assets')->where('id', $asset->id)->update(['deleted_at' => now()]);
        $probe = $this->changeRightBeforeTheAssetLock($trash);
        $this->upload($asset)->assertNotFound();
        $this->assertTrue($probe->fired);
        $this->assertSame([$photo->path], Storage::disk('local')->allFiles('asset-photos'));

        DB::table('assets')->where('id', $asset->id)->update(['deleted_at' => null]);
        $probe = $this->changeRightBeforeTheAssetLock($trash);
        $this->deleteJson("/api/assets/{$asset->id}/photos/{$photo->id}")->assertNotFound();
        $this->assertTrue($probe->fired);
        $this->assertTrue(AssetPhoto::query()->whereKey($photo->id)->exists());
    }

    /* ================================================================== conditional requests */

    public function test_a_304_is_only_ever_sent_after_authorization(): void
    {
        $asset = $this->asset('02');
        $photo = $this->photo($asset);
        $unitAdmin = $this->user(UserRole::UnitAdmin, '02');
        Sanctum::actingAs($unitAdmin);

        $etag = (string) $this->image($asset, $photo)->assertOk()->headers->get('ETag');
        $this->image($asset, $photo, ['If-None-Match' => $etag])->assertStatus(304);

        // the asset moves to another unit: the same conditional request now gets 404, not 304
        $asset->forceFill(['location_code' => '03'])->save();
        $this->image($asset, $photo, ['If-None-Match' => $etag])->assertNotFound();

        // trashed: an actor without assets.restore loses access the same way
        $asset->forceFill(['location_code' => '02'])->save();
        $viewer = $this->user(UserRole::Viewer);
        Sanctum::actingAs($viewer);
        $this->image($asset, $photo, ['If-None-Match' => $etag])->assertStatus(304);
        $asset->delete();
        $this->image($asset, $photo, ['If-None-Match' => $etag])->assertNotFound();
    }
}
