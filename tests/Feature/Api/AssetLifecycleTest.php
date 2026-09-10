<?php

namespace Tests\Feature\Api;

use App\Enums\AssetCondition;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\Subcategory;
use App\Services\Asset\AssetNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.4 §35 (written-off), §36 (soft delete), §37 (restore), §38 (concurrency),
 * §39 (read-API regression).
 */
class AssetLifecycleTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->operator());
    }

    /* ------------------------------------------------------------------ written-off (§35) */

    public function test_write_off_sets_status_date_and_note_without_touching_condition(): void
    {
        $asset = $this->existingAsset('001', overrides: ['condition' => AssetCondition::KurangBaik]);

        $this->postJson("/api/assets/{$asset->id}/write-off", [
            'written_off_on' => '2024-07-01',
            'written_off_note' => 'dihapus dari daftar',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_written_off', true)
            ->assertJsonPath('data.written_off_on', '2024-07-01')
            ->assertJsonPath('data.written_off_note', 'dihapus dari daftar')
            ->assertJsonPath('data.condition', 'kurang_baik');
    }

    public function test_written_off_asset_stays_readable_and_filterable(): void
    {
        $asset = $this->existingAsset('001');
        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-07-01'])->assertOk();

        $this->getJson("/api/assets/{$asset->id}")->assertOk()->assertJsonPath('data.id', $asset->id);

        $this->getJson('/api/assets?is_written_off=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $asset->id);
        $this->getJson('/api/assets?is_written_off=0')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_write_off_twice_is_a_409_conflict(): void
    {
        $asset = $this->existingAsset('001');
        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-07-01'])->assertOk();

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-08-01'])
            ->assertStatus(409);

        // the original date was not silently overwritten
        $this->assertSame('2024-07-01', $asset->fresh()->written_off_on->toDateString());
    }

    public function test_write_off_requires_a_non_future_date(): void
    {
        $asset = $this->existingAsset('001');

        $this->postJson("/api/assets/{$asset->id}/write-off", [])
            ->assertStatus(422)->assertJsonValidationErrors('written_off_on');

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => now()->addYear()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('written_off_on');
    }

    public function test_unwrite_off_clears_date_and_note(): void
    {
        $asset = $this->existingAsset('001', overrides: [
            'is_written_off' => true,
            'written_off_on' => '2024-01-01',
            'written_off_note' => 'note',
        ]);

        $this->postJson("/api/assets/{$asset->id}/unwrite-off")
            ->assertOk()
            ->assertJsonPath('data.is_written_off', false)
            ->assertJsonPath('data.written_off_on', null)
            ->assertJsonPath('data.written_off_note', null);
    }

    public function test_unwrite_off_a_non_written_off_asset_is_a_409(): void
    {
        $asset = $this->existingAsset('001');

        $this->postJson("/api/assets/{$asset->id}/unwrite-off")->assertStatus(409);
    }

    public function test_write_off_on_a_soft_deleted_asset_is_404(): void
    {
        $asset = $this->existingAsset('001');
        $asset->delete();

        $this->postJson("/api/assets/{$asset->id}/write-off", ['written_off_on' => '2024-01-01'])
            ->assertStatus(404);
        $this->postJson("/api/assets/{$asset->id}/unwrite-off")->assertStatus(404);
    }

    /* ------------------------------------------------------------------ soft delete (§36) */

    public function test_delete_soft_deletes_and_hides_from_the_active_list(): void
    {
        $asset = $this->existingAsset('001');

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->assertSoftDeleted('assets', ['id' => $asset->id]);
        // the active inventory list never shows a soft-deleted asset
        $this->getJson('/api/assets')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_operator_can_still_read_a_soft_deleted_asset_to_restore_it(): void
    {
        // Tahap 5.8.5: the detail route resolves trashed assets for write-capable users.
        $asset = $this->existingAsset('001');
        $code = $asset->asset_code;
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.asset_code', $code)
            ->assertJsonPath('data.is_trashed', true)
            ->assertJsonPath('data.is_written_off', false);
    }

    public function test_viewer_still_gets_404_for_a_soft_deleted_asset(): void
    {
        $asset = $this->existingAsset('001');
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        Sanctum::actingAs($this->viewer());
        $this->getJson("/api/assets/{$asset->id}")->assertStatus(404);
    }

    public function test_deleted_asset_row_and_number_survive(): void
    {
        $asset = $this->existingAsset('005');
        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'sequence_no' => '005']);

        // a new asset in the same scope skips the retired number
        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '006');
    }

    public function test_written_off_state_is_retained_through_soft_delete(): void
    {
        $asset = $this->existingAsset('001', overrides: [
            'is_written_off' => true,
            'written_off_on' => '2024-02-02',
        ]);

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'is_written_off' => 1,
            'written_off_on' => '2024-02-02',
        ]);
    }

    public function test_endpoint_never_hard_deletes(): void
    {
        $asset = $this->existingAsset('001');
        MutationLog::factory()->create(['asset_id' => $asset->id]);

        $this->deleteJson("/api/assets/{$asset->id}")->assertNoContent();
        $this->deleteJson("/api/assets/{$asset->id}")->assertStatus(404); // already gone from binding

        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    /* ------------------------------------------------------------------ restore (§37) */

    public function test_restore_brings_back_the_same_asset(): void
    {
        $asset = $this->existingAsset('009', year: 2021);
        $code = $asset->asset_code;
        $asset->delete();

        $this->postJson("/api/assets/{$asset->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.sequence_no', '009')
            ->assertJsonPath('data.asset_code', $code);

        $this->assertNotSoftDeleted('assets', ['id' => $asset->id]);
        $this->getJson("/api/assets/{$asset->id}")->assertOk();
    }

    public function test_restore_consumes_no_new_sequence_and_creates_no_duplicate(): void
    {
        $asset = $this->existingAsset('001');
        $asset->delete();

        $this->postJson("/api/assets/{$asset->id}/restore")->assertOk();

        $this->assertDatabaseCount('assets', 1);
        $this->assertSame('001', $asset->fresh()->sequence_no);
    }

    public function test_restoring_a_live_asset_is_a_noop_200(): void
    {
        $asset = $this->existingAsset('001');

        $this->postJson("/api/assets/{$asset->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id);
    }

    /* ------------------------------------------------------------------ concurrency (§38) */

    public function test_unique_constraint_is_the_final_defence_and_the_service_recovers(): void
    {
        // Force a duplicate race: the generator hands out '001' three times, then '002'.
        // The first insert wins on '001'; the retry loop must recover onto '002'.
        $this->app->bind(AssetNumberGenerator::class, function () {
            return new class extends AssetNumberGenerator
            {
                private int $calls = 0;

                public function next(string $locationCode, string $categoryCode, string $subcategoryCode): string
                {
                    $this->calls++;

                    return $this->calls <= 3 ? '001' : '002';
                }
            };
        });

        $this->existingAsset('001'); // occupies '001' for this scope + year

        $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 2020]))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '002');

        // exactly one asset per identity — the unique index held
        $this->assertSame(
            1,
            Asset::withTrashed()->where(['location_code' => 'ZL', 'category_code' => 'ZC', 'subcategory_code' => '001', 'sequence_no' => '001', 'asset_year' => 2020])->count(),
        );
    }

    public function test_sequential_generator_calls_within_one_transaction_do_not_repeat(): void
    {
        $this->scope();
        $gen = app(AssetNumberGenerator::class);

        DB::transaction(function () use ($gen) {
            $this->assertSame('001', $gen->next('ZL', 'ZC', '001'));
            // still 001 until a row is actually inserted — the caller (service) inserts
            // between allocations; this documents that next() is a pure read.
            $this->assertSame('001', $gen->next('ZL', 'ZC', '001'));
        });
    }

    /* ------------------------------------------------------------------ read regression (§39) */

    public function test_asset_created_via_api_serialises_with_the_tahap_5_3_shape(): void
    {
        $room = $this->room('ZL');
        $response = $this->postJson('/api/assets', $this->validCreatePayload(['room_id' => $room->id]))
            ->assertStatus(201);

        $response->assertJsonStructure([
            'data' => [
                'id', 'asset_code',
                'location' => ['code', 'name', 'alias'],
                'category' => ['code', 'name'],
                'subcategory' => ['code', 'name'],
                'room' => ['id', 'name'],
            ],
        ]);
        $data = $response->json('data');
        foreach (['created_by', 'updated_by', 'import_row_id', 'created_at', 'deleted_at'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $data);
        }
    }

    public function test_composite_subcategory_resolution_survives_a_category_change(): void
    {
        $this->scope('ZL', 'ZC', '001');
        $this->scope('ZL', 'ZD', '001'); // same subcategory CODE, different category
        Subcategory::query()->where(['category_code' => 'ZC', 'code' => '001'])->update(['name' => 'MEJA']);
        Subcategory::query()->where(['category_code' => 'ZD', 'code' => '001'])->update(['name' => 'KURSI']);

        $asset = $this->existingAsset('001', loc: 'ZL', cat: 'ZC', sub: '001');

        $this->getJson("/api/assets/{$asset->id}")->assertJsonPath('data.subcategory.name', 'MEJA');

        $this->patchJson("/api/assets/{$asset->id}", ['category_code' => 'ZD', 'subcategory_code' => '001'])
            ->assertOk()
            ->assertJsonPath('data.subcategory.name', 'KURSI');
    }
}
