<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\Concerns\FiltersAssets;
use App\Http\Requests\Api\AssetIndexRequest;
use App\Models\Asset;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Location;
use App\Models\MutationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAssetFixtures;
use Tests\TestCase;

/**
 * Tahap 6.3 — `GET /api/reports/inventory`. This endpoint reuses the EXACT
 * filter vocabulary and query-building trait `GET /api/assets` /
 * `GET /api/assets/export` use ({@see AssetIndexRequest}, {@see FiltersAssets}),
 * so these tests focus on: authorization (deliberately `can:operator`, NOT
 * `can:viewer` like the dashboard), aggregation correctness, filter reuse, and
 * read-only / DB-safety guarantees.
 */
class ReportApiTest extends TestCase
{
    use InteractsWithAssetFixtures;
    use RefreshDatabase;

    /* ------------------------------------------------------------------ authorization */

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/reports/inventory')->assertStatus(401);
    }

    public function test_viewer_is_forbidden(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/reports/inventory')->assertStatus(403);
    }

    public function test_inactive_operator_is_forbidden(): void
    {
        Sanctum::actingAs($this->operator(active: false));

        $this->getJson('/api/reports/inventory')->assertStatus(403);
    }

    public function test_operator_can_access(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/reports/inventory')->assertOk();
    }

    public function test_admin_can_access(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/reports/inventory')->assertOk();
    }

    /* ------------------------------------------------------------------ response shape */

    public function test_response_contains_only_aggregated_sections_no_raw_asset_rows(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $response = $this->getJson('/api/reports/inventory')->assertOk();

        $response->assertJsonStructure([
            'data' => ['summary', 'by_location', 'by_category', 'by_room', 'by_condition', 'by_year'],
        ]);

        // No asset identity fields anywhere in the payload — aggregation only.
        $this->assertStringNotContainsString('asset_code', $response->getContent());
        $this->assertStringNotContainsString('sequence_no', $response->getContent());
    }

    /* ------------------------------------------------------------------ summary */

    public function test_summary_splits_active_and_written_off_correctly(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, ['is_written_off' => false], '01', '02', '001');
        $this->existingAsset('002', 2020, ['is_written_off' => false], '01', '02', '001');
        $this->existingAsset('003', 2020, ['is_written_off' => true], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $summary = $this->getJson('/api/reports/inventory')->assertOk()->json('data.summary');

        $this->assertSame(3, $summary['total_assets']);
        $this->assertSame(2, $summary['active_assets']);
        $this->assertSame(1, $summary['written_off_assets']);
    }

    public function test_summary_excludes_soft_deleted_assets(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $trashed = $this->existingAsset('002', 2020, [], '01', '02', '001');
        $trashed->delete();
        Sanctum::actingAs($this->operator());

        $summary = $this->getJson('/api/reports/inventory')->assertOk()->json('data.summary');

        $this->assertSame(1, $summary['total_assets']);
    }

    /* ------------------------------------------------------------------ by_location */

    public function test_by_location_shows_every_active_location_including_zero_count(): void
    {
        $this->scope('01', '02', '001');
        Location::query()->firstOrCreate(['code' => '02'], ['name' => 'SD', 'is_active' => true]);
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $byLocation = collect($this->getJson('/api/reports/inventory')->assertOk()->json('data.by_location'))
            ->keyBy('code');

        $this->assertSame(1, $byLocation['01']['asset_count']);
        $this->assertSame(0, $byLocation['02']['asset_count']);
    }

    public function test_by_location_excludes_inactive_locations(): void
    {
        $this->scope('01', '02', '001');
        Location::query()->firstOrCreate(['code' => '09'], ['name' => 'Nonaktif', 'is_active' => false]);
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $codes = collect($this->getJson('/api/reports/inventory')->assertOk()->json('data.by_location'))
            ->pluck('code');

        $this->assertNotContains('09', $codes);
    }

    /* ------------------------------------------------------------------ by_category */

    public function test_by_category_aggregation_is_correct(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('002', 2020, [], '01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '03', '001');
        Sanctum::actingAs($this->operator());

        $byCategory = collect($this->getJson('/api/reports/inventory')->assertOk()->json('data.by_category'))
            ->keyBy('code');

        $this->assertSame(2, $byCategory['02']['asset_count']);
        $this->assertSame(1, $byCategory['03']['asset_count']);
    }

    /* ------------------------------------------------------------------ by_room */

    public function test_by_room_only_lists_rooms_with_at_least_one_matching_asset(): void
    {
        $roomA = $this->room('01', ['name' => 'Ruangan A']);
        $roomB = $this->room('01', ['name' => 'Ruangan B']);
        $this->existingAsset('001', 2020, ['room_id' => $roomA->id], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $byRoom = collect($this->getJson('/api/reports/inventory')->assertOk()->json('data.by_room'));

        $this->assertCount(1, $byRoom);
        $this->assertSame('Ruangan A', $byRoom->first()['name']);
        $this->assertNotContains($roomB->id, $byRoom->pluck('id'));
    }

    /* ------------------------------------------------------------------ by_condition */

    public function test_by_condition_aggregation_including_unknown_null(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, ['condition' => 'baik'], '01', '02', '001');
        $this->existingAsset('002', 2020, ['condition' => 'baik'], '01', '02', '001');
        $this->existingAsset('003', 2020, ['condition' => 'rusak_berat'], '01', '02', '001');
        $this->existingAsset('004', 2020, ['condition' => null], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $byCondition = $this->getJson('/api/reports/inventory')->assertOk()->json('data.by_condition');

        $this->assertSame(2, $byCondition['baik']);
        $this->assertSame(0, $byCondition['kurang_baik']);
        $this->assertSame(1, $byCondition['rusak_berat']);
        $this->assertSame(1, $byCondition['unknown']);
    }

    /* ------------------------------------------------------------------ by_year */

    public function test_by_year_aggregation_is_correct(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2019, [], '01', '02', '001');
        $this->existingAsset('002', 2020, [], '01', '02', '001');
        $this->existingAsset('003', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $byYear = collect($this->getJson('/api/reports/inventory')->assertOk()->json('data.by_year'))
            ->keyBy('year');

        $this->assertSame(1, $byYear[2019]['asset_count']);
        $this->assertSame(2, $byYear[2020]['asset_count']);
    }

    /* ------------------------------------------------------------------ filters */

    public function test_filters_narrow_the_report_exactly_like_the_inventory_endpoint(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '03', '001');
        Sanctum::actingAs($this->operator());

        $data = $this->getJson('/api/reports/inventory?category_code[]=02')->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['total_assets']);
        $byCategory = collect($data['by_category'])->keyBy('code');
        $this->assertSame(1, $byCategory['02']['asset_count']);
        $this->assertSame(0, $byCategory['03']['asset_count']);
    }

    public function test_condition_filter_narrows_the_report(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, ['condition' => 'baik'], '01', '02', '001');
        $this->existingAsset('002', 2020, ['condition' => 'rusak_berat'], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $data = $this->getJson('/api/reports/inventory?condition[]=baik')->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['total_assets']);
        $this->assertSame(1, $data['by_condition']['baik']);
        $this->assertSame(0, $data['by_condition']['rusak_berat']);
    }

    public function test_room_filter_must_be_in_selected_location_exactly_like_inventory(): void
    {
        $roomInOtherLocation = $this->room('02');
        Sanctum::actingAs($this->operator());

        $this->getJson("/api/reports/inventory?location_code[]=01&room_id[]={$roomInOtherLocation->id}")
            ->assertStatus(422);
    }

    public function test_invalid_filter_is_rejected_exactly_like_the_inventory_endpoint(): void
    {
        Sanctum::actingAs($this->operator());

        $this->getJson('/api/reports/inventory?category_code[]=99')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_code');
    }

    /* ------------------------------------------------------------------ read-only / DB safety */

    public function test_report_never_mutates_the_database(): void
    {
        $this->scope('01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        Sanctum::actingAs($this->operator());

        $before = [
            'assets' => Asset::count(),
            'import_batches' => ImportBatch::count(),
            'import_rows' => ImportRow::count(),
            'mutation_logs' => MutationLog::count(),
        ];

        $this->getJson('/api/reports/inventory')->assertOk();
        $this->getJson('/api/reports/inventory?category_code[]=02')->assertOk();

        $after = [
            'assets' => Asset::count(),
            'import_batches' => ImportBatch::count(),
            'import_rows' => ImportRow::count(),
            'mutation_logs' => MutationLog::count(),
        ];

        $this->assertSame($before, $after);
    }

    public function test_breakdown_totals_sum_back_to_the_summary_total(): void
    {
        $this->scope('01', '02', '001');
        $this->scope('01', '03', '001');
        $this->existingAsset('001', 2020, [], '01', '02', '001');
        $this->existingAsset('002', 2020, ['is_written_off' => true], '01', '02', '001');
        $this->existingAsset('001', 2020, [], '01', '03', '001');
        Sanctum::actingAs($this->operator());

        $data = $this->getJson('/api/reports/inventory')->assertOk()->json('data');

        $this->assertSame(3, $data['summary']['total_assets']);
        $this->assertSame(3, collect($data['by_category'])->sum('asset_count'));
        $this->assertSame(3, collect($data['by_location'])->sum('asset_count'));
        $this->assertSame(3, array_sum($data['by_condition']));
    }
}
