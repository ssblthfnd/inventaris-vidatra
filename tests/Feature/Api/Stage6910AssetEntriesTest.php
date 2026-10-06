<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\MutationLog;
use App\Models\User;
use App\Services\Asset\AssetNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.9 R10 — `POST /api/assets/entries`: 1..100 independent assets in one
 * atomic request, each with an optional manually entered number.
 */
class Stage6910AssetEntriesTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    private const URL = '/api/assets/entries';

    /* ================================================================== fixtures */

    /** One valid item for the default ZL/ZC/001 scope (no number = automatic). */
    private function item(array $overrides = []): array
    {
        $this->scope();

        return array_merge([
            'location_code' => 'ZL',
            'category_code' => 'ZC',
            'subcategory_code' => '001',
            'asset_year' => 2025,
            'condition' => 'baik',
        ], $overrides);
    }

    private function unitAdmin(string $locationCode): User
    {
        $this->scope($locationCode);

        return User::factory()->create(['role' => UserRole::UnitAdmin, 'location_code' => $locationCode, 'is_active' => true]);
    }

    /** A generator that counts how often it is asked for a number. */
    private function countingGenerator(): AssetNumberGenerator
    {
        $generator = new class extends AssetNumberGenerator
        {
            public int $calls = 0;

            public function next(string $locationCode, string $categoryCode, string $subcategoryCode): string
            {
                $this->calls++;

                return parent::next($locationCode, $categoryCode, $subcategoryCode);
            }
        };
        $this->app->instance(AssetNumberGenerator::class, $generator);

        return $generator;
    }

    /* ================================================================== numbering */

    public function test_one_asset_with_a_manual_number_is_created_verbatim_without_the_generator(): void
    {
        Sanctum::actingAs($this->operator());
        $generator = $this->countingGenerator();

        $this->postJson(self::URL, ['items' => [$this->item(['sequence_no' => '005A'])]])
            ->assertStatus(201)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.sequence_no', '005A')
            ->assertJsonPath('data.0.asset_code', 'ZL.ZC.001.005A.2025');

        $this->assertSame(0, $generator->calls);
    }

    public function test_manual_number_is_trimmed_only_and_an_empty_one_means_automatic(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '  007 ']),
            $this->item(['sequence_no' => '   ']),
        ]])
            ->assertStatus(201)
            ->assertJsonPath('data.0.sequence_no', '007')
            ->assertJsonPath('data.1.sequence_no', '008');
    }

    public function test_one_asset_without_a_number_continues_from_the_existing_maximum(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('007', 2019);
        $this->existingAsset('003B', 2020);

        $this->postJson(self::URL, ['items' => [$this->item()]])
            ->assertStatus(201)
            ->assertJsonPath('data.0.sequence_no', '008');
    }

    public function test_many_manual_numbers_are_all_kept(): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '010']),
            $this->item(['sequence_no' => '002']),
            $this->item(['sequence_no' => '1500']),
        ]])->assertStatus(201)->assertJsonPath('count', 3);

        $this->assertSame(['010', '002', '1500'], array_column($response->json('data'), 'sequence_no'));
    }

    public function test_many_automatic_numbers_are_consecutive(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('004');

        $response = $this->postJson(self::URL, ['items' => [$this->item(), $this->item(), $this->item()]])
            ->assertStatus(201);

        $this->assertSame(['005', '006', '007'], array_column($response->json('data'), 'sequence_no'));
    }

    public function test_mixed_rows_number_automatically_after_the_highest_manual_number_and_keep_request_order(): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->postJson(self::URL, ['items' => [
            $this->item(),                          // row 1: automatic
            $this->item(['sequence_no' => '005']),  // row 2: manual
            $this->item(),                          // row 3: automatic
            $this->item(['sequence_no' => '010']),  // row 4: manual
        ]])->assertStatus(201);

        // manual numbers were inserted first, so 011 / 012 — but data is in request order
        $this->assertSame(['011', '005', '012', '010'], array_column($response->json('data'), 'sequence_no'));
        $this->assertSame(
            ['ZL.ZC.001.011.2025', 'ZL.ZC.001.005.2025', 'ZL.ZC.001.012.2025', 'ZL.ZC.001.010.2025'],
            array_column($response->json('data'), 'asset_code'),
        );
    }

    public function test_rows_in_different_numbering_families_are_numbered_independently(): void
    {
        Sanctum::actingAs($this->operator());
        $this->scope('ZL', 'ZC', '002');
        $this->existingAsset('020', 2020, [], 'ZL', 'ZC', '002');

        $response = $this->postJson(self::URL, ['items' => [
            $this->item(),
            $this->item(['subcategory_code' => '002']),
            $this->item(['subcategory_code' => '002', 'sequence_no' => '030']),
        ]])->assertStatus(201);

        $this->assertSame(['001', '031', '030'], array_column($response->json('data'), 'sequence_no'));
    }

    public function test_every_row_keeps_its_own_fields(): void
    {
        Sanctum::actingAs($this->operator());
        $roomA = $this->room('ZL', ['name' => 'Ruang A']);

        $this->postJson(self::URL, ['items' => [
            $this->item(['room_id' => $roomA->id, 'brand_model' => 'Model A', 'condition' => 'baik']),
            $this->item(['room_id' => null, 'brand_model' => 'Model B', 'condition' => 'rusak_berat', 'asset_year' => 2019]),
        ]])
            ->assertStatus(201)
            ->assertJsonPath('data.0.room.id', $roomA->id)
            ->assertJsonPath('data.0.brand_model', 'Model A')
            ->assertJsonPath('data.1.room', null)
            ->assertJsonPath('data.1.condition', 'rusak_berat')
            ->assertJsonPath('data.1.asset_year', 2019);

        $this->assertSame(2, Asset::query()->where('created_by', '!=', null)->count());
    }

    /* ================================================================== duplicates */

    public function test_the_same_manual_number_twice_in_one_request_flags_both_rows_and_creates_nothing(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '005']),
            $this->item(),
            $this->item(['sequence_no' => '005']),
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.sequence_no', 'items.2.sequence_no'])
            ->assertJsonMissingValidationErrors(['items.1.sequence_no']);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_manual_numbers_differing_only_in_case_count_as_the_same_number(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '005A']),
            $this->item(['sequence_no' => '005a']),
        ]])->assertStatus(422)->assertJsonValidationErrors(['items.0.sequence_no', 'items.1.sequence_no']);

        $this->existingAsset('009B', 2025);
        $this->postJson(self::URL, ['items' => [$this->item(['sequence_no' => '009b'])]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.sequence_no']);

        $this->assertDatabaseCount('assets', 1);
    }

    public function test_numbers_only_the_column_collation_equates_are_a_row_error_not_a_retry(): void
    {
        Sanctum::actingAs($this->operator());
        $logsBefore = MutationLog::query()->count();

        // 'é' = 'e' under utf8mb4_unicode_ci: passes the request check, then hits
        // uq_assets_number inside the transaction — reported on the second row
        $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '005e']),
            $this->item(['sequence_no' => '005é']),
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['items.1.sequence_no' => ['Nomor inventaris yang sama juga diisi pada baris 1.']]);

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame($logsBefore, MutationLog::query()->count());
    }

    public function test_the_same_number_in_another_year_or_family_is_a_different_identity(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '005']),
            $this->item(['sequence_no' => '005', 'asset_year' => 2024]),
        ]])->assertStatus(201);
    }

    public function test_a_number_held_by_an_active_asset_is_rejected_on_its_row(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('005', 2025);

        $this->postJson(self::URL, ['items' => [
            $this->item(),
            $this->item(['sequence_no' => '005']),
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['items.1.sequence_no' => ['Nomor inventaris sudah digunakan.']]);

        $this->assertDatabaseCount('assets', 1);
    }

    public function test_a_number_held_by_a_soft_deleted_asset_is_rejected_too(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('005', 2025)->delete();

        $this->postJson(self::URL, ['items' => [$this->item(['sequence_no' => '005'])]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.sequence_no']);

        $this->assertSame(1, Asset::withTrashed()->count());
    }

    /* ================================================================== validation / atomicity */

    public function test_one_invalid_row_rejects_the_whole_request_with_row_specific_errors(): void
    {
        Sanctum::actingAs($this->operator());
        $logsBefore = MutationLog::query()->count();

        $this->postJson(self::URL, ['items' => [
            $this->item(),
            $this->item(['subcategory_code' => '999']),
            $this->item(),
            $this->item(['asset_year' => 1900, 'location_code' => '']),
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.subcategory_code', 'items.3.asset_year', 'items.3.location_code'])
            ->assertJsonMissingValidationErrors(['items.0.location_code', 'items.2.location_code']);

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame($logsBefore, MutationLog::query()->count());
    }

    public function test_a_conflict_found_under_the_lock_rolls_back_rows_already_inserted(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('050', 2025);
        $logsBefore = MutationLog::query()->count();

        // row 0 is fine, row 1 collides with the existing asset: nothing is kept
        $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '001']),
            $this->item(['sequence_no' => '050']),
            $this->item(),
        ]])->assertStatus(422)->assertJsonValidationErrors(['items.1.sequence_no']);

        $this->assertDatabaseCount('assets', 1);
        $this->assertSame($logsBefore, MutationLog::query()->count());
    }

    public function test_sequence_format_rules(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => [
            $this->item(['sequence_no' => '00.5']),
            $this->item(['sequence_no' => '12345678901']),
        ]])->assertStatus(422)->assertJsonValidationErrors(['items.0.sequence_no', 'items.1.sequence_no']);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_asset_code_is_never_accepted(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => [$this->item(['asset_code' => 'ZL.ZC.001.001.2025'])]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.asset_code']);
    }

    public function test_items_shape_and_size_limits(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->postJson(self::URL, ['items' => []])->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->postJson(self::URL, ['items' => ['a' => $this->item()]])->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->postJson(self::URL, ['items' => ['not-an-object']])->assertStatus(422)->assertJsonValidationErrors(['items.0']);
        $this->postJson(self::URL, ['items' => array_fill(0, 101, $this->item())])
            ->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_one_hundred_items_are_accepted(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => array_fill(0, 100, $this->item())])
            ->assertStatus(201)
            ->assertJsonPath('count', 100)
            ->assertJsonPath('data.99.sequence_no', '100');
    }

    public function test_room_must_be_active_and_in_the_assets_own_location(): void
    {
        Sanctum::actingAs($this->operator());
        $this->scope('ZM');
        $foreignRoom = $this->room('ZM');
        $inactiveRoom = $this->room('ZL', ['is_active' => false]);

        $this->postJson(self::URL, ['items' => [
            $this->item(['room_id' => $foreignRoom->id]),
            $this->item(['room_id' => $inactiveRoom->id]),
            $this->item(['location_code' => 'ZM', 'room_id' => $foreignRoom->id]),
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.room_id', 'items.1.room_id'])
            ->assertJsonMissingValidationErrors(['items.2.room_id']);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_written_off_rule_applies_per_row(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson(self::URL, ['items' => [
            $this->item(['is_written_off' => true, 'written_off_on' => '2024-01-02']),
            $this->item(['is_written_off' => true]),
        ]])->assertStatus(422)
            ->assertJsonValidationErrors(['items.1.written_off_on'])
            ->assertJsonMissingValidationErrors(['items.0.written_off_on']);

        $this->postJson(self::URL, ['items' => [
            $this->item(['is_written_off' => true, 'written_off_on' => '2024-01-02']),
            $this->item(),
        ]])->assertStatus(201)
            ->assertJsonPath('data.0.is_written_off', true)
            ->assertJsonPath('data.1.is_written_off', false);
    }

    /* ================================================================== history */

    public function test_each_asset_gets_a_create_event_grouped_only_when_more_than_one(): void
    {
        $actor = $this->operator();
        Sanctum::actingAs($actor);

        $single = $this->postJson(self::URL, ['items' => [$this->item()]])->assertStatus(201);
        $singleLog = MutationLog::query()->where('asset_id', $single->json('data.0.id'))->sole();
        $this->assertSame('CREATE', $singleLog->event_type->value);
        $this->assertNull($singleLog->batch_operation_id);
        $this->assertSame($actor->id, $singleLog->performed_by);

        $many = $this->postJson(self::URL, ['items' => [$this->item(), $this->item(['sequence_no' => '090'])]])
            ->assertStatus(201);
        $logs = MutationLog::query()->whereIn('asset_id', array_column($many->json('data'), 'id'))->get();
        $this->assertCount(2, $logs);
        $this->assertNotNull($logs[0]->batch_operation_id);
        $this->assertSame($logs[0]->batch_operation_id, $logs[1]->batch_operation_id);
    }

    /* ================================================================== authorization */

    public function test_unit_admin_creates_in_its_own_location(): void
    {
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson(self::URL, ['items' => [
            $this->item(['location_code' => '02']),
            $this->item(['location_code' => '02', 'sequence_no' => '009']),
        ]])->assertStatus(201)->assertJsonPath('count', 2);
    }

    public function test_unit_admin_is_denied_a_foreign_location_and_nothing_is_written(): void
    {
        $this->scope('03');
        Sanctum::actingAs($this->unitAdmin('02'));

        $this->postJson(self::URL, ['items' => [$this->item(['location_code' => '03'])]])->assertStatus(403);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_unit_admin_mixed_location_request_is_denied_as_a_whole(): void
    {
        $this->scope('03');
        Sanctum::actingAs($this->unitAdmin('02'));
        $logsBefore = MutationLog::query()->count();

        $this->postJson(self::URL, ['items' => [
            $this->item(['location_code' => '02']),
            $this->item(['location_code' => '03']),
            $this->item(['location_code' => '02']),
        ]])->assertStatus(403);

        $this->assertDatabaseCount('assets', 0);
        $this->assertSame($logsBefore, MutationLog::query()->count());
    }

    public function test_unit_admin_learns_nothing_about_numbers_in_a_foreign_location(): void
    {
        $this->existingAsset('005', 2025, [], '03');
        Sanctum::actingAs($this->unitAdmin('02'));

        // 403 (scope), not 422 "already used" — the existing number is never confirmed
        $this->postJson(self::URL, ['items' => [$this->item(['location_code' => '03', 'sequence_no' => '005'])]])
            ->assertStatus(403);
    }

    public function test_viewer_is_denied_and_guest_is_unauthenticated(): void
    {
        $this->postJson(self::URL, ['items' => [$this->item()]])->assertStatus(401);

        Sanctum::actingAs($this->viewer());
        $this->postJson(self::URL, ['items' => [$this->item()]])->assertStatus(403);

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_global_roles_can_create_in_any_location(): void
    {
        $this->scope('ZM');

        foreach ([UserRole::Operator, UserRole::Admin, UserRole::SuperAdmin] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));

            $this->postJson(self::URL, ['items' => [
                $this->item(),
                $this->item(['location_code' => 'ZM']),
            ]])->assertStatus(201)->assertJsonPath('count', 2);
        }

        $this->assertDatabaseCount('assets', 6);
    }

    /* ================================================================== existing endpoints */

    public function test_existing_create_endpoints_still_reject_a_client_sequence(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets', $this->validCreatePayload(['sequence_no' => '777']))
            ->assertStatus(422)->assertJsonValidationErrors('sequence_no');
        $this->postJson('/api/assets/batch', $this->validCreatePayload(['sequence_no' => '777', 'count' => 2]))
            ->assertStatus(422)->assertJsonValidationErrors('sequence_no');

        $this->postJson('/api/assets', $this->validCreatePayload())
            ->assertStatus(201)->assertJsonPath('data.sequence_no', '001');
        $this->postJson('/api/assets/batch', $this->validCreatePayload(['count' => 2]))
            ->assertStatus(201)->assertJsonPath('count', 2)->assertJsonPath('data.1.sequence_no', '003');
    }

    /* ================================================================== concurrency */

    public function test_numbers_from_separate_requests_interleave_safely(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('007', 2025);

        $this->postJson(self::URL, ['items' => [$this->item()]])->assertJsonPath('data.0.sequence_no', '008');

        // the number another request just generated is now taken
        $this->postJson(self::URL, ['items' => [$this->item(['sequence_no' => '008'])]])
            ->assertStatus(422)->assertJsonValidationErrors(['items.0.sequence_no']);

        // a manual number ahead of the counter moves the counter past it
        $this->postJson(self::URL, ['items' => [$this->item(['sequence_no' => '020'])]])->assertStatus(201);
        $this->postJson(self::URL, ['items' => [$this->item()]])->assertJsonPath('data.0.sequence_no', '021');

        $this->assertSame(4, Asset::query()->count());
    }

    public function test_a_duplicate_key_race_retries_and_never_creates_a_duplicate(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('001', 2025);

        // simulate a concurrent commit: the first number handed out is already taken
        $generator = new class extends AssetNumberGenerator
        {
            public int $calls = 0;

            public function next(string $locationCode, string $categoryCode, string $subcategoryCode): string
            {
                return ++$this->calls === 1 ? '001' : parent::next($locationCode, $categoryCode, $subcategoryCode);
            }
        };
        $this->app->instance(AssetNumberGenerator::class, $generator);

        $response = $this->postJson(self::URL, ['items' => [$this->item(['sequence_no' => '010']), $this->item()]])
            ->assertStatus(201);

        $this->assertSame(['010', '011'], array_column($response->json('data'), 'sequence_no'));
        $this->assertSame(3, Asset::query()->count());
        $this->assertSame(3, Asset::query()->distinct()->count('asset_code'));
        $this->assertSame(2, MutationLog::query()->count());
    }
}
