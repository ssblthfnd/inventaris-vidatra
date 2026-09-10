<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\ImportRow;
use App\Models\MutationLog;
use App\Services\Asset\AssetNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 5.8.4 — `POST /api/assets/batch`.
 *
 * Creates `count` SEPARATE asset rows, each `quantity = 1`, each with its own
 * server-generated `sequence_no` / `asset_code`. One atomic transaction.
 */
class AssetBatchCreateTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge($this->validCreatePayload(), ['count' => 3], $overrides);
    }

    /* ------------------------------------------------------------- authorization */

    public function test_operator_can_batch_create(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['count' => 2]))
            ->assertStatus(201)
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_batch_create(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/assets/batch', $this->payload(['count' => 2]))->assertStatus(201);
    }

    public function test_viewer_gets_403(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->postJson('/api/assets/batch', $this->payload())->assertStatus(403);
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_inactive_operator_gets_403(): void
    {
        Sanctum::actingAs($this->operator(active: false));

        $this->postJson('/api/assets/batch', $this->payload())->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->postJson('/api/assets/batch', $this->payload())->assertStatus(401);
    }

    /* ------------------------------------------------------------- count validation */

    public function test_count_of_one_creates_one_asset(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['count' => 1]))
            ->assertStatus(201)
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('assets', 1);
    }

    public function test_count_of_twenty_creates_twenty_assets(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['count' => 20]))
            ->assertStatus(201)
            ->assertJsonPath('count', 20)
            ->assertJsonCount(20, 'data');

        $this->assertDatabaseCount('assets', 20);
    }

    public function test_count_of_one_thousand_is_accepted(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['count' => 1000]))
            ->assertStatus(201)
            ->assertJsonPath('count', 1000);

        $this->assertDatabaseCount('assets', 1000);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidCounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'decimal' => [1.5],
            'over max' => [1001],
            'string' => ['abc'],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidCounts')]
    public function test_invalid_count_is_rejected(mixed $count): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['count' => $count]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('count');

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_missing_count_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $data = $this->validCreatePayload(); // no `count`

        $this->postJson('/api/assets/batch', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('count');
    }

    /* ------------------------------------------------------------- numbering */

    public function test_batch_produces_consecutive_sequences_in_scope(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('006', loc: 'ZL', cat: 'ZC', sub: '001');

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 5, 'asset_year' => 2026]))
            ->assertStatus(201);

        $sequences = collect($response->json('data'))->pluck('sequence_no')->all();
        $this->assertSame(['007', '008', '009', '010', '011'], $sequences);

        $codes = collect($response->json('data'))->pluck('asset_code')->all();
        $this->assertSame([
            'ZL.ZC.001.007.2026',
            'ZL.ZC.001.008.2026',
            'ZL.ZC.001.009.2026',
            'ZL.ZC.001.010.2026',
            'ZL.ZC.001.011.2026',
        ], $codes);
    }

    public function test_year_is_not_part_of_the_sequence_scope(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('001', year: 2024);
        $this->existingAsset('002', year: 2024);

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 3, 'asset_year' => 2026]))
            ->assertStatus(201);

        $this->assertSame(['003', '004', '005'], collect($response->json('data'))->pluck('sequence_no')->all());
    }

    public function test_letter_suffix_family_is_respected(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('005A');
        $this->existingAsset('005B');

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 2]))->assertStatus(201);

        $this->assertSame(['006', '007'], collect($response->json('data'))->pluck('sequence_no')->all());
    }

    public function test_crossing_999_boundary_within_a_batch(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('998');

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 3]))->assertStatus(201);

        $this->assertSame(['999', '1000', '1001'], collect($response->json('data'))->pluck('sequence_no')->all());
    }

    public function test_different_scopes_get_independent_sequences(): void
    {
        Sanctum::actingAs($this->operator());
        $this->existingAsset('040', loc: 'ZL', cat: 'ZC', sub: '001');
        $this->scope('ZM', 'ZC', '001');

        $this->postJson('/api/assets/batch', $this->payload(['count' => 2, 'location_code' => 'ZM']))
            ->assertStatus(201)
            ->assertJsonPath('data.0.sequence_no', '001')
            ->assertJsonPath('data.1.sequence_no', '002');

        // original scope untouched
        $this->postJson('/api/assets/batch', $this->payload(['count' => 1]))
            ->assertJsonPath('data.0.sequence_no', '041');
    }

    public function test_every_created_asset_has_quantity_one(): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 5]))->assertStatus(201);

        foreach ($response->json('data') as $row) {
            $this->assertSame(1, $row['quantity']);
        }
        $this->assertSame(0, Asset::query()->where('quantity', '!=', 1)->count());
    }

    public function test_two_sequential_batches_on_the_same_scope_do_not_overlap(): void
    {
        Sanctum::actingAs($this->operator());

        $a = $this->postJson('/api/assets/batch', $this->payload(['count' => 4]))->assertStatus(201);
        $b = $this->postJson('/api/assets/batch', $this->payload(['count' => 4]))->assertStatus(201);

        $all = collect($a->json('data'))->merge($b->json('data'))->pluck('sequence_no');
        $this->assertSame($all->unique()->count(), $all->count(), 'no sequence collisions across batches');
        $this->assertSame(['001', '002', '003', '004', '005', '006', '007', '008'], $all->sort()->values()->all());
    }

    /* ------------------------------------------------------------- data integrity */

    public function test_batch_creates_exactly_n_rows_with_unique_codes(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['count' => 30]))->assertStatus(201);

        $this->assertDatabaseCount('assets', 30);
        $this->assertSame(30, Asset::query()->distinct()->count('asset_code'));
    }

    public function test_batch_does_not_touch_import_rows_or_mutation_logs(): void
    {
        Sanctum::actingAs($this->operator());
        $importRows = ImportRow::query()->count();

        $this->postJson('/api/assets/batch', $this->payload(['count' => 10]))->assertStatus(201);

        $this->assertSame($importRows, ImportRow::query()->count());
        $this->assertSame(0, MutationLog::query()->count());
        $this->assertSame(0, Asset::query()->whereNotNull('import_row_id')->count());
    }

    public function test_batch_does_not_modify_pre_existing_assets(): void
    {
        Sanctum::actingAs($this->operator());
        $existing = $this->existingAsset('001', overrides: ['brand_model' => 'ORIGINAL']);

        $this->postJson('/api/assets/batch', $this->payload(['count' => 5]))->assertStatus(201);

        $existing->refresh();
        $this->assertSame('ORIGINAL', $existing->brand_model);
        $this->assertSame('001', $existing->sequence_no);
    }

    /* ------------------------------------------------------------- atomicity */

    public function test_a_mid_batch_failure_rolls_the_whole_batch_back(): void
    {
        Sanctum::actingAs($this->operator());

        // a generator that succeeds twice, then blows up on the 3rd allocation
        $this->app->bind(AssetNumberGenerator::class, function () {
            return new class extends AssetNumberGenerator
            {
                private int $calls = 0;

                public function next(string $locationCode, string $categoryCode, string $subcategoryCode): string
                {
                    if (++$this->calls >= 3) {
                        throw new RuntimeException('boom on asset #3');
                    }

                    return parent::next($locationCode, $categoryCode, $subcategoryCode);
                }
            };
        });

        $this->postJson('/api/assets/batch', $this->payload(['count' => 10]))->assertStatus(500);

        $this->assertDatabaseCount('assets', 0);
    }

    /* ------------------------------------------------------------- field validation */

    public function test_invalid_category_subcategory_combination_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $this->scope('ZL', 'ZC', '001');
        $this->scope('ZL', 'ZD', '777');

        $this->postJson('/api/assets/batch', $this->payload([
            'category_code' => 'ZD',
            'subcategory_code' => '001',
        ]))->assertStatus(422)->assertJsonValidationErrors('subcategory_code');

        $this->assertDatabaseCount('assets', 0);
    }

    public function test_room_from_another_location_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());
        $this->scope('ZL', 'ZC', '001');
        $foreignRoom = $this->room('ZM');

        $this->postJson('/api/assets/batch', $this->payload(['room_id' => $foreignRoom->id]))
            ->assertStatus(422)->assertJsonValidationErrors('room_id');
    }

    public function test_client_supplied_sequence_or_asset_code_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['sequence_no' => '777']))
            ->assertStatus(422)->assertJsonValidationErrors('sequence_no');

        $this->postJson('/api/assets/batch', $this->payload(['asset_code' => 'X.Y.Z.1.2000']))
            ->assertStatus(422)->assertJsonValidationErrors('asset_code');
    }

    public function test_written_off_fields_are_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['is_written_off' => true]))
            ->assertStatus(422)->assertJsonValidationErrors('is_written_off');

        $this->postJson('/api/assets/batch', $this->payload(['written_off_on' => '2024-01-01']))
            ->assertStatus(422)->assertJsonValidationErrors('written_off_on');
    }

    public function test_absurd_asset_year_is_rejected(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets/batch', $this->payload(['asset_year' => 1850]))
            ->assertStatus(422)->assertJsonValidationErrors('asset_year');
    }

    public function test_null_condition_is_allowed_for_the_whole_batch(): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 3, 'condition' => null]))
            ->assertStatus(201);

        foreach ($response->json('data') as $row) {
            $this->assertNull($row['condition']);
        }
    }

    public function test_same_serial_number_is_applied_to_every_asset_in_the_batch(): void
    {
        // there is no uniqueness constraint on serial_no (schema) — the batch shares it
        Sanctum::actingAs($this->operator());

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 3, 'serial_no' => 'SN-SHARED']))
            ->assertStatus(201);

        foreach ($response->json('data') as $row) {
            $this->assertSame('SN-SHARED', $row['serial_no']);
        }
    }

    /* ------------------------------------------------------------- response shape */

    public function test_response_carries_message_count_and_each_generated_code(): void
    {
        Sanctum::actingAs($this->operator());

        $response = $this->postJson('/api/assets/batch', $this->payload(['count' => 2, 'asset_year' => 2025]))
            ->assertStatus(201)
            ->assertJsonPath('message', '2 aset berhasil dibuat.')
            ->assertJsonPath('count', 2)
            ->assertJsonStructure(['message', 'count', 'data' => [['id', 'asset_code', 'sequence_no', 'quantity']]]);

        foreach ($response->json('data') as $row) {
            $this->assertMatchesRegularExpression('/^ZL\.ZC\.001\.\d{3}\.2025$/', $row['asset_code']);
        }
    }

    /* ------------------------------------------------------------- regression */

    public function test_single_create_endpoint_still_works(): void
    {
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets', $this->validCreatePayload(['asset_year' => 2025]))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_no', '001')
            ->assertJsonMissingPath('count');
    }

    public function test_single_create_still_rejects_a_count_field_as_unexpected_noop(): void
    {
        // `count` is simply ignored by StoreAssetRequest (not in its rules)
        Sanctum::actingAs($this->operator());

        $this->postJson('/api/assets', $this->validCreatePayload(['count' => 99, 'asset_year' => 2025]))
            ->assertStatus(201);

        $this->assertDatabaseCount('assets', 1);
    }
}
