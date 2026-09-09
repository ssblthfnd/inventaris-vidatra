<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.4 §16–§18, §21, §31 — `POST /api/assets`.
 */
class AssetCreateTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->operator());
    }

    public function test_create_succeeds_and_returns_201_with_the_resource(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.location.code', 'ZL')
            ->assertJsonPath('data.category.code', 'ZC')
            ->assertJsonPath('data.subcategory.code', '001')
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.is_written_off', false);

        $this->assertDatabaseCount('assets', 1);
    }

    public function test_first_sequence_is_001_and_asset_code_is_generated(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 2025]))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '001')
            ->assertJsonPath('data.asset_code', 'ZL.ZC.001.001.2025');
    }

    public function test_sequence_continues_from_existing_data(): void
    {
        $this->existingAsset('007');

        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '008');
    }

    public function test_year_does_not_reset_the_sequence(): void
    {
        $this->existingAsset('001', year: 2024);
        $this->existingAsset('002', year: 2024);

        $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 2026]))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '003')
            ->assertJsonPath('data.asset_code', 'ZL.ZC.001.003.2026');
    }

    public function test_different_scopes_get_independent_sequences(): void
    {
        $this->existingAsset('040', loc: 'ZL', cat: 'ZC', sub: '001');
        $this->scope('ZM', 'ZC', '001');
        $this->scope('ZL', 'ZD', '001');
        $this->scope('ZL', 'ZC', '009');

        $this->postJson('/api/assets', $this->validCreatePayload(['location_code' => 'ZM']))
            ->assertJsonPath('data.sequence_no', '001');
        $this->postJson('/api/assets', $this->validCreatePayload(['category_code' => 'ZD']))
            ->assertJsonPath('data.sequence_no', '001');
        $this->postJson('/api/assets', $this->validCreatePayload(['subcategory_code' => '009']))
            ->assertJsonPath('data.sequence_no', '001');

        // original scope untouched
        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertJsonPath('data.sequence_no', '041');
    }

    public function test_existing_letter_suffix_family_is_counted(): void
    {
        $this->existingAsset('005A');
        $this->existingAsset('005B');

        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '006');
    }

    public function test_sequence_above_999_becomes_1000(): void
    {
        $this->existingAsset('999');

        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '1000');
    }

    public function test_soft_deleted_sequence_stays_consumed(): void
    {
        $this->existingAsset('001');
        $this->existingAsset('002');
        $this->existingAsset('003')->delete();

        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '004');
    }

    public function test_client_supplied_sequence_no_is_rejected(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['sequence_no' => '777']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sequence_no');

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_client_supplied_asset_code_cannot_override_generated_column(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['asset_code' => 'HACKED.CODE.999.999.2000']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('asset_code');
    }

    public function test_quantity_other_than_one_is_rejected(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['quantity' => 3]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    public function test_quantity_one_is_accepted(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['quantity' => 1]))
            ->assertStatus(201);
    }

    public function test_inactive_location_is_rejected(): void
    {
        Location::query()->firstOrCreate(['code' => 'ZX'], ['name' => 'Nonaktif', 'is_active' => false]);
        $this->scope('ZL', 'ZC', '001');

        $this->postJson('/api/assets', $this->validCreatePayload(['location_code' => 'ZX']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_code');
    }

    public function test_inactive_category_is_rejected(): void
    {
        $this->scope('ZL', 'ZC', '001');
        Category::query()->firstOrCreate(['code' => 'ZY'], ['name' => 'Kat Nonaktif', 'is_active' => false]);

        $this->postJson('/api/assets', $this->validCreatePayload(['category_code' => 'ZY']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_code');
    }

    public function test_invalid_category_subcategory_combination_is_rejected(): void
    {
        // subcategory 001 exists under ZC, but not under ZD
        $this->scope('ZL', 'ZC', '001');
        $this->scope('ZL', 'ZD', '777');

        $this->postJson('/api/assets', $this->validCreatePayload([
            'category_code' => 'ZD',
            'subcategory_code' => '001',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('subcategory_code');
    }

    public function test_inactive_subcategory_is_rejected(): void
    {
        [, , $sub] = $this->scope('ZL', 'ZC', '050');
        $sub->update(['is_active' => false]);

        $this->postJson('/api/assets', $this->validCreatePayload(['subcategory_code' => '050']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('subcategory_code');
    }

    public function test_room_from_another_location_is_rejected(): void
    {
        $this->scope('ZL', 'ZC', '001');
        $otherRoom = $this->room('ZM');

        $this->postJson('/api/assets', $this->validCreatePayload(['room_id' => $otherRoom->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_id');
    }

    public function test_inactive_room_is_rejected(): void
    {
        $room = $this->room('ZL', ['is_active' => false]);

        $this->postJson('/api/assets', $this->validCreatePayload(['room_id' => $room->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_id');
    }

    public function test_valid_room_from_same_location_is_accepted(): void
    {
        $room = $this->room('ZL');

        $this->postJson('/api/assets', $this->validCreatePayload(['room_id' => $room->id]))
            ->assertStatus(201)
            ->assertJsonPath('data.room.id', $room->id);
    }

    public function test_invalid_condition_is_rejected(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['condition' => 'sangat_rusak']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('condition');
    }

    public function test_null_condition_is_allowed(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['condition' => null]))
            ->assertStatus(201)
            ->assertJsonPath('data.condition', null);
    }

    public function test_absurd_asset_year_is_rejected(): void
    {
        $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 1850]))
            ->assertStatus(422)->assertJsonValidationErrors('asset_year');
        $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors('asset_year');
    }

    public function test_create_directly_as_written_off_requires_a_valid_date(): void
    {
        // §18 — is_written_off=true without written_off_on
        $this->postJson('/api/assets', $this->validCreatePayload(['is_written_off' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('written_off_on');

        $this->postJson('/api/assets', $this->validCreatePayload([
            'is_written_off' => true,
            'written_off_on' => '2024-05-06',
            'written_off_note' => 'sudah dilelang',
            'condition' => 'baik',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.is_written_off', true)
            ->assertJsonPath('data.written_off_on', '2024-05-06')
            // §18 — condition not auto-changed by write-off
            ->assertJsonPath('data.condition', 'baik');
    }

    public function test_created_asset_records_the_actor_but_never_exposes_it(): void
    {
        $response = $this->postJson('/api/assets', $this->validCreatePayload())->assertStatus(201);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('created_by', $data);
        $this->assertArrayNotHasKey('updated_by', $data);

        $asset = Asset::query()->firstOrFail();
        $this->assertNotNull($asset->created_by);
        $this->assertSame($asset->created_by, $asset->updated_by);
    }
}
